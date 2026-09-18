<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class WeeklySalesChart extends Component
{

    public function render()
    {
        $tz = timezone();
        $offset = Carbon::now($tz)->format('P');
        $nowLocal = Carbon::now($tz);

        $startOfMonth = $nowLocal->copy()->startOfMonth()->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $tillToday = $nowLocal->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString();

        $previousMonth = $nowLocal->copy()->subMonth();
        $startOfLastMonth = $previousMonth->copy()->startOfMonth()->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $endOfLastMonth = $previousMonth->copy()->endOfMonth()->endOfDay()->setTimezone('UTC')->toDateTimeString();

        $salesData = Order::select(
            DB::raw('DATE(CONVERT_TZ(date_time, "+00:00", "' . $offset . '")) as date'),
            DB::raw('SUM(total) as total_sales')
        )
            ->where('orders.date_time', '>=', $startOfMonth)
            ->where('orders.date_time', '<=', $tillToday)
            ->where('status', 'paid')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $monthlyEarnings = Order::where('orders.date_time', '>=', $startOfMonth)
            ->where('orders.date_time', '<=', $tillToday)
            ->where('status', 'paid')
            ->sum('total');

        $previousEarnings = Order::where('orders.date_time', '>=', $startOfLastMonth)
            ->where('orders.date_time', '<=', $endOfLastMonth)
            ->where('status', 'paid')
            ->sum('total');

        $percentChange = calculatePercentChange($monthlyEarnings, $previousEarnings);

        return view('livewire.dashboard.weekly-sales-chart', [
            'salesData' => $salesData,
            'monthlyEarnings' => $monthlyEarnings,
            'percentChange' => $percentChange,
        ]);
    }

}
