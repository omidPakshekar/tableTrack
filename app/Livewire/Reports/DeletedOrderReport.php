<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use App\Models\User;
use App\Models\DeletedOrder;
use App\Scopes\BranchScope;
use App\Scopes\AvailableMenuItemScope;
use App\Models\MenuItem;
use Livewire\Component;
use App\Exports\DeletedOrderReportExport;
use Maatwebsite\Excel\Facades\Excel;

class DeletedOrderReport extends Component
{
    public $dateRangeType = 'currentWeek';
    public $startDate;
    public $endDate;
    public $startTime = '00:00';
    public $endTime = '23:59';
    public $currencyId;
    public $selectedDeletedBy = '';
    public $users = [];
    public $showOrderDetailsModal = false;
    public $selectedDeletedOrderId = null;

    public function mount()
    {
        abort_unless(in_array('Report', restaurant_modules()), 403);
        abort_unless(user_can('Show Reports'), 403);

        $this->currencyId = restaurant()->currency_id;
        $this->dateRangeType = request()->cookie('deleted_order_report_date_range_type', 'currentWeek');
        $this->setDateRange();

        $this->users = User::withoutGlobalScope(BranchScope::class)
            ->where(function ($q) {
                return $q->where('branch_id', branch()->id)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', restaurant()->id)
            ->orderBy('name')
            ->get();
    }

    public function setDateRange()
    {
        $tz = timezone();

        $ranges = [
            'today' => [Carbon::now($tz)->startOfDay(), Carbon::now($tz)->endOfDay()],
            'lastWeek' => [Carbon::now($tz)->subWeek()->startOfWeek(), Carbon::now($tz)->subWeek()->endOfWeek()],
            'last7Days' => [Carbon::now($tz)->subDays(7), Carbon::now($tz)->endOfDay()],
            'currentMonth' => [Carbon::now($tz)->startOfMonth(), Carbon::now($tz)->endOfDay()],
            'lastMonth' => [Carbon::now($tz)->subMonth()->startOfMonth(), Carbon::now($tz)->subMonth()->endOfMonth()],
            'currentYear' => [Carbon::now($tz)->startOfYear(), Carbon::now($tz)->endOfDay()],
            'lastYear' => [Carbon::now($tz)->subYear()->startOfYear(), Carbon::now($tz)->subYear()->endOfYear()],
            'currentWeek' => [Carbon::now($tz)->startOfWeek(), Carbon::now($tz)->endOfWeek()],
        ];

        [$start, $end] = $ranges[$this->dateRangeType] ?? $ranges['currentWeek'];
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $this->startDate = $start->displayDate($dateFormat);
        $this->endDate = $end->displayDate($dateFormat);
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('deleted_order_report_date_range_type', $value, 60 * 24 * 30));
        $this->setDateRange();
    }

    public function updatedStartTime($value)
    {
        $this->startTime = $this->normalizeTime($value);
    }

    public function updatedEndTime($value)
    {
        $this->endTime = $this->normalizeTime($value);
    }

    private function normalizeTime($time): string
    {
        if (empty($time)) {
            return '00:00';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)?$/i', trim($time), $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $ampm = $matches[3] ?? null;

            if ($ampm !== null && $ampm !== '') {
                $ampmUpper = strtoupper($ampm);
                if ($ampmUpper === 'PM' && $hours !== 12) {
                    $hours += 12;
                }
                if ($ampmUpper === 'AM' && $hours === 12) {
                    $hours = 0;
                }
            }

            $hours = min(23, max(0, $hours));
            $minutes = min(59, max(0, $minutes));

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        return '00:00';
    }

    private function prepareDateTimeData(): array
    {
        $timezone = timezone();
        $offset = Carbon::now($timezone)->format('P');
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $normalizedStartTime = $this->normalizeTime($this->startTime);
        $normalizedEndTime = $this->normalizeTime($this->endTime);

        $startDateTime = parseRestaurantDateTime($this->startDate . ' ' . $normalizedStartTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $endDateTime = parseRestaurantDateTime($this->endDate . ' ' . $normalizedEndTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $timezone)->setTimezone('UTC')->format('H:i');

        return compact('timezone', 'offset', 'startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    public function openOrderDetailsModal($orderId)
    {
        $this->selectedDeletedOrderId = $orderId;
        $this->showOrderDetailsModal = true;
    }

    public function closeOrderDetailsModal()
    {
        $this->showOrderDetailsModal = false;
        $this->selectedDeletedOrderId = null;
    }

    private function normalizeDeletedOrderItems(?DeletedOrder $deletedOrder): array
    {
        if (!$deletedOrder || empty($deletedOrder->items)) {
            return [];
        }

        return collect($deletedOrder->items)
            ->map(function ($item) {
                $modifiers = data_get($item, 'modifiers', []);
                $name = data_get($item, 'name') ?? data_get($item, 'item_name');
                $menuItemId = data_get($item, 'menu_item_id');

                if ((!$name || in_array($name, [__('modules.report.notAvailable'), 'N/A'], true)) && $menuItemId) {
                    $name = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
                        ->find($menuItemId)
                        ?->item_name;
                }

                return [
                    'name' => $name,
                    'variation' => data_get($item, 'variation'),
                    'quantity' => data_get($item, 'quantity', 0),
                    'price' => data_get($item, 'price', 0),
                    'amount' => data_get($item, 'amount', 0),
                    'note' => data_get($item, 'note'),
                    'modifiers' => is_array($modifiers) ? array_values(array_filter($modifiers)) : [],
                ];
            })
            ->values()
            ->all();
    }

    public function exportReport()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
            return;
        }

        $dateTimeData = $this->prepareDateTimeData();

        return Excel::download(
            new DeletedOrderReportExport(
                $dateTimeData['startDateTime'],
                $dateTimeData['endDateTime'],
                $dateTimeData['timezone'],
                $dateTimeData['offset'],
                $dateTimeData['startTime'],
                $dateTimeData['endTime'],
                $this->selectedDeletedBy,
                $this->currencyId
            ),
            'deleted-order-report-' . now()->format('Y-m-d_His') . '.xlsx'
        );
    }

    public function render()
    {
        $dateTimeData = $this->prepareDateTimeData();

        $query = DeletedOrder::with('deletedBy')
            ->whereBetween('order_deleted_at', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(order_deleted_at) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                } else {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(order_deleted_at) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(order_deleted_at) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });

        if ($this->selectedDeletedBy) {
            $query->where('deleted_by', $this->selectedDeletedBy);
        }

        $deletedOrders = $query->orderBy('order_deleted_at', 'desc')->get();
        $totalDeletedOrders = $deletedOrders->count();
        $totalDeletedAmount = $deletedOrders->sum('total');

        $selectedDeletedOrder = null;
        $modalOrderItems = [];

        if ($this->showOrderDetailsModal && $this->selectedDeletedOrderId) {
            $selectedDeletedOrder = DeletedOrder::with('deletedBy')->find($this->selectedDeletedOrderId);
            $modalOrderItems = $this->normalizeDeletedOrderItems($selectedDeletedOrder);
        }

        return view('livewire.reports.deleted-order-report', [
            'deletedOrders' => $deletedOrders,
            'totalDeletedOrders' => $totalDeletedOrders,
            'totalDeletedAmount' => $totalDeletedAmount,
            'currencyId' => $this->currencyId,
            'users' => $this->users,
            'selectedDeletedBy' => $this->selectedDeletedBy,
            'selectedDeletedOrder' => $selectedDeletedOrder,
            'modalOrderItems' => $modalOrderItems,
        ]);
    }
}
