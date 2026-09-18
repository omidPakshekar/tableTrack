<?php

namespace App\Services\Tables;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caches the heavy tables-by-area tree for /tables. Volatile fields (locks, orders,
 * reservations) are snapshotted at build time; observers + OrderObserver clear the
 * cache when related data changes. Short TTL limits drift if a mass Table::update bypasses observers.
 */
class TablesIndexCache
{
    public const CACHE_VERSION = 6;

    public const TTL_SECONDS = 120;

    public static function cacheKey(int $branchId): string
    {
        return sprintf('tables.page.v%d.branch.%d', self::CACHE_VERSION, $branchId);
    }

    public static function forgetForBranch(?int $branchId): void
    {
        if (! $branchId) {
            return;
        }

        Cache::forget(self::cacheKey($branchId));
    }

    /**
     * @return array{areas: array<int, array<string, mixed>>}
     */
    public static function get(int $branchId): array
    {
        return Cache::remember(
            self::cacheKey($branchId),
            self::TTL_SECONDS,
            fn () => self::build($branchId)
        );
    }

    /**
     * @return array{areas: array<int, array<string, mixed>>}
     */
    public static function build(int $branchId): array
    {
        Table::cleanupExpiredLocks();

        $areas = Area::query()
            ->where('branch_id', $branchId)
            ->with([
                'tables' => function ($query) {
                    $query->orderBy('table_code')
                        ->with([
                            'tableSession.lockedByUser',
                            'activeReservation',
                        ]);
                },
            ])
            ->orderBy('area_name')
            ->get();

        $tableIds = $areas->flatMap(fn ($area) => $area->tables->pluck('id'))->all();
        $reservationOrdersByTableId = self::loadReservationOrdersByTableId($tableIds);
        $assignmentsByTableId = self::loadActiveAssignmentsByTableId($tableIds);
        $runningOrdersByTableId = self::loadRunningOrdersByTableId($tableIds, $branchId);

        $areasOut = [];
        foreach ($areas as $area) {
            $tablesOut = [];
            foreach ($area->tables as $table) {
                $tablesOut[] = self::serializeTable(
                    $table,
                    $reservationOrdersByTableId[$table->id] ?? null,
                    $assignmentsByTableId[(int) $table->id] ?? null,
                    $runningOrdersByTableId[(int) $table->id] ?? []
                );
            }
            $areasOut[] = [
                'id' => (int) $area->id,
                'area_name' => (string) $area->area_name,
                'tables' => $tablesOut,
            ];
        }

        return ['areas' => $areasOut];
    }

