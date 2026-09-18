<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Component;

class DeliveryDesktopNavigation extends Component
{
    public $restaurant;
    public $shopBranch;
    public $activeTab = '';

    protected $listeners = ['deliveryExecutiveProfileUpdated' => '$refresh'];

    public function mount()
    {
        $this->activeTab = $this->resolveActiveTab();
    }

    private function resolveActiveTab(): string
    {
        if (request()->routeIs('delivery.dashboard')) {
            return 'dashboard';
        }

        if (request()->routeIs('delivery.history')) {
            return 'history';
        }

        if (request()->routeIs('delivery.cod-settlement')) {
            return 'cod-settlement';
        }

        if (request()->routeIs('delivery.assigned-orders')) {
            return 'assigned';
        }

        if (!request()->routeIs('delivery.orders.show')) {
            return '';
        }

        $from = request()->query('from');
        if (in_array($from, ['assigned', 'history', 'dashboard', 'cod-settlement'], true)) {
            return $from;
        }

        $executive = delivery_executive();
        $uuid = request()->route('uuid');

        if (!$executive || !$uuid) {
            return '';
        }

        $order = Order::withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->where('delivery_executive_id', $executive->id)
            ->first();

        if (!$order) {
            return '';
        }

        return in_array($order->order_status->value, OrderStatus::terminalProgressValues(), true)
            ? 'history'
            : 'assigned';
    }

    public function render()
    {
        return view('livewire.delivery-desktop-navigation', [
            'activeTab' => $this->activeTab,
        ]);
    }
}
