<?php

namespace App\Livewire\Settings;

use Livewire\Component;
use App\Models\Role;
use App\Models\OrderNotificationSetting as OrderNotificationSettingModel;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class OrderNotificationSetting extends Component
{
    use LivewireAlert;
    public $roles;
    public $hideNewOrderNotification = [];

    public function mount()
    {
        // Get the current restaurant
        $restaurant = restaurant();

        $this->roles = $restaurant ? Role::where('restaurant_id', $restaurant->id)->get() : collect();

        if ($restaurant && $this->roles->isNotEmpty()) {
            $settings = OrderNotificationSettingModel::where('restaurant_id', $restaurant->id)->get();

            $this->hideNewOrderNotification = $settings
                ->pluck('hide_new_order_notification', 'role_id')
                ->map(fn ($value) => (bool) $value)
                ->toArray();
        }
    }

    public function saveOrderNotificationSettings(): void
    {
        $restaurant = restaurant();

        if (! $restaurant) {
            return;
        }

        foreach ($this->roles as $role) {
            $isHidden = !empty($this->hideNewOrderNotification[$role->id]);

            if ($isHidden) {
                OrderNotificationSettingModel::updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'role_id' => $role->id,
                    ],
                    [
                        'hide_new_order_notification' => true,
                    ]
                );
            } else {
                OrderNotificationSettingModel::where('restaurant_id', $restaurant->id)
                    ->where('role_id', $role->id)
                    ->delete();
            }
        }

        $this->alert('success', __('messages.settingsUpdated'), [
            'position' => 'top-end',
            'toast' => true,
        ]);
    }

    public function render()
    {
        return view('livewire.settings.order-notification-setting');
    }
}
