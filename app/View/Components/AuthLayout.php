<?php

namespace App\View\Components;

use App\Models\GlobalSetting;
use App\Models\LanguageSetting;
use Illuminate\Support\Facades\App;
use Illuminate\View\Component;
use Illuminate\View\View;

class AuthLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {

        // SAAS
        if (module_enabled('Subdomain')) {
            $restaurant = getRestaurantBySubDomain();
            $globalSetting = $restaurant ?? GlobalSetting::first();
        } else {
            $globalSetting = global_setting();
        }

        $appTheme = $globalSetting;

        // Restaurant models use customer_site_language; GlobalSetting uses locale.
        $locale = session('customer_locale')
            ?? ($globalSetting->locale ?? null)
            ?? ($globalSetting->customer_site_language ?? null)
            ?? global_setting()?->locale
            ?? 'en';

        App::setLocale($locale);
        session(['customer_locale' => $locale]);

        // Handle RTL for auth layout before the view reads dir=
        $language = LanguageSetting::where('language_code', $locale)->first();
        $isRtl = (bool) ($language?->is_rtl ?? false);
        session(['customer_is_rtl' => $isRtl]);

        return view('layouts.auth', [
            'globalSetting' => $globalSetting,
            'appTheme' => $appTheme,
        ]);
    }
}