    /**
     * @param  array<int|string>  $tableIds
     * @return array<int, Order|null>
     */
    private static function loadReservationOrdersByTableId(array $tableIds): array
    {
        if ($tableIds === []) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('table_id', $tableIds)
            ->whereHas('reservation')
            ->with('reservation')
            ->orderByDesc('id')
            ->get()
            ->unique('table_id');

        $map = [];
        foreach ($orders as $order) {
            $map[(int) $order->table_id] = $order;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $assignment
     */
    private static function serializeTable(Table $table, ?Order $currentReservationOrder, ?array $assignment = null, array $runningOrders = []): array
    {
        $session = $table->tableSession;
        // Same source as running_orders: latest occupying visit first (query is orderByDesc id).
        $primaryRunning = $runningOrders[0] ?? null;
        $occupiedPaxTotal = (int) collect($runningOrders)->sum(fn (array $order) => (int) ($order['number_of_pax'] ?? 0));
        $seatingCapacity = (int) $table->seating_capacity;
        $seatsLeft = $seatingCapacity > 0 ? max($seatingCapacity - $occupiedPaxTotal, 0) : null;

        $isReservationActive = self::computeIsReservationActive($table, $currentReservationOrder);
        $resolvedAvailableStatus = self::resolveAvailableStatus($table, $runningOrders, $isReservationActive);

        return [
            'id' => (int) $table->id,
            'area_id' => (int) $table->area_id,
            'table_code' => (string) $table->table_code,
            'seating_capacity' => (int) $table->seating_capacity,
            'status' => (string) $table->status,
            // Use computed occupancy status so UI does not depend on stale DB flags.
            'available_status' => $resolvedAvailableStatus,
            'available_status_raw' => (string) $table->available_status,
            'occupied_pax_total' => $occupiedPaxTotal,
            'seats_left' => $seatsLeft,
            'active_order' => $primaryRunning ? [
                'id' => (int) $primaryRunning['id'],
                'status' => (string) $primaryRunning['status'],
                'kot_count' => (int) ($primaryRunning['kot_count'] ?? 0),
                'waiter_id' => isset($primaryRunning['waiter_id']) && $primaryRunning['waiter_id'] !== null
                    ? (int) $primaryRunning['waiter_id']
                    : null,
            ] : null,
            'running_orders' => $runningOrders,
            'running_orders_count' => count($runningOrders),
            'session' => $session && $session->isLocked() ? [
                'locked_by_user_id' => $session->locked_by_user_id !== null ? (int) $session->locked_by_user_id : null,
                'locked_at' => $session->locked_at?->format('H:i'),
                'locked_by_name' => (string) ($session->lockedByUser->name ?? ''),
            ] : null,
            'is_reservation_active' => $isReservationActive,
            'current_reservation_order_status' => $currentReservationOrder?->status,
            'assigned_waiter' => $assignment,
        ];
    }

    /**
     * Resolve the UI availability state from live data, not just persisted table flags.
     *
     * @param  array<int, array<string, mixed>>  $runningOrders
     */
    private static function resolveAvailableStatus(Table $table, array $runningOrders, bool $isReservationActive): string
    {
        if ((string) $table->status === 'inactive') {
            return (string) ($table->available_status ?? 'available');
        }

        if (! empty($runningOrders)) {
            return 'running';
        }

        if ($isReservationActive) {
            return 'reserved';
        }

        return 'available';
    }

    /**
     * @param  array<int|string>  $tableIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadActiveAssignmentsByTableId(array $tableIds): array
    {
        if ($tableIds === []) {
            return [];
        }

        $today = now()->toDateString();

        $assignments = DB::table('assign_waiter_to_tables as awt')
            ->leftJoin('users as primary_waiter', 'primary_waiter.id', '=', 'awt.waiter_id')
            ->leftJoin('users as backup_waiter', 'backup_waiter.id', '=', 'awt.backup_waiter_id')
            ->whereIn('awt.table_id', $tableIds)
            ->where('awt.is_active', true)
            ->where('awt.effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('awt.effective_to')
                    ->orWhere('awt.effective_to', '>=', $today);
            })
            ->orderBy('awt.table_id')
            ->orderByDesc('awt.created_at')
            ->get([
                'awt.table_id',
                'awt.waiter_id',
                'awt.backup_waiter_id',
                'primary_waiter.name as waiter_name',
                'backup_waiter.name as backup_waiter_name',
            ]);

        $byTableId = [];

        foreach ($assignments as $assignment) {
            $tableId = (int) $assignment->table_id;
            if (isset($byTableId[$tableId])) {
                continue;
            }

            $primaryWaiterId = $assignment->waiter_id !== null ? (int) $assignment->waiter_id : null;
            $backupWaiterId = $assignment->backup_waiter_id !== null ? (int) $assignment->backup_waiter_id : null;

            $selectedWaiterId = $primaryWaiterId ?? $backupWaiterId;
            $selectedWaiterName = $assignment->waiter_name ?: $assignment->backup_waiter_name;

            if (! $selectedWaiterId || ! $selectedWaiterName) {
                continue;
            }

            $byTableId[$tableId] = [
                'id' => $selectedWaiterId,
                'name' => (string) $selectedWaiterName,
                'primary_waiter_id' => $primaryWaiterId,
                'backup_waiter_id' => $backupWaiterId,
            ];
        }

        return $byTableId;
    }

    /**
     * Running (not completed) dine-in orders for the current business day only — same window as dashboard "today".
     *
     * @param  array<int|string>  $tableIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function loadRunningOrdersByTableId(array $tableIds, int $branchId): array
    {
        if ($tableIds === []) {
            return [];
        }

        $branch = Branch::query()->find($branchId);
        $boundaries = getBusinessDayBoundaries($branch, now());
        $startUtc = $boundaries['start']->copy()->setTimezone('UTC')->toDateTimeString();
        $endUtc = $boundaries['end']->copy()->setTimezone('UTC')->toDateTimeString();

        $orders = Order::query()
            ->where('branch_id', $branchId)
            ->whereIn('table_id', $tableIds)
            ->occupyingTableSeats()
            ->where(function ($q) use ($startUtc, $endUtc) {
                $q->where(function ($q2) use ($startUtc, $endUtc) {
                    $q2->whereNotNull('orders.date_time')
                        ->where('orders.date_time', '>=', $startUtc)
                        ->where('orders.date_time', '<=', $endUtc);
                })->orWhere(function ($q3) use ($startUtc, $endUtc) {
                    $q3->whereNull('orders.date_time')
                        ->where('orders.created_at', '>=', $startUtc)
                        ->where('orders.created_at', '<=', $endUtc);
                });
            })
            ->withCount('kot')
            ->orderByDesc('id')
            ->get(['id', 'table_id', 'status', 'order_number', 'formatted_order_number', 'token_number', 'waiter_id', 'number_of_pax', 'date_time']);

        $byTable = [];
        foreach ($orders as $order) {
            $tid = (int) $order->table_id;
            if (!isset($byTable[$tid])) {
                $byTable[$tid] = [];
            }
            $byTable[$tid][] = [
                'id' => (int) $order->id,
                'status' => (string) $order->status,
                'order_number' => $order->order_number !== null ? (string) $order->order_number : null,
                'formatted_order_number' => $order->formatted_order_number !== null ? (string) $order->formatted_order_number : null,
                'token_number' => $order->token_number !== null ? (string) $order->token_number : null,
                'waiter_id' => $order->waiter_id !== null ? (int) $order->waiter_id : null,
                'number_of_pax' => (int) ($order->number_of_pax ?? 0),
                'kot_count' => (int) ($order->kot_count ?? 0),
                'date_time' => optional($order->date_time)->toDateTimeString(),
            ];
        }

        return $byTable;
    }

    private static function computeIsReservationActive(Table $table, ?Order $currentReservationOrder): bool
    {
        if ($currentReservationOrder) {
            return $currentReservationOrder->status !== 'paid';
        }

        $activeReservation = $table->activeReservation;
        if (! $activeReservation) {
            return false;
        }

        $startTime = $activeReservation->reservation_date_time;
        $slotTime = $activeReservation->slot_time_difference ?? null;
        if (! $startTime || $slotTime === null) {
            return false;
        }

        $endTime = (clone $startTime)->addMinutes((int) $slotTime);
        $now = now()->timezone(timezone());
        $nowValue = $now->format('His');
        $startValue = $startTime instanceof Carbon ? $startTime->format('His') : Carbon::parse($startTime)->format('His');
        $endValue = $endTime->format('His');

        return $nowValue >= $startValue && $nowValue <= $endValue;
    }
}
