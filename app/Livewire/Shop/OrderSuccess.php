<?php

namespace App\Livewire\Shop;

use App\Models\Branch;
use App\Models\Order;
use Livewire\Attributes\On;
use Livewire\Component;

class OrderSuccess extends Component
{

    public $id;
    public $order;
    public $restaurant;
    public $shopBranch;
    public $dateFormat;
    public $timeFormat;
    public $showLoyaltyOnSuccess = false;

    public function mount()
    {
        $this->order = Order::with([
            'taxes.tax',
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'orderCashCollection',
            'charges.charge',
        ])->where('id', $this->id)->firstOrFail();

        $this->showLoyaltyOnSuccess = function_exists('module_enabled') && module_enabled('Loyalty');

        if (is_null(customer()) && $this->restaurant->customer_login_required) {
            return $this->redirect(route('home'));
        }

        $this->restaurant->loadMissing('euAllergenSetting');

        if (request()->branch && request()->branch != '') {
            $this->shopBranch = Branch::find(request()->branch);
        } else {
            $this->shopBranch = $this->restaurant->branches->first();
        }

        // Set date and time formats
        $this->dateFormat = $this->restaurant->date_format ?? dateFormat();
        $this->timeFormat = $this->restaurant->time_format ?? timeFormat();
    }

    public function render()
    {
        return view('livewire.shop.order-success', [
            'taxMode' => $this->order->tax_mode ?? ($this->restaurant->tax_mode ?? 'order'),
        ]);
    }

    #[On('orderLoyaltyUpdated')]
    public function refreshOrderTotals(): void
    {
        $this->order->refresh();
        $this->order->load([
            'taxes.tax',
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'orderCashCollection',
            'charges.charge',
        ]);
    }

    public function refreshOrderSuccess()
    {
        $this->refreshOrderTotals();
        $this->dispatch('$refresh');
        $this->dispatch('refreshOrderSuccess');
    }
}
