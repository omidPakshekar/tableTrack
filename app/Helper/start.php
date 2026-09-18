<?php

use App\Models\EmailSetting;
use App\Models\GlobalSetting;
use App\Models\LanguageSetting;
use App\Helper\Files;
use App\Models\Package;
use App\Models\PaymentGatewayCredential;
use App\Models\PusherSetting;
use App\Models\Restaurant;
use App\Models\StorageSetting;
use App\Models\SuperadminPaymentGateway;
use App\Models\GlobalCurrency;
use App\Models\Currency;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use App\Models\OrderNumberSetting;
use Intervention\Image\Facades\Image;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Branch;
use App\Models\BranchOperationalShift;
use App\Models\DeliveryExecutive;
use App\Support\Jalali;
use Carbon\Carbon;


if (!function_exists('user')) {

    /**
     * Return current logged-in user
     */
    function user()
    {

        if (session()->has('user')) {
            return session('user');
        }


        session(['user' => auth()->user()]);

        return session('user');
    }
}


function customer()
{
    if (session()->has('customer')) {
        return session('customer');
    }

    return null;
}

function delivery_executive()
{
    if (session()->has('delivery_executive')) {
        return session('delivery_executive');
    }

    if (session()->has('delivery_executive_id')) {
        $executive = DeliveryExecutive::with('branch.restaurant.currency')
            ->find(session('delivery_executive_id'));

        if ($executive) {
            session(['delivery_executive' => $executive]);
            return $executive;
        }
    }

    return null;
}

function restaurant()
{
    if (session()->has('restaurant')) {
        return session('restaurant');
    }

    if (user()) {
        if (user()->restaurant_id) {
            session(['restaurant' => Restaurant::find(user()->restaurant_id)]);
            return session('restaurant');
        }
    }

    // session(['restaurant' => Restaurant::first()]); // Used in Non-saas

    // return session('restaurant');  // Used in Non-saas
    return false;  // Used in Saas

}

function shop($hash = null)
{
    if (session()->has('shop')) {
        return session('shop');
    }

    if (!is_null($hash)) {
        session(['shop' => Restaurant::where('hash', $hash)->first()]);
        return session('shop');
    }

    return false;  // Used in Saas

}

function branch()
{
    if (session()->has('branch')) {
        return session('branch');
    }

    if (restaurant()) {
        session(['branch' => user()->branch ?? restaurant()->branches->first()]);
        return session('branch');
    }

    return false;
}

function shop_branch()
{
    if (session()->has('shop_branch')) {
        return session('shop_branch');
    }

    if (shop()) {
        session(['shop_branch' => shop()->branches->first()]);
        return session('shop_branch');
    }

    return false;
}

function currency()
{
    if (session()->has('currency')) {
        return session('currency');
    }

    if (restaurant()) {
        session(['currency' => restaurant()->currency->currency_symbol]);

        return session('currency');
    }

    return false;
}

function dateFormat()
{
    static $memo = null;

    if ($memo !== null) {
        return $memo;
    }

    if (session()->has('date_format')) {
        return $memo = session('date_format');
    }

    $user = auth()->user();
    if ($user && $user->restaurant_id) {
        $restaurant = restaurant();
        if (! $restaurant || (int) $restaurant->id !== (int) $user->restaurant_id) {
            $restaurant = Restaurant::find($user->restaurant_id);
            if ($restaurant) {
                session(['restaurant' => $restaurant]);
            }
        }

        if ($restaurant && $restaurant->date_format) {
            session(['date_format' => $restaurant->date_format]);

            return $memo = $restaurant->date_format;
        }
    }

    $globalSetting = global_setting();
    if ($globalSetting && $globalSetting->date_format) {
        session(['date_format' => $globalSetting->date_format]);

        return $memo = $globalSetting->date_format;
    }

    return $memo = 'd/m/Y';
}

function timeFormat()
{
    static $memo = null;

    if ($memo !== null) {
        return $memo;
    }

    if (session()->has('time_format')) {
        return $memo = session('time_format');
    }

    $user = auth()->user();
    if ($user && $user->restaurant_id) {
        $restaurant = restaurant();
        if (! $restaurant || (int) $restaurant->id !== (int) $user->restaurant_id) {
            $restaurant = Restaurant::find($user->restaurant_id);
            if ($restaurant) {
                session(['restaurant' => $restaurant]);
            }
        }

        if ($restaurant && $restaurant->time_format) {
            session(['time_format' => $restaurant->time_format]);

            return $memo = $restaurant->time_format;
        }
    }

    $globalSetting = global_setting();
    if ($globalSetting && $globalSetting->time_format) {
        session(['time_format' => $globalSetting->time_format]);

        return $memo = $globalSetting->time_format;
    }

    return $memo = 'h:i A';
}

if (!function_exists('usesJalaliCalendar')) {
    function usesJalaliCalendar(): bool
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        if (app()->getLocale() === 'fa') {
            return $memo = true;
        }

        if (session()->get('locale') === 'fa') {
            return $memo = true;
        }

        if (user() && user()->locale === 'fa') {
            return $memo = true;
        }

        return $memo = false;
    }
}

