<?php

namespace App\Livewire\Settings;

use App\Models\DesktopApplication;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class DesktopApplicationSettings extends Component
{
    use LivewireAlert;

    public $windows_file_path = '';

    public $mac_file_path = '';

    public $partner_app_ios = '';

    public $partner_app_android = '';

    public $waiter_pos_app_ios = '';

    public $waiter_pos_app_android = '';

    public $desktopApp;

    public string $initialSubtab = 'desktop';

    protected function rules(): array
    {
        return [
            'windows_file_path' => ['nullable', 'string', Rule::when(filled($this->windows_file_path), ['url'])],
            'mac_file_path' => ['nullable', 'string', Rule::when(filled($this->mac_file_path), ['url'])],
            'partner_app_ios' => ['nullable', 'string', 'max:2048', Rule::when(filled($this->partner_app_ios), ['url'])],
            'partner_app_android' => ['nullable', 'string', 'max:2048', Rule::when(filled($this->partner_app_android), ['url'])],
            'waiter_pos_app_ios' => ['nullable', 'string', 'max:2048', Rule::when(filled($this->waiter_pos_app_ios), ['url'])],
            'waiter_pos_app_android' => ['nullable', 'string', 'max:2048', Rule::when(filled($this->waiter_pos_app_android), ['url'])],
        ];
    }

    public function mount(): void
    {
        $this->initialSubtab = $this->normalizeSubtab(request('subtab', 'desktop'));
        $this->desktopApp = DesktopApplication::first();
        $this->loadExistingData();
    }

    private function normalizeSubtab(?string $subtab): string
    {
        if ($subtab === 'mobile') {
            return 'delivery-partner';
        }

        return in_array($subtab, ['desktop', 'delivery-partner', 'waiter-pos'], true)
            ? $subtab
            : 'desktop';
    }

    public function loadExistingData(): void
    {
        $this->desktopApp = DesktopApplication::first();

        if ($this->desktopApp) {
            $this->windows_file_path = $this->desktopApp->windows_file_path ?? DesktopApplication::WINDOWS_FILE_PATH;
            $this->mac_file_path = $this->desktopApp->mac_file_path ?? DesktopApplication::MAC_FILE_PATH;
            $this->partner_app_ios = $this->storedMobileAppUrl(
                $this->desktopApp->partner_app_ios,
                DesktopApplication::PARTNER_APP_IOS_URL
            );
            $this->partner_app_android = $this->storedMobileAppUrl(
                $this->desktopApp->partner_app_android,
                DesktopApplication::PARTNER_APP_ANDROID_URL
            );
            $this->waiter_pos_app_ios = $this->storedMobileAppUrl(
                $this->desktopApp->waiter_pos_app_ios,
                DesktopApplication::WAITER_POS_APP_IOS_URL
            );
            $this->waiter_pos_app_android = $this->storedMobileAppUrl(
                $this->desktopApp->waiter_pos_app_android,
                DesktopApplication::WAITER_POS_APP_ANDROID_URL
            );
        }
    }

    public function saveAll(): void
    {
        $this->validate();
        $app = DesktopApplication::first();

        if (! $app) {
            $app = DesktopApplication::create([]);
        }

        $app->windows_file_path = $this->windows_file_path;
        $app->mac_file_path = $this->mac_file_path;
        $app->partner_app_ios = $this->partner_app_ios;
        $app->partner_app_android = $this->partner_app_android;
        $app->waiter_pos_app_ios = $this->waiter_pos_app_ios;
        $app->waiter_pos_app_android = $this->waiter_pos_app_android;
        $app->save();

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
        $this->loadExistingData();
    }

    public function resetWindowsUrl(): void
    {
        $this->windows_file_path = DesktopApplication::WINDOWS_FILE_PATH;
    }

    public function resetMacUrl(): void
    {
        $this->mac_file_path = DesktopApplication::MAC_FILE_PATH;
    }

    /**
     * Null in DB = never configured (show default in form). Empty string = explicitly cleared.
     */
    private function storedMobileAppUrl(?string $stored, string $default): string
    {
        return $stored === null ? $default : $stored;
    }

    public function render()
    {
        $desktopApplication = DesktopApplication::first();

        return view('livewire.settings.desktop-application-settings', compact('desktopApplication'));
    }
}
