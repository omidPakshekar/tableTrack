<?php

namespace App\Livewire\Shop;

use App\Concerns\PrintsShopKot;
use App\Services\ShopCartKotPrintUrls;
use App\Models\Order;
use App\Traits\PrinterSetting;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class DeferredKotPrint extends Component
{
    use LivewireAlert;
    use PrinterSetting;
    use PrintsShopKot;

    public int $orderId;

    public bool $printed = false;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function runDeferredKotPrint(): void
    {
        if ($this->printed) {
            return;
        }
        $this->printed = true;

        $order = Order::with([
            'kot.items.menuItem',
            'kot.items.menuItemVariation',
            'kot.items.modifierOptions',
        ])->find($this->orderId);

        if (!$order || $order->placed_via !== 'shop') {
            return;
        }

        $order->loadMissing('branch.restaurant');
        if (!ShopCartKotPrintUrls::shouldPrintKotAfterShopOnlinePayment($order, $order->branch?->restaurant)) {
            return;
        }

        $this->printKot($order);
    }

    public function render()
    {
        return view('livewire.shop.deferred-kot-print');
    }
}