if (!function_exists('displayDate')) {
    function displayDate($date, ?string $format = null): string
    {
        if (!$date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $format = $format ?? dateFormat();

        if (usesJalaliCalendar()) {
            return Jalali::fromCarbon($carbon)->format($format, $carbon);
        }

        return $carbon->translatedFormat($format);
    }
}

if (!function_exists('displayDateTime')) {
    function displayDateTime($date, ?string $dateFormat = null, ?string $timeFormat = null): string
    {
        $dateFormat = $dateFormat ?? dateFormat();
        $timeFormat = $timeFormat ?? timeFormat();

        return displayDate($date, trim($dateFormat . ' ' . $timeFormat));
    }
}

if (!function_exists('parseRestaurantDate')) {
    function parseRestaurantDate(string $date, ?string $format = null, ?string $timezone = null): Carbon
    {
        $format = $format ?? dateFormat();
        $timezone = $timezone ?? timezone();

        if (usesJalaliCalendar()) {
            $jalali = Jalali::fromFormat($date, $format);

            if ($jalali) {
                return $jalali->toCarbon($timezone);
            }
        }

        return Carbon::createFromFormat($format, $date, $timezone);
    }
}

if (!function_exists('parseRestaurantDateTime')) {
    function parseRestaurantDateTime(string $value, string $format, ?string $timezone = null): Carbon
    {
        $timezone = $timezone ?? timezone();

        if (!usesJalaliCalendar()) {
            return Carbon::createFromFormat($format, $value, $timezone);
        }

        if (str_contains($format, ' H:i:s')) {
            $dateFormatPart = str_replace(' H:i:s', '', $format);
            $parts = preg_split('/\s+/', trim($value), 2);
            $date = parseRestaurantDate($parts[0], $dateFormatPart ?: dateFormat(), $timezone);

            if (isset($parts[1])) {
                $time = Carbon::createFromFormat('H:i:s', $parts[1], $timezone);

                return $date->setTime($time->hour, $time->minute, $time->second);
            }

            return $date;
        }

        if (str_contains($format, ' H:i')) {
            $dateFormatPart = str_replace(' H:i', '', $format);
            $parts = preg_split('/\s+/', trim($value), 2);
            $date = parseRestaurantDate($parts[0], $dateFormatPart ?: dateFormat(), $timezone);

            if (isset($parts[1])) {
                $time = Carbon::createFromFormat('H:i', $parts[1], $timezone);

                return $date->setTime($time->hour, $time->minute, 0);
            }

            return $date;
        }

        return parseRestaurantDate($value, $format, $timezone);
    }
}

if (!function_exists('restaurantToYmd')) {
    /**
     * Convert date from restaurant/global date format to Y-m-d format
     *
     * @param string $date
     * @return string
     */
    function restaurantToYmd($date)
    {
        try {
            if (usesJalaliCalendar()) {
                $jalali = Jalali::fromFormat((string) $date, dateFormat());

                if ($jalali) {
                    return $jalali->toCarbon()->format('Y-m-d');
                }
            }

            return Carbon::createFromFormat(dateFormat(), $date)->format('Y-m-d');
        } catch (\Exception $e) {
            try {
                return Carbon::parse($date)->format('Y-m-d');
            } catch (\Exception $e) {
                return $date;
            }
        }
    }
}

if (!function_exists('restaurantToDateString')) {
    /**
     * Convert date from restaurant/global date format to date string (Y-m-d)
     *
     * @param string $date
     * @return string
     */
    function restaurantToDateString($date)
    {
        try {
            if (usesJalaliCalendar()) {
                $jalali = Jalali::fromFormat((string) $date, dateFormat());

                if ($jalali) {
                    return $jalali->toCarbon()->toDateString();
                }
            }

            return Carbon::createFromFormat(dateFormat(), $date)->toDateString();
        } catch (\Exception $e) {
            try {
                return Carbon::parse($date)->toDateString();
            } catch (\Exception $e) {
                return $date;
            }
        }
    }
}

function timezone()
{
    // Prefer live restaurant setting over a possibly stale session value.
    // (Session can remain UTC after timezone is changed via DB/admin/import.)
    if (restaurant()) {
        $tz = restaurant()->timezone ?: 'UTC';
        if (session('timezone') !== $tz) {
            session(['timezone' => $tz]);
        }

        return $tz;
    }

    if (shop()) {
        $tz = shop()->timezone ?: 'UTC';
        if (session('timezone') !== $tz) {
            session(['timezone' => $tz]);
        }

        return $tz;
    }

    // For superadmin, use global setting timezone
    if (user() && is_null(user()->restaurant_id)) {
        $globalTimezone = global_setting()->timezone ?? 'UTC';
        session(['timezone' => $globalTimezone]);

        return session('timezone');
    }

    if (session()->has('timezone')) {
        return session('timezone');
    }

    return 'UTC';
}

function paymentGateway()
{
    if (session()->has('paymentGateway')) {
        return session('paymentGateway');
    }

    if (shop()) {
        $payment = PaymentGatewayCredential::where('restaurant_id', shop()->id)->first();

        session(['paymentGateway' => $payment]);

        return session('paymentGateway');
    }

    return false;
}

if (!function_exists('check_migrate_status')) {

    // @codingStandardsIgnoreLine
    function check_migrate_status()
    {

        if (!session()->has('check_migrate_status')) {

            $status = Artisan::call('migrate:check');

            if ($status && !request()->ajax()) {
                Artisan::call('migrate', ['--force' => true, '--schema-path' => 'do not run schema path']); // Migrate database
                Artisan::call('optimize:clear');
            }

            session(['check_migrate_status' => 'Good']);
        }

        return session('check_migrate_status');
    }
}

if (!function_exists('role_permissions')) {

    function role_permissions()
    {
        $user = user();
        if (is_null($user)) {
            return [];
        }

        $userId = $user->id;
        
        // Check if permissions are cached for this user
        if (cache()->has('role_permissions_' . $userId)) {
            return cache()->get('role_permissions_' . $userId);
        }

        $roleId = $user->roles->first()->id;
        if (!$roleId) {
            return [];
        }

        // Load permissions from database
        $permissions = Role::where('id', $roleId)
            ->first()->permissions->pluck('name')->toArray();

        // Cache permissions for this user
        cache()->put('role_permissions_' . $userId, $permissions);
        
        return $permissions;
    }
}

if (!function_exists('user_can')) {

    function user_can($permission)
    {
        $rolePermissions = role_permissions() ?? [];

        return in_array($permission, $rolePermissions);
    }
}

if (!function_exists('restaurant_modules')) {
    function restaurant_modules($restaurant = null): array
    {
        $restaurant = $restaurant ?: restaurant();

        if (!$restaurant) {
            return [];
        }

        $cacheKey = 'restaurant_modules_' . $restaurant->id;

        if (cache()->has($cacheKey)) {
            return cache($cacheKey);
        }

        $user = user();

        if ($user && is_null($user->restaurant_id) && is_null($user->branch_id)) {
            return [];
        }

        $restaurant = Restaurant::with('package.modules')->find($restaurant->id);
        session(['restaurant' => $restaurant]);

        $package = $restaurant->package;

        $packageModules = $package->modules->pluck('name')->toArray();
        $additionalFeatures = json_decode($package->additional_features ?? '[]', true);

        $allModules = array_unique(array_merge($packageModules, $additionalFeatures));

        cache([$cacheKey => $allModules]);

        return cache($cacheKey);
    }
}


if (!function_exists('global_setting')) {

    // @codingStandardsIgnoreLine
    function global_setting()
    {

        if (cache()->has('global_setting')) {
            return cache('global_setting');
        }

        cache(['global_setting' => GlobalSetting::first()]);

        return cache('global_setting');
    }
}

if (!function_exists('restaurantOrGlobalSetting')) {

    function restaurantOrGlobalSetting()
    {
        if (user()) {

            if (user()->restaurant_id) {
                return restaurant();
            }
        }

        return global_setting();
    }
}

if (!function_exists('branches')) {

    function branches()
    {

        if (session()->has('branches')) {
            return session('branches');
        }

        if (restaurant()) {
            return session(['branches' => restaurant()->branches]);
        }

        return false;
    }
}

if (!function_exists('isRtl')) {

    function isRtl()
    {
        if (session()->has('isRtl')) {
            return (bool) session('isRtl');
        }

        $locale = null;

        if (user()) {
            $locale = auth()->user()->locale;
        } else {
            try {
                $locale = session('locale') ?? global_setting()?->locale;
            } catch (\Exception $e) {
                $locale = session('locale');
            }
        }

        if ($locale) {
            $language = LanguageSetting::where('language_code', $locale)->first();
            $isRtl = (bool) ($language?->is_rtl == 1);
            session(['isRtl' => $isRtl]);

            return $isRtl;
        }

        return false;
    }
}

if (!function_exists('languages')) {

    function languages()
    {

        if (cache()->has('languages')) {
            return cache('languages');
        }

        $languages = LanguageSetting::where('active', 1)->get();
        cache(['languages' => $languages]);

        return cache('languages');
    }
}

if (!function_exists('asset_url_local_s3')) {

    // @codingStandardsIgnoreLine
    function asset_url_local_s3($path)
    {
        if (in_array(config('filesystems.default'), StorageSetting::S3_COMPATIBLE_STORAGE)) {
            // Check if the URL is already cached
            if (Cache::has(config('filesystems.default') . '-' . $path)) {
                $temporaryUrl = Cache::get(config('filesystems.default') . '-' . $path);
            } else {
                // Generate a new temporary URL and cache it
                $temporaryUrl = Storage::disk(config('filesystems.default'))->temporaryUrl($path, now()->addMinutes(StorageSetting::HASH_TEMP_FILE_TIME));
                Cache::put(config('filesystems.default') . '-' . $path, $temporaryUrl, StorageSetting::HASH_TEMP_FILE_TIME * 60);
            }

            return $temporaryUrl;
        }

        $path = Files::UPLOAD_FOLDER . '/' . $path;
        $storageUrl = $path;

        if (!Str::startsWith($storageUrl, 'http')) {
            return url($storageUrl);
        }

        return $storageUrl;
    }
}

if (!function_exists('download_local_s3')) {

    // @codingStandardsIgnoreLine
    function download_local_s3($file, $path)
    {

        if (in_array(config('filesystems.default'), StorageSetting::S3_COMPATIBLE_STORAGE)) {
            return Storage::disk(config('filesystems.default'))->download($path, basename($file->filename));
        }

        $path = Files::UPLOAD_FOLDER . '/' . $path;
        $ext = pathinfo($file->filename, PATHINFO_EXTENSION);

        $filename = $file->name ? $file->name . '.' . $ext : $file->filename;
        try {
            return response()->download($path, $filename);
        } catch (\Exception $e) {
            return response()->view('errors.file_not_found', ['message' => $e->getMessage()], 404);
        }
    }
}


if (!function_exists('asset_url')) {

    // @codingStandardsIgnoreLine
    function asset_url($path)
    {
        $path = \App\Helper\Files::UPLOAD_FOLDER . '/' . $path;
        $storageUrl = $path;

        if (!Str::startsWith($storageUrl, 'http')) {
            return url($storageUrl);
        }

        return $storageUrl;
    }
}

if (!function_exists('getDomain')) {

    function getDomain($host = false)
    {
        if (!$host) {
            $host = $_SERVER['SERVER_NAME'] ?? 'tabletrack.test';
        }

        $shortDomain = config('app.short_domain_name');
        $dotCount = ($shortDomain === true) ? 2 : 1;

        $myHost = strtolower(trim($host));
        $count = substr_count($myHost, '.');

        if (!is_null(config('app.main_domain_name'))) {
            return config('app.main_domain_name');
        }

        if ($count === $dotCount || $count === 1) {
            return $myHost;
        }

        $myHost = explode('.', $myHost, 2);

        return end($myHost);
    }
}

if (!function_exists('getDomainSpecificUrl')) {

    function getDomainSpecificUrl($url, $restaurant = null)
    {
        // Check if Subdomain module exist
        if (!module_enabled('Subdomain')) {
            return $url;
        }

        config(['app.url' => config('app.main_app_url')]);

        // If restaurant specific
        if ($restaurant) {
            $restaurantUrl = (config('app.redirect_https') ? 'https' : 'http') . '://' . $restaurant->sub_domain;

            config(['app.url' => $restaurantUrl]);
            // Removed Illuminate\Support\Facades\URL::forceRootUrl($companyUrl);

            if (Str::contains($url, $restaurant->sub_domain)) {
                return $url;
            }

            $url = str_replace(request()->getHost(), $restaurant->sub_domain, $url);
            $url = str_replace('www.', '', $url);

            // Replace https to http for sub-domain to
            if (!config('app.redirect_https')) {
                return str_replace('https', 'http', $url);
            }

            return $url;
        }

        // Removed config(['app.url' => $url]);
        // Comment      \Illuminate\Support\Facades\URL::forceRootUrl($url);
        // If there is no restaurant and url has login means
        // New superadmin is created
        return str_replace('login', 'super-admin-login', $url);
    }
}


function module_enabled($moduleName)
{
    return Module::has($moduleName) && Module::isEnabled($moduleName);
}

if (!function_exists('isHotelModuleEnabled')) {
    /**
     * Check if Hotel module is enabled and available in restaurant modules
     * 
     * @return bool
     */
    function isHotelModuleEnabled(?\App\Models\Restaurant $restaurant = null): bool
    {
        if (! function_exists('module_enabled') || ! module_enabled('Hotel')) {
            return false;
        }

        if (! function_exists('restaurant_modules')) {
            return false;
        }

        return in_array('Hotel', restaurant_modules($restaurant), true);
    }
}

if (!function_exists('package')) {

    function package()
    {

        if (cache()->has('package')) {
            return cache('package');
        }

        $package = Package::first();

        cache(['package' => $package]);

        return cache('package');
    }
}

function superadminPaymentGateway()
{
    if (cache()->has('superadminPaymentGateway')) {
        return cache('superadminPaymentGateway');
    }

    $payment = SuperadminPaymentGateway::first();

    cache(['superadminPaymentGateway' => $payment]);

    return cache('superadminPaymentGateway');
}


function pusherSettings()
{


    if (cache()->has('pusherSettings')) {
        return cache('pusherSettings');
    }

    $setting = PusherSetting::first();

    cache(['pusherSettings' => $setting]);

    return cache('pusherSettings');
}

if (!function_exists('clearRestaurantModulesCache')) {

    function clearRestaurantModulesCache($restaurantId)
    {
        if (is_null($restaurantId)) {
            return true;
        }

        cache()->forget('restaurant_modules_' . $restaurantId);
    }
}

if (!function_exists('currency_format_setting')) {

    // @codingStandardsIgnoreLine
    function currency_format_setting($currencyId = null)
    {
        if (!session()->has('currency_format_setting' . $currencyId) || (is_null($currencyId) && restaurant())) {
            if ($currencyId == null && restaurant()) {
                $setting = restaurant()->load('currency')->currency;
            } else {
                $setting = Currency::where('id', $currencyId)->first();
            }
            session(['currency_format_setting' . $currencyId => $setting]);
        }

        return session('currency_format_setting' . $currencyId);
    }
}

if (!function_exists('currency_format')) {

    // @codingStandardsIgnoreLine
    function currency_format($amount, $currencyId = null, $showSymbol = true, $showCode = false, $decimals = null)
    {
        $formats = currency_format_setting($currencyId);

        $settings = $formats->restaurant ?? Restaurant::find($formats->restaurant_id);

        if ($showCode) {
            $currency_symbol = $formats->currency_code ?? '';
        } else {
            if (!$showSymbol) {
                $currency_symbol = '';
            } else {
                $settings = $formats->restaurant ?? Restaurant::find($formats->restaurant_id);
                $currency_symbol = $currencyId == null ? $settings->currency->currency_symbol : $formats->currency_symbol;
            }
        }


        $currency_position = $formats->currency_position ?? 'left';
        $no_of_decimal = $decimals !== null
            ? (int) $decimals
            : (!is_null($formats->no_of_decimal) ? $formats->no_of_decimal : '0');
        $thousand_separator = !is_null($formats->thousand_separator) ? $formats->thousand_separator : '';
        $decimal_separator = !is_null($formats->decimal_separator) ? $formats->decimal_separator : '0';

        $amount = number_format(floatval($amount), $no_of_decimal, $decimal_separator, $thousand_separator);

        $amount = match ($currency_position) {
            'right' => $amount . $currency_symbol,
            'left_with_space' => $currency_symbol . ' ' . $amount,
            'right_with_space' => $amount . ' ' . $currency_symbol,
            default => $currency_symbol . $amount,
        };

        return $amount;
    }
}

if (!function_exists('currency_format_denomination')) {

    /**
     * Format money for denomination face values / count subtotals.
     * Uses at least the restaurant currency decimals, and up to 3 when needed (e.g. 0.025).
     */
    // @codingStandardsIgnoreLine
    function currency_format_denomination($amount, $currencyId = null, $showSymbol = true)
    {
        $formats = currency_format_setting($currencyId);
        $baseDecimals = !is_null($formats->no_of_decimal) ? (int) $formats->no_of_decimal : 2;
        $fraction = explode('.', number_format(abs((float) $amount), 3, '.', ''))[1] ?? '';
        $fraction = rtrim($fraction, '0');
        $decimals = max($baseDecimals, strlen($fraction));

        return currency_format($amount, $currencyId, $showSymbol, false, $decimals);
    }
}


if (!function_exists('global_currency_format_setting')) {

    // @codingStandardsIgnoreLine
    function global_currency_format_setting($currencyId = null)
    {
        if (!session()->has('global_currency_format_setting' . $currencyId)) {
            $setting = $currencyId == null ? GlobalCurrency::first() : GlobalCurrency::where('id', $currencyId)->first();
            session(['global_currency_format_setting' . $currencyId => $setting]);
        }

        return session('global_currency_format_setting' . $currencyId);
    }
}

if (!function_exists('global_currency_format')) {

    // @codingStandardsIgnoreLine
    function global_currency_format($amount, $currencyId = null, $showSymbol = true)
    {
        $formats = global_currency_format_setting($currencyId);


        if (!$showSymbol) {
            $currency_symbol = '';
        } else {
            $currency_symbol = $formats->currency_symbol;
        }

        $currency_position = $formats->currency_position;
        $no_of_decimal = !is_null($formats->no_of_decimal) ? $formats->no_of_decimal : '0';
        $thousand_separator = !is_null($formats->thousand_separator) ? $formats->thousand_separator : '';
        $decimal_separator = !is_null($formats->decimal_separator) ? $formats->decimal_separator : '0';

        $amount = number_format($amount, $no_of_decimal, $decimal_separator, $thousand_separator);

        $amount = match ($currency_position) {
            'right' => $amount . $currency_symbol,
            'left_with_space' => $currency_symbol . ' ' . $amount,
            'right_with_space' => $amount . ' ' . $currency_symbol,
            default => $currency_symbol . $amount,
        };

        return $amount;
    }
}

if (!function_exists('smtp_setting')) {

    // @codingStandardsIgnoreLine
    function smtp_setting()
    {
        if (!session()->has('smtp_setting')) {
            session(['smtp_setting' => EmailSetting::first()]);
        }

        return session('smtp_setting');
    }
}

if (!function_exists('custom_module_plugins')) {

    // @codingStandardsIgnoreLine
    function custom_module_plugins()
    {

        if (!cache()->has('custom_module_plugins')) {
            $plugins = \Nwidart\Modules\Facades\Module::allEnabled();
            cache(['custom_module_plugins' => array_keys($plugins)]);
        }

        return cache('custom_module_plugins');
    }
}

if (!function_exists('isOrderPrefixEnabled')) {

    /**
     * Check if order prefix feature is enabled for the given branch
     */
    function isOrderPrefixEnabled($branch = null)
    {
        if (!$branch) {
            $branch = branch();
        }

        if (!$branch) {
            return false;
        }

        $settings = getOrderNumberSetting($branch->id);
        return $settings && $settings->enable_feature;
    }
}

if (!function_exists('getOrderNumberSetting')) {
    function getOrderNumberSetting($branchId) {
        return cache()->remember('order_number_setting_' . $branchId, 60 * 60 * 24, function () use ($branchId) {
            return OrderNumberSetting::where('branch_id', $branchId)->first() ?? new OrderNumberSetting();
        });
    }
}

if (!function_exists('getRestaurantStaffStats')) {
    /**
     * Get restaurant staff stats including staff_limit and current staff count.
     *
     * @param int|null $restaurantId
     * @return array|null
     */
    function getRestaurantStaffStats($restaurantId = null)
    {
        if (is_null($restaurantId)) {
            return null;
        }

        return cache()->rememberForever('restaurant_' . $restaurantId . '_staff_stats', function () use ($restaurantId) {
            $restaurant = Restaurant::with('package')->find($restaurantId);

            if (!$restaurant || !$restaurant->package) {
                return [
                    'staff_limit' => 0,
                    'current_count' => 0,
                    'unlimited' => false,
                ];
            }

            $staffLimit = $restaurant->package->staff_limit;
            $currentStaffCount = $restaurant->users()->count();

            return [
                'staff_limit' => $staffLimit,
                'current_count' => $currentStaffCount,
                'unlimited' => ($staffLimit == -1),
            ];
        });
    }
}

if (!function_exists('getRestaurantMenuItemStats')) {
    /**
     * Get restaurant menu item stats including menu_items_limit and current menu item count.
     * Counts menu items across all branches of the restaurant.
     *
     * @param int|null $restaurantId
     * @return array|null
     */
    function getRestaurantMenuItemStats($restaurantId = null)
    {
        if (is_null($restaurantId)) {
            return null;
        }

        return cache()->rememberForever('restaurant_' . $restaurantId . '_menu_item_stats', function () use ($restaurantId) {
            $restaurant = Restaurant::with('package', 'branches')->find($restaurantId);

            if (!$restaurant || !$restaurant->package) {
                return [
                    'menu_items_limit' => 0,
                    'current_count' => 0,
                    'unlimited' => false,
                ];
            }

            $menuItemsLimit = $restaurant->package->menu_items_limit;

            // Get all branch IDs for this restaurant
            $branchIds = $restaurant->branches->pluck('id')->toArray();

            // Count menu items across all branches
            $currentMenuItemCount = MenuItem::whereIn('branch_id', $branchIds)->count();

            return [
                'menu_items_limit' => $menuItemsLimit,
                'current_count' => $currentMenuItemCount,
                'unlimited' => ($menuItemsLimit == -1),
            ];
        });
    }
}

if (!function_exists('getRestaurantOrderStats')) {
    /**
     * Get order stats including order_limit and current order count for the current branch.
     * Uses branch's total_orders and count_orders fields.
     *
     * @param int|null $branchId (kept for backward compatibility, but uses current branch)
     * @return array|null
     */
    function getRestaurantOrderStats($branchId = null)
    {
        if (is_null($branchId)) {
            return null;
        }

        return cache()->rememberForever('branch_' . $branchId . '_order_stats', function () use ($branchId) {
            $branch = Branch::find($branchId);

            if (!$branch) {
                return [
                    'order_limit' => 0,
                    'current_count' => 0,
                    'unlimited' => false,
                ];
            }

            $totalOrders = $branch->total_orders ?? -1;
            $countOrders = $branch->count_orders ?? 0;

            $unlimited = ($totalOrders == -1);

            return [
                'order_limit' => $totalOrders,
                'current_count' => $countOrders,
                'unlimited' => $unlimited,
            ];
        });
    }
}

if (!function_exists('branchOrderStatsAllowNewOrder')) {
    /**
     * Whether the branch daily order cap still allows another customer order.
     * When the configured cap is zero or negative (and not the unlimited -1 flag),
     * treat it as no positive cap so the customer menu is not stuck with a hidden Add button.
     *
     * @param  array<string, mixed>|null  $stats  Row from getRestaurantOrderStats()
     */
    function branchOrderStatsAllowNewOrder(?array $stats): bool
    {
        if ($stats === null) {
            return true;
        }

        if (! empty($stats['unlimited'])) {
            return true;
        }

        $limit = (int) ($stats['order_limit'] ?? 0);

        if ($limit <= 0) {
            return true;
        }

        $current = (int) ($stats['current_count'] ?? 0);

        return $current < $limit;
    }
}

if (!function_exists('getBranchOperationalShifts')) {
    /**
     * Get active operational shifts for a branch
     *
     * @param \App\Models\Branch|null $branch
     * @param string|null $dayOfWeek Optional day filter (Monday, Tuesday, etc.)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function getBranchOperationalShifts($branch = null, $dayOfWeek = null)
    {
        $branch = $branch ?? branch();
        
        if (!$branch) {
            return collect([]);
        }
        
        $query = BranchOperationalShift::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time');
        
        if ($dayOfWeek) {
            $query->where(function($q) use ($dayOfWeek) {
                // Check if JSON array contains 'All' or the specific day
                $q->whereJsonContains('day_of_week', 'All')
                  ->orWhereJsonContains('day_of_week', $dayOfWeek);
            });
        } else {
            // If no day filter, only show shifts with 'All'
            $query->whereJsonContains('day_of_week', 'All');
        }
        
        return $query->get();
    }
}

if (!function_exists('getBusinessDayBoundaries')) {
    /**
     * Get business day boundaries based on operational shifts
     * Returns start and end times in restaurant timezone
     *
     * @param \App\Models\Branch|null $branch
     * @param \Carbon\Carbon|string|null $date
     * @return array ['start' => Carbon, 'end' => Carbon, 'timezone' => string, 'calendar_date' => string]
     */
    function getBusinessDayBoundaries($branch = null, $date = null)
    {
        static $operationalShiftsByBranchId = [];
        static $boundaryResultCache = [];

        $branch = $branch ?? branch();

        // Carbon::parse($carbon, $tz) does not convert timezone — UTC "now" after midnight
        // would resolve to the wrong calendar day for Americas restaurants. Convert properly.
        $toTimezone = function ($value, string $tz): Carbon {
            if ($value === null) {
                return Carbon::now($tz);
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->setTimezone($tz);
            }

            return Carbon::parse($value, $tz);
        };
        
        if (!$branch) {
            // Fallback to calendar day
            $tz = timezone() ?? 'UTC';
            $currentTime = $toTimezone($date, $tz);

            return [
                'start' => $currentTime->copy()->startOfDay(),
                'end' => $currentTime->copy()->endOfDay(),
                'timezone' => $tz,
                'calendar_date' => $currentTime->toDateString()
            ];
        }

        $branchId = (int) $branch->id;
        $branch->loadMissing('restaurant');
        $restaurant = $branch->restaurant;
        $restaurantTimezone = $restaurant->timezone ?? 'UTC';

        $currentTime = $toTimezone($date, $restaurantTimezone);

        $boundaryCacheKey = $branchId . '|' . $currentTime->format('Y-m-d H:i');
        if (isset($boundaryResultCache[$boundaryCacheKey])) {
            $row = $boundaryResultCache[$boundaryCacheKey];
            $out = [
                'start' => $row['start']->copy(),
                'end' => $row['end']->copy(),
                'timezone' => $row['timezone'],
                'calendar_date' => $row['calendar_date'],
            ];
            if (isset($row['business_day_end'])) {
                $out['business_day_end'] = $row['business_day_end']->copy();
            }

            return $out;
        }

        $toRestaurantTime = function ($utcTime) use ($restaurantTimezone) {
            return Carbon::now('UTC')
                ->setTimeFromTimeString($utcTime)
                ->setTimezone($restaurantTimezone)
                ->format('H:i:s');
        };
        
        $calendarDate = $currentTime->toDateString(); // e.g., "2024-12-30"
        $dayOfWeek = $currentTime->format('l'); // e.g., "Monday"
        
        // Get all active shifts (we'll filter by day of week manually)
        if (! isset($operationalShiftsByBranchId[$branchId])) {
            $operationalShiftsByBranchId[$branchId] = BranchOperationalShift::where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('start_time')
                ->get();
        }
        $allShifts = $operationalShiftsByBranchId[$branchId];
        
        // Filter shifts that apply to the current day of week
        $shifts = $allShifts->filter(function($shift) use ($dayOfWeek) {
            $shiftDays = $shift->day_of_week ?? [];
            return in_array('All', $shiftDays) || in_array($dayOfWeek, $shiftDays);
        });
        
        if ($shifts->isEmpty()) {
            // No shifts configured - use calendar day in restaurant timezone
            return $boundaryResultCache[$boundaryCacheKey] = [
                'start' => $currentTime->copy()->startOfDay(),
                'end' => $currentTime->copy()->endOfDay(),
                'timezone' => $restaurantTimezone,
                'calendar_date' => $calendarDate
            ];
        }
        
        // Check for shifts from previous day that ended in current day
        $previousDay = Carbon::parse($calendarDate, $restaurantTimezone)->subDay();
        $previousDayOfWeek = $previousDay->format('l');
        $previousDayShifts = $allShifts->filter(function($shift) use ($previousDayOfWeek) {
            $shiftDays = $shift->day_of_week ?? [];
            return in_array('All', $shiftDays) || in_array($previousDayOfWeek, $shiftDays);
        });
        $previousDayEndTime = null;
        
        foreach ($previousDayShifts as $shift) {
            $shiftStartTime = $toRestaurantTime($shift->start_time);
            $shiftEndTime = $toRestaurantTime($shift->end_time);

            // Parse shift times for previous day
            $shiftStart = Carbon::parse(
                $previousDay->toDateString() . ' ' . $shiftStartTime,
                $restaurantTimezone
            );
            
            $shiftEnd = Carbon::parse(
                $previousDay->toDateString() . ' ' . $shiftEndTime,
                $restaurantTimezone
            );
            
            // Handle overnight shifts (end_time < start_time means it ends next day)
            if ($shiftEndTime < $shiftStartTime) {
                $shiftEnd->addDay();
            }
            
            // Check if this shift ended in the current calendar date
            if ($shiftEnd->toDateString() === $calendarDate) {
                // This shift from previous day ended in current day
                if (!$previousDayEndTime || $shiftEnd > $previousDayEndTime) {
                    $previousDayEndTime = $shiftEnd;
                }
            }
        }
        
        // Business day boundaries:
        // Start: first active shift start of the day (or previous day's overnight-end if later)
        // End: latest active shift end of the day (can cross to next day)
        $businessDayStart = null;
        $businessDayEnd = null;

        // Find earliest start and latest end from shifts that started on this calendar date.
        $latestShiftEnd = null;
        $earliestShiftStart = null;

        foreach ($shifts as $shift) {
            $shiftStartTime = $toRestaurantTime($shift->start_time);
            $shiftEndTime = $toRestaurantTime($shift->end_time);

            // Parse shift times in restaurant timezone
            $shiftStart = Carbon::parse(
                $calendarDate . ' ' . $shiftStartTime,
                $restaurantTimezone
            );
            
            $shiftEnd = Carbon::parse(
                $calendarDate . ' ' . $shiftEndTime,
                $restaurantTimezone
            );
            
            // Handle overnight shifts (end_time < start_time means it ends next day)
            if ($shiftEndTime < $shiftStartTime) {
                $shiftEnd->addDay();
            }

            if (!$earliestShiftStart || $shiftStart < $earliestShiftStart) {
                $earliestShiftStart = $shiftStart;
            }
            
            // Track the latest shift end (especially if it crosses to next day)
            if (!$latestShiftEnd || $shiftEnd > $latestShiftEnd) {
                $latestShiftEnd = $shiftEnd;
            }
        }

        // Base start/end from configured shifts
        if ($earliestShiftStart) {
            $businessDayStart = $earliestShiftStart;
        }

        if ($latestShiftEnd) {
            $businessDayEnd = $latestShiftEnd;
        }

        // If previous day's shift ended in current day and it's later than first shift start,
        // keep continuity by starting at previous shift end.
        if ($previousDayEndTime && $businessDayStart && $previousDayEndTime > $businessDayStart) {
            $businessDayStart = $previousDayEndTime;
        }

        // Safety fallback for unexpected empty boundaries.
        if (!$businessDayStart) {
            $businessDayStart = $currentTime->copy()->startOfDay();
        }
        if (!$businessDayEnd) {
            $businessDayEnd = $currentTime->copy()->endOfDay();
        }
        
        // For "today", the end should be current time (now) if we're viewing today
        // This is for query purposes - we want to show orders up to now
        $isToday = $calendarDate === Carbon::now($restaurantTimezone)->toDateString();
        if ($isToday) {
            // For queries, use current time
            // But we'll store the full business day end separately for display
            $latestEnd = Carbon::now($restaurantTimezone);
        } else {
            // For past dates, use the full business day end
            $latestEnd = $businessDayEnd;
        }
        
        // Use business day start
        $earliestStart = $businessDayStart;

        $earliestStart = $earliestStart->copy()->setTimezone($restaurantTimezone);
        $latestEnd = $latestEnd->copy()->setTimezone($restaurantTimezone);
        $businessDayEnd = $businessDayEnd->copy()->setTimezone($restaurantTimezone);
        
        return $boundaryResultCache[$boundaryCacheKey] = [
            'start' => $earliestStart,
            'end' => $latestEnd, // For queries - "now" if today, otherwise full business day end
            'business_day_end' => $businessDayEnd, // Full business day end for display (11:59 PM or shift end)
            'timezone' => $restaurantTimezone,
            'calendar_date' => $calendarDate
        ];
    }
}

if (!function_exists('getShiftForOrder')) {
    /**
     * Determine which shift an order belongs to based on order datetime
     *
     * @param string|\Carbon\Carbon $orderDateTime Order datetime (in UTC from database)
     * @param int|null $branchId
     * @return int|null Shift ID or null if no shift found (gap period)
     */
    function getShiftForOrder($orderDateTime, $branchId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : branch();
        
        if (!$branch) {
            return null;
        }
        
        $restaurantTimezone = $branch->restaurant->timezone ?? 'UTC';
        $toRestaurantTime = function ($utcTime) use ($restaurantTimezone) {
            return Carbon::now('UTC')
                ->setTimeFromTimeString($utcTime)
                ->setTimezone($restaurantTimezone)
                ->format('H:i:s');
        };
        
        // Convert order datetime from UTC to restaurant timezone
        $orderTime = Carbon::parse($orderDateTime)->setTimezone($restaurantTimezone);
        
        $calendarDate = $orderTime->toDateString();
        $dayOfWeek = $orderTime->format('l'); // e.g., "Monday", "Tuesday"
        
        // Get all active shifts (without day filter to check all shifts)
        $shifts = BranchOperationalShift::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get();
        
        foreach ($shifts as $shift) {
            $shiftStartTime = $toRestaurantTime($shift->start_time);
            $shiftEndTime = $toRestaurantTime($shift->end_time);

            // Check if shift applies to this day of week
            $shiftDays = $shift->day_of_week ?? [];
            if (!in_array('All', $shiftDays) && !in_array($dayOfWeek, $shiftDays)) {
                continue; // Skip this shift if it doesn't apply to this day
            }
            
            // Parse shift times in restaurant timezone
            $shiftStart = Carbon::parse(
                $calendarDate . ' ' . $shiftStartTime,
                $restaurantTimezone
            );
            
            $shiftEnd = Carbon::parse(
                $calendarDate . ' ' . $shiftEndTime,
                $restaurantTimezone
            );
            
            // Handle overnight shifts
            if ($shiftEndTime < $shiftStartTime) {
                $shiftEnd->addDay();
                
                // If order is early morning (before shift end), check previous day
                if ($orderTime->format('H:i') < $shiftEnd->format('H:i')) {
                    $shiftStart->subDay();
                }
            }
            
            // Check if order falls within this shift
            if ($orderTime >= $shiftStart && $orderTime <= $shiftEnd) {
                return $shift->id;
            }
        }
        
        return null; // No shift found (gap period)
    }
}

if (!function_exists('isWithinBusinessDay')) {
    /**
     * Check if a datetime falls within the current business day
     *
     * @param string|\Carbon\Carbon $datetime
     * @param \App\Models\Branch|null $branch
     * @return bool
     */
    function isWithinBusinessDay($datetime, $branch = null)
    {
        $boundaries = getBusinessDayBoundaries($branch, $datetime);
        
        $dt = Carbon::parse($datetime);
        if ($dt->timezone->getName() !== $boundaries['timezone']) {
            $dt = $dt->setTimezone($boundaries['timezone']);
        }
        
        return $dt >= $boundaries['start'] && $dt <= $boundaries['end'];
    }
}

if (! function_exists('image_upload_mimes')) {
    function image_upload_mimes(): string
    {
        return \App\Support\ImageUpload::MIME_EXTENSIONS;
    }
}

if (! function_exists('flipText')) {
    /**
     * Shape RTL text for receipt/KOT/PDF printing (see RtlPrintText).
     *
     * @param  string|null  $printingChoice  "browserPopupPrint" skips shaping; omit for PDF/thermal.
     */
    function flipText(string $text, ?string $language = null, ?string $printingChoice = null): string
    {
        return \App\Helper\RtlPrintText::flip($text, $language, $printingChoice);
    }
}

if (! function_exists('calculatePercentChange')) {
    /**
     * Relative percent change from a previous value to a current value.
     *
     * Returns null when the previous value is zero and the current value is not
     * (percent change vs. zero is undefined). Returns 0 when both are zero.
     */
    function calculatePercentChange(float|int $current, float|int $previous): ?float
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return (($current - $previous) / $previous) * 100;
    }
}

