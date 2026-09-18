<?php

namespace App\Livewire\DeliveryPortal;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderCashCollectionService;
use Livewire\Component;

class AssignedOrders extends Component
{
    public $restaurant;

    public function render()
    {
        $executive = delivery_executive();

        $orders = collect();

        if ($executive) {
            $orders = Order::withoutGlobalScopes()
                ->with(['items', 'customer'])
                ->with(['orderCashCollection'])
                ->where('delivery_executive_id', $executive->id)
                ->where('order_type', 'delivery')
                ->whereNotIn('order_status', OrderStatus::terminalProgressValues())
                ->orderByDesc('id')
                ->get();

            $service = app(OrderCashCollectionService::class);
            $orders->each(fn (Order $order) => $service->syncForOrder($order));
            $orders->load('orderCashCollection');
        }

        return view('livewire.delivery-portal.assigned-orders', [
            'orders' => $orders,
        ]);
    }
}
