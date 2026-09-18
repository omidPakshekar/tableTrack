<?php

namespace App\Http\Middleware;

use App\Models\LanguageSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /**
     * Apply locale and RTL direction before the response is rendered,
     * so the first HTML paint already has the correct dir/lang.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Authenticated app users always use their admin locale / RTL flag.
        if (auth()->check()) {
            $locale = session('locale') ?: (auth()->user()->locale ?: null);

            if (!$locale) {
                $locale = $this->globalLocale();
            }

            $locale = $locale ?: config('app.locale', 'en');
            App::setLocale($locale);
            session(['locale' => $locale]);

            if (!session()->has('isRtl')) {
                session(['isRtl' => $this->isLanguageRtl($locale)]);
            }

            return $next($request);
        }

        // Guests (landing, signup, shop): prefer customer locale when present.
        if (session()->has('customer_locale')) {
            $locale = session('customer_locale');
            App::setLocale($locale);

            if (!session()->has('customer_is_rtl')) {
                session(['customer_is_rtl' => $this->isLanguageRtl($locale)]);
            }

            return $next($request);
        }

        $locale = session('locale') ?: $this->globalLocale() ?: config('app.locale', 'en');
        App::setLocale($locale);

        if (!session()->has('isRtl')) {
            session(['isRtl' => $this->isLanguageRtl($locale)]);
        }

        if (!session()->has('customer_is_rtl')) {
            session(['customer_is_rtl' => $this->isLanguageRtl($locale)]);
        }

        return $next($request);
    }

    private function globalLocale(): ?string
    {
        try {
            return global_setting()?->locale;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isLanguageRtl(string $locale): bool
    {
       

        // Attempt DB lookup, but handle database errors gracefully.
        try {
            $language = \App\Models\LanguageSetting::where('language_code', $locale)->first();
            return (bool) ($language?->is_rtl == 1);
        } catch (\Throwable $e) {
            // Fallback RTL languages, avoiding DB lookup if DB connection is unavailable or during DB failures.
        // Extend this list as needed. Examples: Arabic, Hebrew, Persian, Urdu, etc.
        $rtlLocales = [
            'ar',      // Arabic
            'he',      // Hebrew
            'fa',      // Persian
            'ur',      // Urdu
            'dv',      // Divehi
            'ku',      // Kurdish (Sorani)
            'ps',      // Pashto
            'sd',      // Sindhi
            'ug',      // Uyghur
            'yi',      // Yiddish
        ];

        $localeCode = strtolower(explode('-', $locale)[0]); // handle "ar-SA" etc.

            if (in_array($localeCode, $rtlLocales, true)) {
                return true;
            }
        }
        return false;
    }
}
