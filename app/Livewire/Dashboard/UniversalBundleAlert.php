<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Cache;

class UniversalBundleAlert extends Component
{
    public $showAlert = false;
    public $modules = [];
    public $universalBundleLink = 'https://codecanyon.net/item/universal-modules-bundle-for-tabletrack/60154227';

    public function mount()
    {
        if (!auth()->check()) {
            $this->showAlert = false;
            return;
        }

        // Check if user has dismissed the alert
        $userId = auth()->user()->id;
        $dismissed = Cache::get('universal_bundle_alert_dismissed_' . $userId, false);

        if ($dismissed) {
            $this->showAlert = false;
            return;
        }

        // Check if UniversalBundle module is installed
        $hasUniversalBundle = $this->checkUniversalBundleInstalled();

        if (!$hasUniversalBundle) {
            $this->showAlert = true;
            $this->loadAvailableModules();
        }
    }

    private function checkUniversalBundleInstalled()
    {
        $allModules = Module::all();

        foreach ($allModules as $module) {
            $configPath = base_path() . '/Modules/' . $module . '/Config/config.php';

            if (file_exists($configPath)) {
                $config = require $configPath;

                if (isset($config['name']) && stripos($config['name'], 'universal') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function loadAvailableModules()
    {
        try {
            $plugins = \Froiden\Envato\Functions\EnvatoUpdate::plugins();

            if (empty($plugins)) {
                $plugins = [];
            }

            $showModules = [];
            foreach ($plugins as $item) {
                if (!str_contains($item['product_name'], 'Universal')) {
                    $showModules[] = $item;
                }
            }

            $this->modules = $showModules;
        } catch (\Exception $e) {
            $this->modules = [];
        }
    }



    public function dismissAlert()
    {
        if (!auth()->check()) {
            return;
        }

        // Store dismissal permanently in cache (1 year)
        $userId = auth()->user()->id;
        Cache::put('universal_bundle_alert_dismissed_' . $userId, true, now()->addYear());

        $this->showAlert = false;
    }

    public function render()
    {
        return view('livewire.dashboard.universal-bundle-alert');
    }
}
