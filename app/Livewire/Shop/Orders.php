<?php

namespace App\Livewire\Shop;

use App\Models\Order;
use Livewire\Component;

class Orders extends Component
{

    public $orders;
    public $restaurant;
    public $shopBranch;
    public $orderID;

    public function mount()
    {
        if (is_null(customer())) {

            if (module_enabled('Subdomain')) {
                return $this->redirect('/');
            }

            return $this->redirect(route('home'));
        }

        // Check if current_order parameter is present to show specific order detail
        if (request()->has('current_order')) {
            $this->orderID = request()->get('current_order');
            // Dispatch event to show order detail (if OrderDetail component is on the page)
            $this->dispatch('showOrderDetail', id: $this->orderID);
        }

        $this->orders = Order::withoutGlobalScopes()->where('customer_id', customer()->id)->orderBy('id', 'desc')
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft')
            ->get();

    }

    public function render()
    {
        return view('livewire.shop.orders');
    }
}