if (! function_exists('tlync_is_public_https_url')) {
    function tlync_is_public_https_url(string $url): bool
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return false;
        }

        return true;
    }
}

if (! function_exists('tlync_public_base_url')) {
    function tlync_public_base_url(): ?string
    {
        $configured = trim((string) env('TLYNC_PUBLIC_BASE_URL', ''));
        if ($configured !== '' && tlync_is_public_https_url($configured)) {
            return rtrim($configured, '/');
        }

        if (! app()->runningInConsole()) {
            try {
                $requestUrl = request()->getSchemeAndHttpHost();
                if (tlync_is_public_https_url($requestUrl)) {
                    return rtrim($requestUrl, '/');
                }
            } catch (\Throwable) {
                // No HTTP request available.
            }
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl !== '' && tlync_is_public_https_url($appUrl)) {
            return $appUrl;
        }

        return null;
    }
}

if (! function_exists('tlync_public_route')) {
    function tlync_public_route(string $name, array $parameters = []): string
    {
        $base = tlync_public_base_url();
        if ($base === null) {
            throw new \RuntimeException('TLYNC_PUBLIC_BASE_URL_REQUIRED');
        }

        return $base . route($name, $parameters, false);
    }
}

if (! function_exists('tlync_api_phone')) {
  /**
   * T-Lync / Sadad expect international Libya format: 2189xxxxxxxx
   */
    function tlync_api_phone(string $normalizedPhone): string
    {
        $digits = preg_replace('/\D/', '', $normalizedPhone);

        if (str_starts_with($digits, '218')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '218' . $digits;
    }
}

if (! function_exists('tlync_api_amount')) {
    function tlync_api_amount(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
