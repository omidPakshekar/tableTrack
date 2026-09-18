<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use App\Models\MenuItem;
use App\Scopes\AvailableMenuItemScope;

class TodayMenuItemEarnings extends Component
{

    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $query = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)->with(['orders' => function ($q) use ($startUTC, $endUTC) {
            return $q->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'paid')
                ->where('orders.date_time', '>=', $startUTC)
                ->where('orders.date_time', '<=', $endUTC);
        }])->get();

        $menuItems = $query->map(function ($order) {
            $order['total'] = $order->orders->sum('amount');
            return $order;
        });

        $menuItems = $query->filter(function ($order) {
            return ($order->total > 0);
        })->sortBy('total', SORT_REGULAR, true)->splice(0, 5);

        return view('livewire.dashboard.today-menu-item-earnings', [
            'menuItems' => $menuItems
        ]);
    }

}
