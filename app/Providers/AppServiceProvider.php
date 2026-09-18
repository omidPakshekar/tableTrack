<?php

namespace App\Providers;

use App\Models\Kot;
use App\Models\Tax;
use App\Models\Area;
use App\Models\Menu;
use App\Models\User;
use App\Models\Order;
use App\Models\Table;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expenses;
use App\Models\ExpensesRecurring;
use App\Models\KotPlace;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\FileStorage;
use App\Models\Reservation;
use App\Models\ItemCategory;
use App\Models\DeliveryPlatform;
use App\Observers\KotObserver;
use App\Observers\TaxObserver;
use App\Models\ExpenseCategory;
use App\Models\KotCancelReason;
use App\Observers\AreaObserver;
use App\Observers\MenuObserver;
use App\Observers\UserObserver;
use App\Observers\WaiterObserver;
use App\Models\RestaurantCharge;
use App\Observers\OrderObserver;
use App\Observers\TableObserver;
use App\Models\DeliveryExecutive;
use App\Observers\BranchObserver;
use App\Models\ReservationSetting;
use App\Observers\PaymentObserver;
use App\Observers\PrinterObserver;
use App\Models\NotificationSetting;
use App\Models\TableSession;
use App\Observers\TableSessionObserver;
use App\Observers\CurrencyObserver;
use App\Observers\CustomerObserver;
use App\Observers\ExpensesObserver;
use App\Observers\ExpensesRecurringObserver;
use App\Observers\KotPlaceObserver;
use App\Observers\MenuItemObserver;
use App\Observers\DeliveryPlatformObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use App\Observers\OrderItemObserver;
use Illuminate\Support\Facades\Gate;
use App\Observers\RestaurantObserver;
use App\Observers\FileStorageObserver;
use App\Observers\ReservationObserver;
use Illuminate\Support\Facades\Schema;
use App\Observers\ItemCategoryObserver;
use Illuminate\Support\ServiceProvider;
use App\Models\PaymentGatewayCredential;
use App\Observers\PaymentGatewayObserver;
use Illuminate\Database\Eloquent\Builder;
use App\Observers\ExpenseCategoryObserver;
use App\Observers\KotCancelReasonObserver;

use App\Observers\DeliveryExecutiveObserver;
use App\Observers\RestaurantChargesObserver;
use App\Observers\ReservationSettingObserver;

use Spatie\Translatable\Facades\Translatable;
use App\Observers\NotificationSettingObserver;

use App\Observers\OrderTypeObserver;
use App\Models\OrderType;
use App\Observers\ItemModifierObserver;
use App\Observers\KotItemObserver;
use App\Models\ItemModifier;
use App\Models\KotItem;
use App\Observers\PushNotificationObserver;
use App\Models\PushNotification;
use App\Observers\RefundReasonObserver;
use App\Models\RefundReason;
use Illuminate\Support\Carbon;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (config('app.redirect_https')) {
            $this->app['request']->server->set('HTTPS', true);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.redirect_https')) {
            URL::forceScheme('https');
        }

        // When CDN is enabled, use CDN URL only for Vite build assets (build/*) and vendor (e.g. Livewire); images stay on app URL
        if (config('app.asset_url')) {
            $this->app->extend('url', function ($current) {
                $ref = new \ReflectionClass($current);
                $routesProp = $ref->getProperty('routes');
                $routesProp->setAccessible(true);
                $requestProp = $ref->getProperty('request');
                $requestProp->setAccessible(true);
                return new \App\Routing\CdnAwareUrlGenerator(
                    $routesProp->getValue($current),
                    $requestProp->getValue($current),
                    null
                );
            });

            // Livewire uses config('livewire.asset_url') as the full script URL (it does not use asset()). Set it to CDN.
            $manifestPath = public_path('vendor/livewire/manifest.json');
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $id = $manifest['/livewire.js'] ?? '';
                $file = config('app.debug') ? 'livewire.js' : 'livewire.min.js';
                config([
                    'livewire.asset_url' => rtrim(config('app.asset_url'), '/') . '/vendor/livewire/' . $file . '?id=' . $id,
                ]);
            }
        }

        Schema::defaultStringLength(191);

        Carbon::macro('displayDate', function (?string $format = null) {
            return displayDate($this, $format);
        });

        Carbon::macro('displayDateTime', function (?string $dateFormat = null, ?string $timeFormat = null) {
            return displayDateTime($this, $dateFormat, $timeFormat);
        });

        // Link observers with models
        Area::observe(AreaObserver::class);
        User::observe([
            UserObserver::class,
            WaiterObserver::class,
        ]);
        ItemCategory::observe(ItemCategoryObserver::class);
        Kot::observe(KotObserver::class);
        KotItem::observe(KotItemObserver::class);
        ItemModifier::observe(ItemModifierObserver::class);
        MenuItem::observe(MenuItemObserver::class);
        Menu::observe(MenuObserver::class);
        OrderItem::observe(OrderItemObserver::class);
        Order::observe(OrderObserver::class);
        Payment::observe(PaymentObserver::class);
        ReservationSetting::observe(ReservationSettingObserver::class);
        Reservation::observe(ReservationObserver::class);
        Table::observe(TableObserver::class);
        PaymentGatewayCredential::observe(PaymentGatewayObserver::class);
        Tax::observe(TaxObserver::class);
        Currency::observe(CurrencyObserver::class);
        NotificationSetting::observe(NotificationSettingObserver::class);
        Customer::observe(CustomerObserver::class);
        Restaurant::observe(RestaurantObserver::class);
        Branch::observe(BranchObserver::class);
        FileStorage::observe(FileStorageObserver::class);
        DeliveryExecutive::observe(DeliveryExecutiveObserver::class);
        RestaurantCharge::observe(RestaurantChargesObserver::class);
        Expenses::observe(ExpensesObserver::class);
        ExpensesRecurring::observe(ExpensesRecurringObserver::class);
        ExpenseCategory::observe(ExpenseCategoryObserver::class);
        KotPlace::observe(KotPlaceObserver::class);
        Printer::observe(PrinterObserver::class);
        KotCancelReason::observe(KotCancelReasonObserver::class);
        OrderType::observe(OrderTypeObserver::class);
        DeliveryPlatform::observe(DeliveryPlatformObserver::class);
        TableSession::observe(TableSessionObserver::class);
        PushNotification::observe(PushNotificationObserver::class);
        RefundReason::observe(RefundReasonObserver::class);

        // Implicitly grant "Admin" role all permissions
        // This works in the app by using gate-related functions like auth()->user->can() and @can()
        Gate::before(function ($user, $ability) {
            return $user->hasRole('Admin_' . $user->restaurant_id) ? true : null;
        });

        // Search macro for searching in tables.
        Builder::macro('search', function ($field, $string) {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            return $string ? $this->where($field, 'like', '%' . $string . '%') : $this;
        });

        // Fallback to English if the locale is not found
        try {
            Translatable::fallback(global_setting()->locale, 'en');
        } catch (\Exception $e) {
            Log::error('Error in Translatable fallback: ' . $e->getMessage());
        }

        // Model::preventLazyLoading(app()->environment('development'));
    }
}
