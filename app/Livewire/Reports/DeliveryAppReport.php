<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use Livewire\Component;
use App\Models\Order;
use App\Models\DeliveryPlatform;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Models\OrderType;

class DeliveryAppReport extends Component
{
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $startTime = '00:00'; // Default start time
    public $endTime = '23:59';  // Default end time
    public $searchTerm;
    public $selectedDeliveryApp = 'all';

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('delivery_app_report_date_range_type', 'currentWeek');
        $this->setDateRange();
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('delivery_app_report_date_range_type', $value, 60 * 24 * 30)); // 30 days
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

    private function prepareDateTimeData()
    {
        $timezone = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $startDateTime = parseRestaurantDateTime($this->startDate . ' ' . $this->startTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $endDateTime = parseRestaurantDateTime($this->endDate . ' ' . $this->endTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $startTime = Carbon::parse($this->startTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::parse($this->endTime, $timezone)->setTimezone('UTC')->format('H:i');

        return compact('timezone', 'startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    public function render()
    {
        $dateTimeData = $this->prepareDateTimeData();

        // Get all delivery platforms
        $deliveryApps = DeliveryPlatform::all();

        $deliveryOrderTypes = OrderType::where('slug', 'delivery')->first();

        // Get aggregated data grouped by delivery app (including null for direct delivery)
        $deliveryAppStats = Order::select(
                'delivery_app_id',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(sub_total) as total_revenue'),
                DB::raw('SUM(delivery_fee) as total_delivery_fees'),
                DB::raw('AVG(sub_total) as avg_order_value')
            )
            ->whereBetween('date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->where('status', 'paid')
            ->where('order_type_id', $deliveryOrderTypes->id)
            ->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw("TIME(date_time) BETWEEN ? AND ?", [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                } else {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw("TIME(date_time) >= ?", [$dateTimeData['startTime']])
                            ->orWhereRaw("TIME(date_time) <= ?", [$dateTimeData['endTime']]);
                    });
                }
            });

        // Filter by selected delivery app for stats
        if ($this->selectedDeliveryApp !== 'all') {
            if ($this->selectedDeliveryApp === 'direct') {
                // Direct delivery (no delivery app)
                $deliveryAppStats->whereNull('delivery_app_id');
            } else {
                // Specific delivery app
                $deliveryAppStats->where('delivery_app_id', $this->selectedDeliveryApp);
            }
        }

        $deliveryAppStats = $deliveryAppStats->groupBy('delivery_app_id')->get();

        // Calculate commission for each delivery app
        $reportData = $deliveryAppStats->map(function ($stat) use ($deliveryApps) {
            $deliveryApp = $deliveryApps->firstWhere('id', $stat->delivery_app_id);

            // Handle direct delivery (no delivery app)
            if (!$deliveryApp && $stat->delivery_app_id === null) {
                return [
                    'delivery_app' => (object) [
                        'id' => null,
                        'name' => __('modules.report.directDelivery'),
                        'logo_url' => null,
                        'commission_type' => 'percent',
                        'commission_value' => 0,
                    ],
                    'total_orders' => $stat->total_orders,
                    'total_revenue' => $stat->total_revenue,
                    'total_delivery_fees' => $stat->total_delivery_fees,
                    'avg_order_value' => $stat->avg_order_value,
                    'commission' => 0,
                    'net_revenue' => $stat->total_revenue,
                    'is_direct' => true,
                ];
            }

            // Skip if delivery app not found and not direct delivery
            if (!$deliveryApp) {
                return null;
            }

            $commission = 0;
            if ($deliveryApp->commission_type === 'percent') {
                $commission = ($stat->total_revenue * $deliveryApp->commission_value) / 100;
            } else {
                $commission = $deliveryApp->commission_value * $stat->total_orders;
            }

            return [
                'delivery_app' => $deliveryApp,
                'total_orders' => $stat->total_orders,
                'total_revenue' => $stat->total_revenue,
                'total_delivery_fees' => $stat->total_delivery_fees,
                'avg_order_value' => $stat->avg_order_value,
                'commission' => $commission,
                'net_revenue' => $stat->total_revenue - $commission,
                'is_direct' => false,
            ];
        })->filter()->values();

        // Calculate overall totals
        $totalOrders = $reportData->sum('total_orders');
        $totalRevenue = $reportData->sum('total_revenue');
        $totalCommission = $reportData->sum('commission');
        $totalDeliveryFees = $reportData->sum('total_delivery_fees');
        $netRevenue = $reportData->sum('net_revenue');

        return view('livewire.reports.delivery-app-report', [
            'deliveryApps' => $deliveryApps,
            'reportData' => $reportData,
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'totalCommission' => $totalCommission,
            'totalDeliveryFees' => $totalDeliveryFees,
            'netRevenue' => $netRevenue,
        ]);
    }
}
