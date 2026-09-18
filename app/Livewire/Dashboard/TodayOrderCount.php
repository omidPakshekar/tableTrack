<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Carbon\Carbon;
use Livewire\Component;

class TodayOrderCount extends Component
{
    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $todayQuery = Order::where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC)
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft');

        // Filter by waiter if user is a waiter
        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $todayQuery->where('waiter_id', user()->id);
        }

        $orderCount = $todayQuery->count();

        // Get business day boundaries for yesterday
        $yesterdayBoundaries = getBusinessDayBoundaries(branch(), now()->subDay());
        $yesterdayStartUTC = $yesterdayBoundaries['start']->setTimezone('UTC')->toDateTimeString();
        $yesterdayEndUTC = $yesterdayBoundaries['end']->setTimezone('UTC')->toDateTimeString();

        $yesterdayQuery = Order::where('orders.date_time', '>=', $yesterdayStartUTC)
            ->where('orders.date_time', '<=', $yesterdayEndUTC)
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft');

        // Filter by waiter if user is a waiter
        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $yesterdayQuery->where('waiter_id', user()->id);
        }

        $yesterdayCount = $yesterdayQuery->count();

        $percentChange = calculatePercentChange($orderCount, $yesterdayCount);

        return view('livewire.dashboard.today-order-count', [
            'orderCount' => $orderCount,
            'percentChange' => $percentChange,
        ]);
    }

}
