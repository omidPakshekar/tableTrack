<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Carbon\Carbon;
use Livewire\Component;

class TodayOrderList extends Component
{

    protected $listeners = ['refreshOrders' => '$refresh'];
    public $waiterOrders;
    public $orders;

    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $orders = Order::withCount('items')->with('table', 'waiter', 'orderType')
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft')
            ->orderBy('id', 'desc')
            ->where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC);

        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $orders = $orders->where('waiter_id', user()->id);
        }

        $this->waiterOrders = $orders->get();

        $this->orders = $orders->get();

        return view('livewire.dashboard.today-order-list', [
            'waiterOrders' => $this->waiterOrders,
            'orders' => $this->orders
        ]);
    }

}
