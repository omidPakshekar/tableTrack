<?php

namespace App\Livewire\Settings;

use App\Models\KotSetting;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class KotSettings extends Component
{
    use LivewireAlert;

    public $kotSettings;
    public $enableItemLevelStatus;
    public $defaultKotStatus;
    public $defaultCustomerKotStatus;

    public function mount()
    {
        $this->kotSettings = KotSetting::first();

        if (!$this->kotSettings) {
            // Create default settings if they don't exist
            $this->kotSettings = KotSetting::create([
                'branch_id' => branch()->id,
                'default_status_pos' => 'pending',
                'default_status_customer' => 'pending',
                'enable_item_level_status' => true,
            ]);
        }

        $this->enableItemLevelStatus = (bool) $this->kotSettings->enable_item_level_status;
        $this->defaultKotStatus = $this->kotSettings->default_status_pos ?? 'pending';
        $this->defaultCustomerKotStatus = $this->kotSettings->default_status_customer ?? 'pending';
    }

    public function setPosStatus($status)
    {
        $this->defaultKotStatus = $status;
    }

    public function setCustomerStatus($status)
    {
        $this->defaultCustomerKotStatus = $status;
    }

    public function submitForm()
    {
        $this->kotSettings->update([
            'enable_item_level_status' => $this->enableItemLevelStatus,
            'default_status_pos' => $this->defaultKotStatus,
            'default_status_customer' => $this->defaultCustomerKotStatus,
        ]);

        // Refresh the settings to ensure we have the latest data
        $this->kotSettings->refresh();

        // Refresh all properties from the database to maintain consistency
        $this->enableItemLevelStatus = (bool) $this->kotSettings->enable_item_level_status;
        $this->defaultKotStatus = $this->kotSettings->default_status_pos ?? 'pending';
        $this->defaultCustomerKotStatus = $this->kotSettings->default_status_customer ?? 'pending';

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.settings.kot-settings');
    }
}
