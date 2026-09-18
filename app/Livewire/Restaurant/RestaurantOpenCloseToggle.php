<?php

namespace App\Livewire\Restaurant;

use App\Models\Restaurant;
use App\Services\RestaurantCloseGuard;
use Livewire\Attributes\On;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class RestaurantOpenCloseToggle extends Component
{
    use LivewireAlert;

    public bool $canManageOpenClose = false;
    public bool $showToggle = false;
    public bool $isRestaurantOpen = true;
    public bool $showConfirmModal = false;
    public bool $closeBlocked = false;

    /** @var list<array{id: int, table_code: string|null}> */
    public array $closeBlockTables = [];

    /** @var list<array{id: int, order_number: mixed, show_formatted_order_number: string|null, table_code: string|null, status: string, total: mixed}> */
    public array $closeBlockOrders = [];

    public function mount(): void
    {
        $this->loadState();
    }

    #[On('settingsUpdated')]
    public function refreshToggleState(): void
    {
        $this->loadState();
    }

    public function toggleRestaurantState(): void
    {
        if (!$this->canManageOpenClose) {
            abort(403);
        }

        $currentRestaurant = Restaurant::find(restaurant()->id);

        if (!$currentRestaurant) {
            return;
        }

        $isToggleMode = ($currentRestaurant->restaurant_open_close_mode ?? 'auto') === 'manual'
            && ($currentRestaurant->restaurant_manual_open_close_type ?? 'time') === 'toggle';

        if (!$isToggleMode) {
            $this->loadState();
            return;
        }

        $aboutToClose = !(bool) $currentRestaurant->is_temporarily_closed;

        if ($aboutToClose) {
            $branchId = (int) (branch()->id ?? 0);

            if ($branchId && !RestaurantCloseGuard::canClose($branchId)) {
                $this->applyCloseBlockers(RestaurantCloseGuard::blockers($branchId));
                $this->showConfirmModal = true;

                return;
            }
        }

        $currentRestaurant->is_temporarily_closed = !$currentRestaurant->is_temporarily_closed;
        $currentRestaurant->save();

        session()->forget('restaurant');

        $this->resetCloseBlockers();
        $this->loadState();
        $this->showConfirmModal = false;

        $this->alert('success', $this->isRestaurantOpen ? __('messages.restaurantOpened') : __('messages.restaurantClosed'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function openConfirmModal(): void
    {
        if (!$this->canManageOpenClose || !$this->showToggle) {
            return;
        }

        $this->resetCloseBlockers();

        if ($this->isRestaurantOpen) {
            $branchId = (int) (branch()->id ?? 0);

            if ($branchId) {
                $blockers = RestaurantCloseGuard::blockers($branchId);

                if (!empty($blockers['tables']) || !empty($blockers['orders'])) {
                    $this->applyCloseBlockers($blockers);
                }
            }
        }

        $this->showConfirmModal = true;
    }

    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->resetCloseBlockers();
    }

    public function updatedShowConfirmModal(bool $value): void
    {
        if (!$value) {
            $this->resetCloseBlockers();
        }
    }

    public function render()
    {
        return view('livewire.restaurant.restaurant-open-close-toggle');
    }

    private function loadState(): void
    {
        $this->canManageOpenClose = user()->hasRole('Admin_' . user()->restaurant_id)
            || user_can('Show Restaurant Open/Close');

        $currentRestaurant = Restaurant::find(restaurant()->id);

        if (!$currentRestaurant) {
            $this->showToggle = false;
            $this->isRestaurantOpen = true;
            return;
        }

        $this->showToggle = $this->canManageOpenClose
            && ($currentRestaurant->restaurant_open_close_mode ?? 'auto') === 'manual'
            && ($currentRestaurant->restaurant_manual_open_close_type ?? 'time') === 'toggle';

        $this->isRestaurantOpen = !$currentRestaurant->is_temporarily_closed;
    }

    /**
     * @param array{tables: list<array{id: int, table_code: string|null}>, orders: list<array{id: int, order_number: mixed, show_formatted_order_number: string|null, table_code: string|null, status: string, total: mixed}>} $blockers
     */
    private function applyCloseBlockers(array $blockers): void
    {
        $this->closeBlocked = true;
        $this->closeBlockTables = $blockers['tables'];
        $this->closeBlockOrders = $blockers['orders'];
    }

    private function resetCloseBlockers(): void
    {
        $this->closeBlocked = false;
        $this->closeBlockTables = [];
        $this->closeBlockOrders = [];
    }
}
