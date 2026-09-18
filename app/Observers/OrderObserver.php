<?php

namespace App\Observers;

use App\Events\NewOrderCreated;
use App\Events\SendNewOrderReceived;
use App\Events\OrderCancelled;
use App\Events\OrderSuccessEvent;
use App\Events\OrderUpdated;
use App\Events\TodayOrdersUpdated;
use App\Enums\OrderStatus;
use App\Models\Kot;
use App\Models\Order;
use App\Services\DeletedOrderLogService;
use App\Services\OrderCashCollectionService;
use App\Services\Tables\TablesIndexCache;

class OrderObserver
{
    /** @var array<string, int> */
    private static array $todayKotCountByBranchAndWindow = [];

    public function __construct(
        private readonly OrderCashCollectionService $orderCashCollectionService,
        private readonly DeletedOrderLogService $deletedOrderLogService,
    ) {}

    /**
     * KOT rows change the "today" counter; invalidate so the next count query stays correct.
     */
    public static function forgetTodayKotCountMemoForBranch(?int $branchId): void
    {
        if ($branchId === null) {
            self::$todayKotCountByBranchAndWindow = [];

            return;
        }

        $prefix = $branchId.'|';
        foreach (array_keys(self::$todayKotCountByBranchAndWindow) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset(self::$todayKotCountByBranchAndWindow[$key]);
            }
        }
    }

    public function creating(Order $order)
    {
        if (branch() && $order->branch_id == null) {
            $order->branch_id = branch()->id;
        }
    }

    /**
     * When payment marks the order as paid, optionally set progress order_status to completed
     * so dine-in tables and downstream UI treat the order as finished (restaurant setting).
     */
    public function updating(Order $order): void
    {
        if (! $order->isDirty('status') || (string) $order->status !== 'paid') {
            return;
        }
        if ((string) $order->getOriginal('status') === 'paid') {
            return;
        }
        if ($order->order_status === OrderStatus::CANCELLED) {
            return;
        }

        $order->loadMissing('branch.restaurant');
        $restaurant = $order->branch?->restaurant;
        if (! $restaurant || ! (bool) data_get($restaurant, 'auto_mark_order_completed_on_paid', true)) {
            return;
        }

        $order->order_status = OrderStatus::COMPLETED;
    }

    public function created(Order $order)
    {
        $this->orderCashCollectionService->syncForOrder($order);

        // Increment branch's count_orders (only for non-draft, non-canceled orders)
        if ($order->branch) {
            $order->branch->increment('count_orders');

            // Clear branch order stats cache
            cache()->forget('branch_'.$order->branch->id.'_order_stats');
        }

        $this->forgetTablesPageCacheIfTableOrder($order);

        $todayKotCount = $this->countKotsInBusinessDayForOrder($order);

        event(new OrderUpdated($order, 'created'));
        event(new TodayOrdersUpdated($todayKotCount));

        // Dispatch event for new order notification
        if ($order->status !== 'draft') {
            event(new NewOrderCreated($order));
            // Also trigger push/email notification pipeline (listener is responsible for de-duplication).
            SendNewOrderReceived::dispatch($order);

            session(['new_order_notification_pending' => true]);
        }
    }

    public function updated(Order $order)
    {
        if ($order->wasChanged(['order_type', 'delivery_executive_id', 'amount_paid', 'total', 'branch_id'])) {
            $this->orderCashCollectionService->syncForOrder($order);
        }

        if ($order->wasChanged('status') && $order->status == 'canceled') {
            OrderCancelled::dispatch($order);
        }

        if ($order->wasChanged('order_status')) {
            $this->cascadeOrderStatusToKots($order);

            if ($order->order_status === OrderStatus::COMPLETED) {
                $order->releaseTableSeatHoldIfAssigned();
            }
        }

        $todayKotCount = $this->countKotsInBusinessDayForOrder($order);

        $order->unsetRelations();

        // Broadcast events
        event(new OrderUpdated($order, 'updated'));
        event(new TodayOrdersUpdated($todayKotCount));

        if ($order->wasChanged('status') && (string) $order->status === 'paid') {
            event(new OrderSuccessEvent($order));
        }

        $this->forgetTablesPageCacheIfTableOrder($order);
    }

    public function deleting(Order $order): void
    {
        $this->deletedOrderLogService->log($order);
    }

    public function deleted(Order $order): void
    {
        $this->forgetTablesPageCacheIfTableOrder($order);
    }

    private function forgetTablesPageCacheIfTableOrder(Order $order): void
    {
        if ($order->table_id && $order->branch_id) {
            TablesIndexCache::forgetForBranch((int) $order->branch_id);
        }
    }

    /**
     * Cascade order status to KOTs and items (manual override from order level).
     * This provides a one-way override when managers manually change order status.
     */
    private function countKotsInBusinessDayForOrder(Order $order): int
    {
        $branch = $order->branch;
        if (! $branch) {
            return 0;
        }

        $boundaries = getBusinessDayBoundaries($branch, now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();
        $memoKey = $branch->id.'|'.$startUTC.'|'.$endUTC;

        if (array_key_exists($memoKey, self::$todayKotCountByBranchAndWindow)) {
            return self::$todayKotCountByBranchAndWindow[$memoKey];
        }

        $count = Kot::query()
            ->join('orders', 'kots.order_id', '=', 'orders.id')
            ->where('kots.branch_id', $branch->id)
            ->where('kots.created_at', '>=', $startUTC)
            ->where('kots.created_at', '<=', $endUTC)
            ->whereNotIn('orders.status', ['canceled', 'draft'])
            ->count();

        return self::$todayKotCountByBranchAndWindow[$memoKey] = $count;
    }

    private function cascadeOrderStatusToKots(Order $order)
    {
        // Status mapping: order_status => [kot_status, item_status]
        $statusMapping = [
            'placed' => ['kot' => 'pending_confirmation', 'item' => 'pending'],
            'confirmed' => ['kot' => 'in_kitchen', 'item' => null],
            'preparing' => ['kot' => 'in_kitchen', 'item' => 'cooking'],
            'food_ready' => ['kot' => 'food_ready', 'item' => 'ready'],
            'ready_for_pickup' => [
                'kot' => $order->order_type === 'pickup' ? 'food_ready' : 'served',
                'item' => $order->order_type === 'pickup' ? 'ready' : null,
            ],
            'out_for_delivery' => ['kot' => 'served', 'item' => null],
            'served' => ['kot' => 'served', 'item' => null],
            'delivered' => ['kot' => 'served', 'item' => null],
            'completed' => ['kot' => 'served', 'item' => null],
            'cancelled' => ['kot' => 'cancelled', 'item' => 'cancelled'],
        ];

        $mapping = $statusMapping[$order->order_status->value] ?? ['kot' => 'pending_confirmation', 'item' => 'pending'];

        if ($mapping['kot']) {
            $order->kot->each(function ($kot) use ($mapping) {
                $kot->updateQuietly(['status' => $mapping['kot']]);

                if ($mapping['item']) {
                    $kot->items()->update(['status' => $mapping['item']]);
                }
            });
        }
    }
}
