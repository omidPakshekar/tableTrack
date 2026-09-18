<?php

namespace App\Livewire\DeliveryPortal;

use App\Models\LanguageSetting;
use Illuminate\Support\Facades\App;
use Livewire\Component;

class LanguageSwitcher extends Component
{
    public function setLanguage(string $locale): void
    {
        $language = LanguageSetting::where('language_code', $locale)
            ->where('active', 1)
            ->first();

        if (!$language) {
            return;
        }

        session([
            'delivery_locale' => $language->language_code,
            'customer_locale' => $language->language_code,
            'customer_site_language' => $language->language_code,
            'customer_is_rtl' => (bool) $language->is_rtl,
            'delivery_is_rtl' => (bool) $language->is_rtl,
        ]);

        session()->forget(['locale', 'is_rtl', 'isRtl']);
        App::setLocale($language->language_code);

        $this->js('window.location.reload()');
    }

    public function render()
    {
        $locale = session('delivery_locale') ?? session('customer_locale') ?? app()->getLocale();
        $activeLanguage = LanguageSetting::where('language_code', $locale)->first()
            ?? languages()->first();

        return view('livewire.delivery-portal.language-switcher', [
            'activeLanguage' => $activeLanguage,
        ]);
    }
}
