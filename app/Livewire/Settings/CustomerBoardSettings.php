<?php

namespace App\Livewire\Settings;

use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class CustomerBoardSettings extends Component
{
    use LivewireAlert;

    public $settings;
    public bool $showPaymentQr = true;

    public function mount()
    {
        $this->showPaymentQr = (bool) ($this->settings->show_payment_qr_on_customer_display ?? true);
    }

    public function submitForm(): void
    {
        $this->validate([
            'showPaymentQr' => 'required|boolean',
        ]);

        $this->settings->update([
            'show_payment_qr_on_customer_display' => $this->showPaymentQr,
        ]);

        session()->forget('restaurant');

        $this->alert('success', __('messages.settingsUpdated'), [
            'position' => 'top-end',
            'toast' => true,
        ]);
    }

    public function render()
    {
        return view('livewire.settings.customer-board-settings');
    }
}
