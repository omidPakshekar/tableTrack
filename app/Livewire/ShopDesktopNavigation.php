<?php

namespace App\Livewire;

use App\Models\Table;
use Livewire\Component;
use Livewire\Attributes\On;

class ShopDesktopNavigation extends Component
{
    protected $listeners = ['setCustomer' => '$refresh'];

    public $orderItemCount = 0;
    public $restaurant;
    public $shopBranch;
    public $showWaiterButtonCheck = false;
    public $table = null;

    #[On('updateCartCount')]
    public function updateCartCount($count)
    {
        $this->orderItemCount = $count;
    }

    public function mount()
    {
        $this->getTableFromRequest();
        $this->showWaiterButtonCheck = $this->checkWaiterButtonStatus();
    }

    public function getTableFromRequest()
    {
        // Check if we have a table hash from the route parameter
        $tableHash = request()->route('hash');
        if ($tableHash) {
            $this->table = Table::where('hash', $tableHash)->first();
        }

        // If no table found from route, check the 'table' query parameter
        if (!$this->table && request()->filled('table')) {
            $this->table = Table::where('hash', request('table'))->first();
        }
    }

    #[On('orderTypeChanged')]
    public function onOrderTypeChanged($slug)
    {
        // Recalculate visibility when Cart broadcasts a new order type
        $this->showWaiterButtonCheck = $this->checkWaiterButtonStatus($slug);
    }

    public function checkWaiterButtonStatus($orderTypeSlug = null)
    {
        $this->dispatch('refreshComponent');

        if (!$this->restaurant->is_waiter_request_enabled || !$this->restaurant->is_waiter_request_enabled_on_desktop) {
            return false;
        }

        // Prefer explicit slug from event; otherwise fall back to session value
        if (!$orderTypeSlug) {
            $orderTypeSlug = session('shop_order_type_slug');
        }

        // Hide Call Waiter on Delivery & Pickup pages
        if ($orderTypeSlug && $orderTypeSlug !== 'dine_in') {
            return false;
        }

        $cameFromQR = request()->query('hash') === $this->restaurant->hash || request()->boolean('from_qr');

        if ($this->restaurant->is_waiter_request_enabled_open_by_qr && !$cameFromQR) {
            return false;
        }

        return true;
    }

    private function getPackageModules($restaurant)
    {
        if (!$restaurant || !$restaurant->package) {
            return [];
        }

        $modules = $restaurant->package->modules->pluck('name')->toArray();
        $additionalFeatures = json_decode($restaurant->package->additional_features ?? '[]', true);

        return array_merge($modules, $additionalFeatures);
    }

    public function render()
    {
        $modules = $this->getPackageModules($this->restaurant);
        return view('livewire.shop-desktop-navigation', ['modules' => $modules]);
    }

}
