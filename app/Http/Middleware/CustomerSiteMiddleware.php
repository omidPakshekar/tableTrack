<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Restaurant;
use App\Models\LanguageSetting;

class CustomerSiteMiddleware
{

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hash = $request->route('hash');

        $restaurant = null;

        if ($hash) {
            $restaurant = Restaurant::where('hash', $hash)->first();

            if ($restaurant && $restaurant->customer_site_language) {
                // Always prefer customer-site locale key. Fallback to legacy key once.
                $locale = session('customer_locale') ?: session('locale');

                if (!$locale) {
                    // First visit - use restaurant's customer_site_language directly
                    $locale = $restaurant->customer_site_language;
                }

                // Get RTL from selected language setting.
                $language = LanguageSetting::where('language_code', $locale)->first();
                $rtl = $language?->is_rtl ?? false;

                // Keep customer-site keys in sync for layouts/components.
                session([
                    'customer_locale' => $locale,
                    'customer_site_language' => $locale,
                    'customer_is_rtl' => $rtl,
                ]);

                // Clear unrelated/admin session keys to avoid conflicts.
                session()->forget(['locale', 'is_rtl', 'isRtl']);
                App::setLocale($locale);
            }

            // Get RTL from selected language setting.
            $language = LanguageSetting::where('language_code', $locale)->first();
            $rtl = $language?->is_rtl ?? false;

            // Keep customer-site keys in sync for layouts/components.
            session([
                'customer_locale' => $locale,
                'customer_site_language' => $locale,
                'customer_is_rtl' => $rtl,
            ]);

            // Clear unrelated/admin session keys to avoid conflicts.
            session()->forget(['locale', 'is_rtl', 'isRtl']);
            App::setLocale($locale);
        }

        return $next($request);
    }
}
