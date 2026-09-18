<?php

namespace App\Livewire\Settings;

use App\Models\NotificationSetting;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class NotificationSettings extends Component
{

    use LivewireAlert;
    
    public $notificationSettings;
    public $sendEmail;
    public $sendTime;

    public function mount()
    {
        $this->notificationSettings = NotificationSetting::get();
        $this->sendEmail = $this->notificationSettings->pluck('send_email')->toArray();
        $this->sendTime = $this->notificationSettings->pluck('send_time')->toArray();
    }

    public function submitForm()
    {
        foreach ($this->notificationSettings as $key => $notification) {
            $sendEmail = (bool) ($this->sendEmail[$key] ?? false);

            // Menu PDF emails are scheduled daily; require a time when enabled.
            $sendTime = $this->sendTime[$key] ?? null;
            if ($notification->type === 'menu_pdf_sent') {
                if ($sendEmail && blank($sendTime)) {
                    $this->alert('error', __('validation.required', ['attribute' => __('app.time')]), [
                        'toast' => true,
                        'position' => 'top-end',
                        'showCancelButton' => false,
                        'cancelButtonText' => __('app.close')
                    ]);
                    return;
                }

                $sendTime = $sendEmail ? $this->normalizeTime($sendTime) : null;
            } else {
                // For other notifications, keep send_time null.
                $sendTime = null;
            }

            $notification->update([
                'send_email' => $sendEmail,
                'send_time' => $sendTime,
            ]);
        }

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function render()
    {
        return view('livewire.settings.notification-settings');
    }

    private function normalizeTime(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // <input type="time"> usually yields "HH:MM" — normalize to "HH:MM:SS"
        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }

        return $value;
    }

}
