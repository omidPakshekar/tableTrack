<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use Livewire\Component;
use App\Models\Refund;
use Livewire\Attributes\On;
use App\Exports\RefundReportExport;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;

class RefundReport extends Component
{
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $startTime = '00:00';
    public $endTime = '23:59';
    public $searchTerm;
    public $sortBy = 'processed_at';
    public $sortDirection = 'desc';
    public $refundTypeFilter = '';

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        $tz = timezone();

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('refund_report_date_range_type', 'currentWeek');
        $this->startDate = Carbon::now($tz)->startOfWeek()->format('m/d/Y');
        $this->endDate = Carbon::now($tz)->endOfWeek()->format('m/d/Y');
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('refund_report_date_range_type', $value, 60 * 24 * 30)); // 30 days
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
                new RefundReportExport($data['startDateTime'], $data['endDateTime'], $data['startTime'], $data['endTime'], $data['timezone'], $this->searchTerm, $this->refundTypeFilter),
                'refund-report-' . now()->toDateTimeString() . '.xlsx'
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
    public function refunds()
    {
        $dateTimeData = $this->prepareDateTimeData();

        $query = Refund::with(['payment.order.deliveryPlatform', 'deliveryPlatform', 'refundReason', 'processedBy'])
            ->where('branch_id', branch()->id)
            ->where('status', 'processed')
            ->whereBetween('processed_at', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw("TIME(processed_at) BETWEEN ? AND ?", [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                } else {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw("TIME(processed_at) >= ?", [$dateTimeData['startTime']])
                            ->orWhereRaw("TIME(processed_at) <= ?", [$dateTimeData['endTime']]);
                    });
                }
            });

        if ($this->refundTypeFilter) {
            $query->where('refund_type', $this->refundTypeFilter);
        }

        if ($this->searchTerm) {
            $query->where(function ($q) {
                $q->whereHas('order', function ($q) {
                    $q->where('order_number', 'like', '%' . $this->searchTerm . '%');
                })
                ->orWhereHas('refundReason', function ($q) {
                    $q->where('reason', 'like', '%' . $this->searchTerm . '%');
                })
                ->orWhereHas('processedBy', function ($q) {
                    $q->where('name', 'like', '%' . $this->searchTerm . '%');
                });
            });
        }

        // Use stored commission_adjustment from database
        return $query->get();

        // Sort by the selected field
        switch ($this->sortBy) {
            case 'processed_at':
                return $this->sortDirection === 'asc'
                    ? $refunds->sortBy('processed_at')
                    : $refunds->sortByDesc('processed_at');

            case 'refund_type':
                return $this->sortDirection === 'asc'
                    ? $refunds->sortBy('refund_type')
                    : $refunds->sortByDesc('refund_type');

            case 'amount':
                return $this->sortDirection === 'asc'
                    ? $refunds->sortBy('amount')
                    : $refunds->sortByDesc('amount');

            case 'processed_by':
                return $this->sortDirection === 'asc'
                    ? $refunds->sortBy('processedBy.name')
                    : $refunds->sortByDesc('processedBy.name');

            default:
                return $this->sortDirection === 'asc'
                    ? $refunds->sortBy('processed_at')
                    : $refunds->sortByDesc('processed_at');
        }
    }
    
    #[Computed]
    public function totalRefunds()
    {
        return $this->refunds->count();
    }
    
    #[Computed]
    public function totalRefundAmount()
    {
        return $this->refunds->sum('amount');
    }

    #[Computed]
    public function totalOriginalAmount()
    {
        return $this->refunds->sum(function ($refund) {
            return $refund->payment ? $refund->payment->amount : 0;
        });
    }

    #[Computed]
    public function totalCommissionAdjustment()
    {
        return $this->refunds->sum('commission_adjustment') ?? 0;
    }

    public function render()
    {
        return view('livewire.reports.refund-report');
    }
}
