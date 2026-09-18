<?php

namespace App\Livewire\DeliveryPortal;

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Component;
use Livewire\WithPagination;

class DeliveryHistory extends Component
{
    use WithPagination;

    public $restaurant;

    public function render()
    {
        $executive = delivery_executive();

        $orders = Order::withoutGlobalScopes()
            ->with(['items', 'customer'])
            ->where('delivery_executive_id', $executive?->id)
            ->where('order_type', 'delivery')
            ->whereIn('order_status', OrderStatus::terminalProgressValues())
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.delivery-portal.delivery-history', [
            'orders' => $orders,
        ]);
    }
}
