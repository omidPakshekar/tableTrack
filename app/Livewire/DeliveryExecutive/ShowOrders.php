<?php

namespace App\Livewire\DeliveryExecutive;

use App\Enums\OrderStatus;
use Livewire\Component;

class ShowOrders extends Component
{

    public $customer;

    public function render()
    {
        $trackingEnabled = module_enabled('RestApi');
        $mapApiKey = global_setting()->google_map_api_key ?? restaurant()?->map_api_key ?? null;
        $mapProvider = global_setting()->map_provider ?? 'google';

        $orders = collect();

        if ($this->customer) {
            $this->customer->loadMissing(['orders' => function ($query) {
                $query->latest('id');
            }]);

            $orders = $this->customer->orders->map(function ($order) {
                $progressStatus = is_object($order->order_status) ? $order->order_status->value : $order->order_status;

                return [
                    'order' => $order,
                    'isOutForDelivery' => in_array((string) $progressStatus, ['out_for_delivery', 'reached_destination'], true),
                    'isDelivered' => in_array((string) $progressStatus, OrderStatus::fulfilledProgressValues(), true),
                ];
            });
        }

        return view('livewire.delivery-executive.show-orders', [
            'trackingEnabled' => $trackingEnabled,
            'mapApiKey' => $mapApiKey,
            'mapProvider' => $mapProvider,
            'orders' => $orders,
        ]);
    }

}
