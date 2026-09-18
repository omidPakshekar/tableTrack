<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TodayTableEarnings extends Component
{

    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $orders = Order::select('table_id', DB::raw('SUM(total) as total_price'))
            ->with('table.area')
            ->whereNotNull('table_id')
            ->where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC)
            ->groupBy('table_id')
            ->where('status', 'paid')
            ->get()->sortBy('total_price', SORT_REGULAR, true)->splice(0, 5);

        return view('livewire.dashboard.today-table-earnings', [
            'orders' => $orders
        ]);
    }

}
