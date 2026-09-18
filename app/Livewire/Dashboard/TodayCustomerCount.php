<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Carbon\Carbon;
use Livewire\Component;

class TodayCustomerCount extends Component
{
    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();
        
        $orderCount = Order::where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC)
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft')
            ->distinct()->count('customer_id');
        
        // Get business day boundaries for yesterday
        $yesterdayBoundaries = getBusinessDayBoundaries(branch(), now()->subDay());
        $yesterdayStartUTC = $yesterdayBoundaries['start']->setTimezone('UTC')->toDateTimeString();
        $yesterdayEndUTC = $yesterdayBoundaries['end']->setTimezone('UTC')->toDateTimeString();
        
        $yesterdayCount = Order::where('orders.date_time', '>=', $yesterdayStartUTC)
            ->where('orders.date_time', '<=', $yesterdayEndUTC)
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft')
            ->distinct()->count('customer_id');

        $percentChange = calculatePercentChange($orderCount, $yesterdayCount);

        return view('livewire.dashboard.today-customer-count', [
            'orderCount' => $orderCount,
            'percentChange' => $percentChange,
        ]);
    }

}
