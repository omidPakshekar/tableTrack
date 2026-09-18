<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantImpersonationLog;
use App\Models\RestaurantImpersonationLogAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RestaurantImpersonationLogService
{
    public function startSession(User $superadmin, User $impersonated, Restaurant $restaurant): RestaurantImpersonationLog
    {
        return RestaurantImpersonationLog::create([
            'restaurant_id' => $restaurant->id,
            'superadmin_user_id' => $superadmin->id,
            'superadmin_name' => $superadmin->name,
            'superadmin_email' => $superadmin->email,
            'impersonated_user_id' => $impersonated->id,
            'impersonated_user_name' => $impersonated->name,
            'impersonated_user_email' => $impersonated->email,
            'started_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function endSession(int $logId): void
    {
        RestaurantImpersonationLog::query()
            ->where('id', $logId)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => now(),
                'end_ip_address' => request()->ip(),
            ]);
    }

    public function logRequest(int $logId, Request $request): void
    {
        if (! $this->shouldLogRequest($request)) {
            return;
        }

        foreach ($this->describeRequest($request) as $entry) {
            if ($this->isDuplicateAction($logId, $entry)) {
                continue;
            }

            RestaurantImpersonationLogAction::create([
                'restaurant_impersonation_log_id' => $logId,
                'method' => $entry['method'],
                'path' => $entry['path'],
                'route_name' => $entry['route_name'] ?? null,
                'description' => $entry['description'] ?? null,
                'created_at' => now(),
            ]);
        }
    }

    private function shouldLogRequest(Request $request): bool
    {
        if ($request->is(
            'livewire/livewire.js',
            'livewire/livewire.min.js',
            'vendor/*',
            'build/*',
            'storage/*',
            'up',
            'favicon.ico',
            'manifest.json'
        )) {
            return false;
        }

        $path = $request->path();

        if (preg_match('/\.(js|css|map|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i', $path)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, array{method: string, path: string, route_name?: ?string, description?: ?string}>
     */
    private function describeRequest(Request $request): array
    {
        if ($request->is('livewire/*')) {
            return $this->describeLivewireRequest($request);
        }

        $path = $this->buildLoggedPath($request);

        return [[
            'method' => $request->method(),
            'path' => $path,
            'route_name' => $request->route()?->getName(),
            'description' => $this->buildActivityDescription($request),
        ]];
    }

    /**
     * @return array<int, array{method: string, path: string, route_name?: ?string, description?: ?string}>
     */
    private function describeLivewireRequest(Request $request): array
    {
        $path = '/' . ltrim($request->path(), '/');
        $entries = [];

        foreach ($request->input('components', []) as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '{}', true);
            $componentName = $snapshot['memo']['name'] ?? 'Livewire';

            foreach ($component['calls'] ?? [] as $call) {
                $method = $call['method'] ?? 'unknown';
                $entries[] = [
                    'method' => 'POST',
                    'path' => $path,
                    'route_name' => null,
                    'description' => $componentName . '::' . $method,
                ];
            }
        }

        if ($entries !== []) {
            return $entries;
        }

        return [];
    }

    private function buildLoggedPath(Request $request): string
    {
        $path = '/' . ltrim($request->path(), '/');
        $query = $this->relevantQueryString($request);

        return $query === '' ? $path : $path . '?' . $query;
    }

    private function relevantQueryString(Request $request): string
    {
        $params = array_filter([
            'tab' => $request->query('tab'),
        ], fn ($value) => filled($value));

        return http_build_query($params);
    }

    private function buildActivityDescription(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if ($routeName === 'settings.index') {
            $tab = (string) $request->query('tab', 'restaurant');

            return __('menu.settings') . ': ' . $this->settingsTabLabel($tab);
        }

        if ($routeName === 'superadmin.superadmin-settings.index' && $request->query('tab')) {
            return __('menu.settings') . ': ' . $this->superadminSettingsTabLabel((string) $request->query('tab'));
        }

        if ($routeName) {
            return $this->moduleRouteLabel($routeName);
        }

        return null;
    }

    private function superadminSettingsTabLabel(string $tab): string
    {
        $labels = [
            'app' => 'modules.settings.appSettings',
            'email' => 'modules.settings.emailSettings',
            'language' => 'modules.settings.languageSettings',
            'payment' => 'modules.settings.paymentgatewaySettings',
            'theme' => 'modules.settings.themeSettings',
            'push' => 'modules.settings.notificationSettings',
            'currency' => 'modules.settings.currencySettings',
            'storage' => 'modules.settings.storageSettings',
            'webPushSetting' => 'modules.settings.webPushSetting',
            'role' => 'modules.settings.roleSettings',
            'desktop-app' => 'superadmin.desktopApplicationSettings',
        ];

        if (isset($labels[$tab])) {
            $translated = __($labels[$tab]);

            return $translated !== $labels[$tab] ? $translated : ucfirst($tab);
        }

        $moduleSlug = $this->routeNameToModuleSlug($tab);

        if ($moduleSlug) {
            $label = $this->resolveModuleLabel($moduleSlug, $tab);

            if ($label) {
                return $label;
            }
        }

        return ucfirst($tab);
    }

    private function settingsTabLabel(string $tab): string
    {
        $labels = [
            'restaurant' => 'restaurantSettings',
            'app' => 'appSettings',
            'operationalShifts' => 'operationalShifts',
            'restaurantOpenClose' => 'restaurantOpenCloseSettings',
            'branch' => 'branchSettings',
            'currency' => 'currencySettings',
            'email' => 'emailSettings',
            'tax' => 'taxSettings',
            'payment' => 'paymentgatewaySettings',
            'theme' => 'themeSettings',
            'role' => 'roleSettings',
            'billing' => 'billing',
            'reservation' => 'reservationSettings',
            'aboutus' => 'aboutUsSettings',
            'customerSite' => 'customerSiteSettings',
            'customerDisplay' => 'customerDisplaySettings',
            'receipt' => 'receiptSetting',
            'printer' => 'printerSetting',
            'downloads' => 'downloads',
            'menuItemImageSettings' => 'modules.menu.menuItemImageSettings',
            'deliverySettings' => 'deliverySettings',
            'euAllergens' => 'euAllergensFicTitle',
            'kotSettings' => 'kotSettings',
            'cancelSettings' => 'cancelSettings',
            'orderSettings' => 'orderSettings',
            'refundReasons' => 'refundReasons',
        ];

        if (isset($labels[$tab])) {
            $key = str_contains($labels[$tab], '.')
                ? $labels[$tab]
                : 'modules.settings.' . $labels[$tab];

            return __($key) !== $key ? __($key) : ucfirst($tab);
        }

        if (str_ends_with($tab, 'Settings')) {
            $moduleSlug = strtolower(substr($tab, 0, -strlen('Settings')));

            if ($this->isCustomModuleSlug($moduleSlug)) {
                $label = $this->resolveModuleLabel($moduleSlug, $tab);

                if ($label) {
                    return $label;
                }
            }
        }

        return ucfirst($tab);
    }

    private function moduleRouteLabel(string $routeName): ?string
    {
        $moduleSlug = $this->routeNameToModuleSlug($routeName);

        if (! $moduleSlug) {
            return null;
        }

        $pluginName = $this->pluginDisplayName($moduleSlug);
        $label = $this->resolveModuleRouteLabel($moduleSlug, $routeName);

        return $pluginName . ': ' . $label;
    }

    private function routeNameToModuleSlug(string $routeName): ?string
    {
        $prefix = strtolower(explode('.', $routeName)[0] ?? '');

        $aliases = [
            'multi-pos' => 'multipos',
            'cashregister' => 'cashregister',
            'cash-register' => 'cashregister',
        ];

        $normalizedPrefix = $aliases[$prefix] ?? $prefix;

        return $this->isCustomModuleSlug($normalizedPrefix) ? $normalizedPrefix : null;
    }

    private function resolveModuleLabel(string $moduleSlug, string $key): ?string
    {
        foreach ([
            "{$moduleSlug}::app.{$key}",
            "{$moduleSlug}::modules.settings.{$key}",
            'modules.settings.' . $key,
        ] as $translationKey) {
            $translated = __($translationKey);

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return null;
    }

    private function resolveModuleRouteLabel(string $moduleSlug, string $routeName): string
    {
        $segments = explode('.', $routeName);

        if (end($segments) === 'index' && count($segments) > 1) {
            array_pop($segments);
        }

        $candidates = [];

        if (count($segments) >= 2) {
            $combined = Str::camel(str_replace('-', '_', $segments[count($segments) - 2] . '_' . $segments[count($segments) - 1]));
            $candidates[] = "{$moduleSlug}::modules.menu.{$combined}";
        }

        $actionSegment = (string) end($segments);
        $camelKey = Str::camel(str_replace('-', '_', $actionSegment));

        $candidates[] = "{$moduleSlug}::modules.menu.{$camelKey}";
        $candidates[] = "{$moduleSlug}::app.{$camelKey}";
        $candidates[] = "{$moduleSlug}::modules.menu.{$actionSegment}";

        foreach (array_unique($candidates) as $translationKey) {
            $translated = __($translationKey);

            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        return ucwords(str_replace(['.', '-', '_'], ' ', $actionSegment));
    }

    /**
     * @return array<int, string>
     */
    private function customModuleSlugs(): array
    {
        if (! function_exists('custom_module_plugins')) {
            return [];
        }

        return array_map('strtolower', custom_module_plugins());
    }

    private function isCustomModuleSlug(string $slug): bool
    {
        return in_array(strtolower($slug), $this->customModuleSlugs(), true);
    }

    private function pluginDisplayName(string $moduleSlug): string
    {
        foreach (custom_module_plugins() as $plugin) {
            if (strtolower($plugin) === strtolower($moduleSlug)) {
                return $plugin;
            }
        }

        return ucfirst($moduleSlug);
    }

    /**
     * @param  array{method: string, path: string, route_name?: ?string, description?: ?string}  $entry
     */
    private function isDuplicateAction(int $logId, array $entry): bool
    {
        $lastAction = RestaurantImpersonationLogAction::query()
            ->where('restaurant_impersonation_log_id', $logId)
            ->latest('created_at')
            ->first();

        if (! $lastAction || ! $lastAction->created_at) {
            return false;
        }

        if ($lastAction->created_at->diffInSeconds(now()) > 45) {
            return false;
        }

        return $lastAction->path === $entry['path']
            && $lastAction->route_name === ($entry['route_name'] ?? null)
            && $lastAction->description === ($entry['description'] ?? null);
    }
}
