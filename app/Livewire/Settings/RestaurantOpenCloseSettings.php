<?php

namespace App\Livewire\Settings;

use App\Services\RestaurantCloseGuard;
use Livewire\Component;
use Illuminate\Support\Facades\Schema;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class RestaurantOpenCloseSettings extends Component
{
    use LivewireAlert;

    public $settings;
    public bool $openCloseAuto = true;
    public bool $openCloseManual = false;
    public bool $isTemporarilyClosed = false;
    public bool $manualUseToggle = false;
    public bool $manualUseTime = true;
    public ?string $manualOpenTime = null;
    public ?string $manualCloseTime = null;
    public bool $openCloseColumnsAvailable = true;

    /** @var list<array{id: int, table_code: string|null}> */
    public array $closeBlockTables = [];

    /** @var list<array{id: int, order_number: mixed, show_formatted_order_number: string|null, table_code: string|null, status: string, total: mixed}> */
    public array $closeBlockOrders = [];

    public function mount($settings)
    {
        abort_if((
            !user()->hasRole('Admin_' . user()->restaurant_id)
            && !user_can('Show Restaurant Open/Close')
        ), 403);

        $this->settings = $settings;
        $this->openCloseColumnsAvailable = $this->hasRequiredOpenCloseColumns();
        $this->isTemporarilyClosed = (bool) ($this->settings->is_temporarily_closed ?? false);
        $this->openCloseManual = ($this->settings->restaurant_open_close_mode ?? 'auto') === 'manual';
        $this->openCloseAuto = !$this->openCloseManual;
        $manualControlType = $this->settings->restaurant_manual_open_close_type ?? 'time';
        $this->manualUseToggle = $manualControlType === 'toggle';
        $this->manualUseTime = !$this->manualUseToggle;

        // Temporary close is a top-level override; reflect it clearly in UI.
        if ($this->isTemporarilyClosed) {
            $this->openCloseAuto = false;
            $this->openCloseManual = false;
        }

        $this->manualOpenTime = $this->settings->manual_open_time ? date('H:i', strtotime($this->settings->manual_open_time)) : null;
        $this->manualCloseTime = $this->settings->manual_close_time ? date('H:i', strtotime($this->settings->manual_close_time)) : null;
    }

    public function updated($propertyName)
    {
        if ($propertyName === 'isTemporarilyClosed') {
            $this->closeBlockTables = [];
            $this->closeBlockOrders = [];

            if ($this->isTemporarilyClosed) {
                $this->openCloseAuto = false;
                $this->openCloseManual = false;
            }
        }

        if ($propertyName === 'openCloseAuto' && $this->openCloseAuto) {
            $this->openCloseManual = false;
            $this->isTemporarilyClosed = false;
            $this->closeBlockTables = [];
            $this->closeBlockOrders = [];
        }

        if ($propertyName === 'openCloseManual' && $this->openCloseManual) {
            $this->openCloseAuto = false;
            $this->isTemporarilyClosed = false;
            $this->closeBlockTables = [];
            $this->closeBlockOrders = [];

            if ($this->manualUseTime && empty($this->manualOpenTime)) {
                $this->manualOpenTime = '09:00';
            }

            if ($this->manualUseTime && empty($this->manualCloseTime)) {
                $this->manualCloseTime = '22:00';
            }
        }

        if ($propertyName === 'manualUseToggle' && $this->manualUseToggle) {
            $this->manualUseTime = false;
        }

        if ($propertyName === 'manualUseTime' && $this->manualUseTime) {
            $this->manualUseToggle = false;

            if (empty($this->manualOpenTime)) {
                $this->manualOpenTime = '09:00';
            }

            if (empty($this->manualCloseTime)) {
                $this->manualCloseTime = '22:00';
            }
        }

        if ($this->openCloseManual && !$this->manualUseToggle && !$this->manualUseTime) {
            $this->manualUseTime = true;
        }

        // Keep at least one mode selected if temporary close is not active.
        if (!$this->isTemporarilyClosed && !$this->openCloseAuto && !$this->openCloseManual) {
            $this->openCloseAuto = true;
        }
    }

    public function submitForm()
    {
        if (!$this->hasRequiredOpenCloseColumns()) {
            $this->openCloseColumnsAvailable = false;
            $this->alert('error', __('messages.restaurantOpenCloseSchemaMissing'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        $this->validate([
            'openCloseAuto' => 'boolean',
            'openCloseManual' => 'boolean',
            'isTemporarilyClosed' => 'boolean',
            'manualUseToggle' => 'boolean',
            'manualUseTime' => 'boolean',
        ]);

        if ($this->openCloseAuto && $this->openCloseManual) {
            $this->openCloseManual = false;
        }

        // When temporarily closed, do not override mode/times.
        if ($this->isTemporarilyClosed) {
            $alreadyClosed = (bool) ($this->settings->is_temporarily_closed ?? false);

            if (!$alreadyClosed) {
                $branchId = (int) (branch()->id ?? 0);

                if ($branchId && !RestaurantCloseGuard::canClose($branchId)) {
                    $blockers = RestaurantCloseGuard::blockers($branchId);
                    $this->closeBlockTables = $blockers['tables'];
                    $this->closeBlockOrders = $blockers['orders'];
                    $this->isTemporarilyClosed = false;

                    if (!$this->openCloseAuto && !$this->openCloseManual) {
                        $this->openCloseAuto = true;
                    }

                    $this->alert('error', __('messages.restaurantCannotCloseUntilSettled'), [
                        'toast' => true,
                        'position' => 'top-end',
                        'showCancelButton' => false,
                        'cancelButtonText' => __('app.close'),
                    ]);

                    return;
                }
            }

            $this->closeBlockTables = [];
            $this->closeBlockOrders = [];
            $this->settings->is_temporarily_closed = true;
            $this->settings->save();

            session()->forget('restaurant');

            $this->alert('success', __('messages.settingsUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        $this->closeBlockTables = [];
        $this->closeBlockOrders = [];

        if ($this->openCloseManual && !$this->manualUseToggle && !$this->manualUseTime) {
            $this->manualUseTime = true;
        }

        if ($this->openCloseManual && $this->manualUseTime) {
            $this->validate([
                'manualOpenTime' => 'required|date_format:H:i',
                'manualCloseTime' => 'required|date_format:H:i',
            ]);

            if ($this->manualOpenTime === $this->manualCloseTime) {
                $this->addError('manualCloseTime', __('messages.chooseEndTimeLater'));
                return;
            }
        }

        if (!$this->openCloseAuto && !$this->openCloseManual) {
            $this->openCloseAuto = true;
        }

        $this->settings->restaurant_open_close_mode = $this->openCloseManual ? 'manual' : 'auto';
        $this->settings->restaurant_manual_open_close_type = ($this->openCloseManual && $this->manualUseToggle) ? 'toggle' : 'time';
        $this->settings->manual_open_time = ($this->openCloseManual && $this->manualUseTime) ? $this->manualOpenTime : null;
        $this->settings->manual_close_time = ($this->openCloseManual && $this->manualUseTime) ? $this->manualCloseTime : null;
        $this->settings->is_temporarily_closed = false;
        $this->settings->save();

        session()->forget('restaurant');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function render()
    {
        return view('livewire.settings.restaurant-open-close-settings');
    }

    private function hasRequiredOpenCloseColumns(): bool
    {
        return Schema::hasColumns('restaurants', [
            'restaurant_open_close_mode',
            'restaurant_manual_open_close_type',
            'manual_open_time',
            'manual_close_time',
            'is_temporarily_closed',
        ]);
    }
}
