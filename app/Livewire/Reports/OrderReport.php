<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use App\Models\Order;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Exports\OrderReportExport;
use Maatwebsite\Excel\Facades\Excel;

class OrderReport extends Component
{
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $startTime = '00:00';
    public $endTime = '23:59';
    public $searchTerm;
    public $sortBy = 'date_time';
    public $sortDirection = 'desc';
    public $orderTypeFilter = '';

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        $tz = timezone();

        $this->dateRangeType = request()->cookie('order_report_date_range_type', 'currentWeek');
        $this->startDate = Carbon::now($tz)->startOfWeek()->format('m/d/Y');
        $this->endDate = Carbon::now($tz)->endOfWeek()->format('m/d/Y');
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('order_report_date_range_type', $value, 60 * 24 * 30));
    }

    public function sortByToggle($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'desc';
        }
    }

    public function setDateRange()
    {
        $tz = timezone();

        switch ($this->dateRangeType) {
        case 'today':
            $this->startDate = Carbon::now($tz)->startOfDay()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->startOfDay()->format('m/d/Y');
            break;

        case 'lastWeek':
            $this->startDate = Carbon::now($tz)->subWeek()->startOfWeek()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->subWeek()->endOfWeek()->format('m/d/Y');
            break;

        case 'last7Days':
            $this->startDate = Carbon::now($tz)->subDays(7)->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->startOfDay()->format('m/d/Y');
            break;

        case 'currentMonth':
            $this->startDate = Carbon::now($tz)->startOfMonth()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->startOfDay()->format('m/d/Y');
            break;

        case 'lastMonth':
            $this->startDate = Carbon::now($tz)->subMonth()->startOfMonth()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->subMonth()->endOfMonth()->format('m/d/Y');
            break;

        case 'currentYear':
            $this->startDate = Carbon::now($tz)->startOfYear()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->startOfDay()->format('m/d/Y');
            break;

        case 'lastYear':
            $this->startDate = Carbon::now($tz)->subYear()->startOfYear()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->subYear()->endOfYear()->format('m/d/Y');
            break;

        default:
            $this->startDate = Carbon::now($tz)->startOfWeek()->format('m/d/Y');
            $this->endDate = Carbon::now($tz)->endOfWeek()->format('m/d/Y');
            break;
        }
    }

    #[On('setStartDate')]
    public function setStartDate($start)
    {
        $this->startDate = $start;
    }

    #[On('setEndDate')]
    public function setEndDate($end)
    {
        $this->endDate = $end;
    }

    public function exportReport()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
        } else {
            $data = $this->prepareDateTimeData();

            return Excel::download(
                new OrderReportExport(
                    $data['startDateTime'],
                    $data['endDateTime'],
                    $data['startTime'],
                    $data['endTime'],
                    $data['timezone'],
                    $this->searchTerm,
                    $this->orderTypeFilter
                ),
                'order-report-' . now()->toDateTimeString() . '.xlsx'
            );
        }
    }

    private function prepareDateTimeData()
    {
        $timezone = timezone();

        $startDateTime = Carbon::createFromFormat('m/d/Y H:i', "{$this->startDate} {$this->startTime}", $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $endDateTime = Carbon::createFromFormat('m/d/Y H:i', "{$this->endDate} {$this->endTime}", $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $startTime = Carbon::parse($this->startTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::parse($this->endTime, $timezone)->setTimezone('UTC')->format('H:i');

        return compact('timezone', 'startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    #[Computed]
    public function orders()
    {
        $dateTimeData = $this->prepareDateTimeData();

        $query = Order::with(['branch', 'addedBy', 'customer', 'waiter', 'table', 'deliveryPlatform', 'payments'])
            ->where('branch_id', branch()->id)
            ->whereBetween('date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(date_time) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                } else {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(date_time) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(date_time) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });

        if ($this->orderTypeFilter) {
            $query->where('order_type', $this->orderTypeFilter);
        }

        if ($this->searchTerm) {
            $search = '%' . $this->searchTerm . '%';
            $normalizedSearch = '%' . str_replace(' ', '_', $this->searchTerm) . '%';

            $query->where(function ($q) use ($search, $normalizedSearch) {
                $q->where('order_number', 'like', $search)
                    ->orWhere('status', 'like', $normalizedSearch)
                    ->orWhere('order_type', 'like', $normalizedSearch)
                    ->orWhereHas('branch', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    })
                    ->orWhereHas('payments', function ($q) use ($normalizedSearch) {
                        $q->where('payment_method', 'like', $normalizedSearch);
                    })
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', $search)
                            ->orWhere('phone', 'like', $search);
                    })
                    ->orWhereHas('waiter', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    })
                    ->orWhereHas('addedBy', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    });
            });
        }

        $orders = $query->get();

        switch ($this->sortBy) {
            case 'id':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('id')
                    : $orders->sortByDesc('id');

            case 'branch':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy(fn ($order) => $order->branch?->name)
                    : $orders->sortByDesc(fn ($order) => $order->branch?->name);

            case 'order_number':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('order_number')
                    : $orders->sortByDesc('order_number');

            case 'placed_by':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy(fn ($order) => $order->addedBy?->name)
                    : $orders->sortByDesc(fn ($order) => $order->addedBy?->name);

            case 'amount_paid':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('amount_paid')
                    : $orders->sortByDesc('amount_paid');

            case 'total_tax_amount':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('total_tax_amount')
                    : $orders->sortByDesc('total_tax_amount');

            case 'date_time':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('date_time')
                    : $orders->sortByDesc('date_time');

            case 'order_type':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('order_type')
                    : $orders->sortByDesc('order_type');

            case 'total':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('total')
                    : $orders->sortByDesc('total');

            case 'status':
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('status')
                    : $orders->sortByDesc('status');

            default:
                return $this->sortDirection === 'asc'
                    ? $orders->sortBy('date_time')
                    : $orders->sortByDesc('date_time');
        }
    }

    #[Computed]
    public function totalOrders()
    {
        return $this->orders->count();
    }

    #[Computed]
    public function totalOrderAmount()
    {
        return $this->orders->sum('total');
    }

    public function render()
    {
        return view('livewire.reports.order-report');
    }
}
