<?php

namespace App\Livewire\Settings;

use App\Models\DesktopApplication;
use Livewire\Component;

class DownloadSettings extends Component
{
    public function render()
    {
        $app = DesktopApplication::first();
        return view('livewire.settings.download-settings', ['app' => $app]);
    }
}
