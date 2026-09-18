<?php

namespace App\Livewire\Reports;

use App\Exports\CategoryReportExport;
use App\Models\ItemCategory;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class CategoryReport extends Component
{

    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $startTime = '00:00'; // Default start time
    public $endTime = '23:59';  // Default end time

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('category_report_date_range_type', 'currentWeek');
        $this->setDateRange();
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('category_report_date_range_type', $value, 60 * 24 * 30)); // 30 days
    }
    
    public function updatedStartTime($value)
    {
        // Normalize time format to H:i (e.g., "14:30")
        $this->startTime = $this->normalizeTime($value);
    }
    
    public function updatedEndTime($value)
    {
        // Normalize time format to H:i (e.g., "14:30")
        $this->endTime = $this->normalizeTime($value);
    }
    
    private function normalizeTime($time)
    {
        if (empty($time)) {
            return '00:00';
        }
        
        // Extract time in H:i format from various possible formats
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $matches)) {
            $hours = str_pad((int)$matches[1], 2, '0', STR_PAD_LEFT);
            $minutes = str_pad((int)$matches[2], 2, '0', STR_PAD_LEFT);
            
            // Validate hours and minutes
            $hours = min(23, max(0, (int)$hours));
            $minutes = min(59, max(0, (int)$minutes));
            
            return sprintf('%02d:%02d', $hours, $minutes);
        }
        
        // Fallback to default if parsing fails
        return '00:00';
    }

    public function setDateRange()
    {
        $tz = timezone();

        $ranges = [
            'today' => [Carbon::now($tz)->startOfDay(), Carbon::now($tz)->startOfDay()],
            'lastWeek' => [Carbon::now($tz)->subWeek()->startOfWeek(), Carbon::now($tz)->subWeek()->endOfWeek()],
            'last7Days' => [Carbon::now($tz)->subDays(7), Carbon::now($tz)->startOfDay()],
            'currentMonth' => [Carbon::now($tz)->startOfMonth(), Carbon::now($tz)->startOfDay()],
            'lastMonth' => [Carbon::now($tz)->subMonth()->startOfMonth(), Carbon::now($tz)->subMonth()->endOfMonth()],
            'currentYear' => [Carbon::now($tz)->startOfYear(), Carbon::now($tz)->startOfDay()],
            'lastYear' => [Carbon::now($tz)->subYear()->startOfYear(), Carbon::now($tz)->subYear()->endOfYear()],
            'currentWeek' => [Carbon::now($tz)->startOfWeek(), Carbon::now($tz)->endOfWeek()],
        ];

        [$start, $end] = $ranges[$this->dateRangeType] ?? $ranges['currentWeek'];
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $this->startDate = $start->displayDate($dateFormat);
        $this->endDate = $end->displayDate($dateFormat);
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
            return Excel::download(new CategoryReportExport($data['startDateTime'], $data['endDateTime'], $data['startTime'], $data['endTime'], $data['timezone']), 'category-report-' . now()->toDateTimeString() . '.xlsx');
        }
    }

    private function prepareDateTimeData()
    {
        $timezone = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Normalize time values to ensure they're in H:i format
        $normalizedStartTime = $this->normalizeTime($this->startTime);
        $normalizedEndTime = $this->normalizeTime($this->endTime);

        $startDateTime = parseRestaurantDateTime($this->startDate . ' ' . $normalizedStartTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $endDateTime = parseRestaurantDateTime($this->endDate . ' ' . $normalizedEndTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        // Parse time values safely
        $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $timezone)->setTimezone('UTC')->format('H:i');

        return compact('timezone', 'startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    public function render()
    {
        $dateTimeData = $this->prepareDateTimeData();

        $query = ItemCategory::with(['orders' => function ($q) use ($dateTimeData) {
            return $q->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'paid')
                ->whereBetween('orders.date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
                ->where(function ($q) use ($dateTimeData) {
                    if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                        $q->whereRaw("TIME(orders.date_time) BETWEEN ? AND ?", [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                    } else {
                        $q->where(function ($sub) use ($dateTimeData) {
                            $sub->whereRaw("TIME(orders.date_time) >= ?", [$dateTimeData['startTime']])
                                ->orWhereRaw("TIME(orders.date_time) <= ?", [$dateTimeData['endTime']]);
                        });
                    }
                });
        }])->get();

        return view('livewire.reports.category-report', [
            'menuItems' => $query
        ]);
    }

}
