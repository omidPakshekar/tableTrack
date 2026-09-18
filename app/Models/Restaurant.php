<?php

namespace App\Models;

use App\Support\EuAnnexIiAllergens;
use App\Traits\FaviconTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Cashier\Billable;
use App\Models\BaseModel;
use Modules\Sms\Entities\RestaurantAndroidSmsSetting;

class Restaurant extends BaseModel
{
    use HasFactory, Billable;

    protected $guarded = ['id'];

    const FAVICON_BASE_PATH_RESTAURANT = 'favicons/restaurant/';

    const ABOUT_US_DEFAULT_TEXT = '<p class="text-lg text-gray-600 mb-6">
          Welcome to our restaurant, where great food and good vibes come together! We\'re a local, family-owned spot that loves bringing people together over delicious meals and unforgettable moments. Whether you\'re here for a quick bite, a family dinner, or a celebration, we\'re all about making your time with us special.
        </p>
        <p class="text-lg text-gray-600 mb-6">
          Our menu is packed with dishes made from fresh, quality ingredients because we believe food should taste as
          good as it makes you feel. From our signature dishes to seasonal specials, there\'s always something to excite
          your taste buds.
        </p>
        <p class="text-lg text-gray-600 mb-6">
          But we\'re not just about the food—we\'re about community. We love seeing familiar faces and welcoming new ones.
          Our team is a fun, friendly bunch dedicated to serving you with a smile and making sure every visit feels like
          coming home.
        </p>
        <p class="text-lg text-gray-600">
          So, come on in, grab a seat, and let us take care of the rest. We can\'t wait to share our love of food with
          you!
        </p>
        <p class="text-lg text-gray-800 font-semibold mt-6">See you soon! 🍽️✨</p>';

    protected $appends = [
        'logo_url',
        'dark_logo_url',
    ];

    public function getFaviconBasePath(): string
    {
        return self::FAVICON_BASE_PATH_RESTAURANT . $this->hash . '/';
    }

    private static function appendAssetVersion(string $url, ?int $version): string
    {
        if ($version === null) {
            return $url;
        }

        // Pre-signed object URLs (S3, etc.) sign the exact query string; extra params break the signature.
        if (in_array(config('filesystems.default'), StorageSetting::S3_COMPATIBLE_STORAGE, true)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . $version;
    }

    protected $casts = [
        'license_expire_on' => 'datetime',
        'trial_expire_on' => 'datetime',
        'license_updated_at' => 'datetime',
        'subscription_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'custom_delivery_options' => 'array',
        'is_active' => 'boolean',
        'enable_admin_reservation' => 'boolean',
        'enable_customer_reservation' => 'boolean',
        'restrict_qr_order_by_location' => 'boolean',
        'ai_enabled' => 'boolean',
        'ai_allowed_roles' => 'array',
        'ai_monthly_reset_at' => 'date',
        'is_temporarily_closed' => 'boolean',
        'restaurant_manual_open_close_type' => 'string',
        'disable_menu_item_default_image' => 'boolean',
        'auto_mark_order_completed_on_paid' => 'boolean',
        'show_payment_qr_on_customer_display' => 'boolean',
    ];

    public function logoUrl(): Attribute
    {
        return Attribute::get(function (): string {
            return $this->logo ? asset_url_local_s3('logo/' . $this->logo) : global_setting()->logoUrl;
        });
    }

    public function darkLogoUrl(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->dark_logo) {
                return asset_url_local_s3('logo/' . $this->dark_logo);
            }

            if ($this->logo) {
                return asset_url_local_s3('logo/' . $this->logo);
            }

            $global = global_setting();

            return $global->dark_logo ? $global->dark_logo_url : $global->logoUrl;
        });
    }

    public function hasDarkLogo(): bool
    {
        return filled($this->dark_logo);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withoutGlobalScopes();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes();
    }

    public function paymentGateways(): HasOne
    {
        return $this->hasOne(PaymentGatewayCredential::class)->withoutGlobalScopes();
    }

    public function customerDisplayQrCodeImageUrl(): ?string
    {
        if (! (bool) ($this->show_payment_qr_on_customer_display ?? true)) {
            return null;
        }

        $paymentGateway = $this->paymentGateways;
        if ($paymentGateway?->qr_code_image) {
            $gatewayUrl = $paymentGateway->qr_code_image_url;
            if ($gatewayUrl !== '') {
                return $gatewayUrl;
            }
        }

        $branch = branch();
        if (! $branch || (int) $branch->restaurant_id !== (int) $this->id) {
            $branch = $this->branches()->first();
        }

        if ($branch) {
            $receiptSetting = $branch->receiptSetting()->first();
            if ($receiptSetting?->payment_qr_code) {
                $receiptUrl = $receiptSetting->payment_qr_code_url;
                if ($receiptUrl !== '') {
                    return $receiptUrl;
                }
            }
        }

        return null;
    }

    public function euAllergenSetting(): HasOne
    {
        return $this->hasOne(RestaurantEuAllergenSetting::class)->withoutGlobalScopes();
    }

    /**
     * Annex II allergen keys enabled for menu item forms (EU 1169/2011), or empty when feature is off.
     *
     * @return list<string>
     */
    public function selectableEuAllergenKeys(): array
    {
        $setting = $this->relationLoaded('euAllergenSetting')
            ? $this->getRelation('euAllergenSetting')
            : $this->euAllergenSetting()->first();

        if (!$setting || !$setting->enabled) {
            return [];
        }

        return EuAnnexIiAllergens::normalizedSelection($setting->allergen_keys);
    }

    public function androidSmsGatewaySetting(): HasOne
    {
        return $this->hasOne(RestaurantAndroidSmsSetting::class);
    }

    public function offlinePaymentMethods(): HasMany
    {
        return $this->hasMany(OfflinePaymentMethod::class);
    }

    public function restaurantPayment(): HasMany
    {
        return $this->hasMany(RestaurantPayment::class)->where('status', 'paid  ')->orderByDesc('id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function currentInvoice(): HasOne
    {
        return $this->hasOne(GlobalInvoice::class)->latest();
    }

    public static function restaurantAdmin($restaurant)
    {
        return $restaurant->users()->orderBy('id')->first();
    }

    public function receiptSetting(): HasOne
    {
        return $this->hasOne(ReceiptSetting::class);
    }

    public function printerSettings(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    public function predefinedAmounts(): HasMany
    {
        return $this->hasMany(PredefinedAmount::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(RestaurantTax::class);
    }

    public function kotPlaces(): HasMany
    {
        return $this->hasMany(KotPlace::class);
    }

    public function orderPlaces(): HasMany
    {
        return $this->hasMany(MultipleOrder::class);
    }

    public function cartHeaderSetting(): HasOne
    {
        return $this->hasOne(CartHeaderSetting::class);
    }

    /**
     * Get URL for Android Chrome 192x192 favicon
     * Returns restaurant's custom favicon if available, otherwise falls back to global setting
     */
    public function uploadFavIconAndroidChrome192Url(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom favicon if exists, otherwise use global setting
            return $this->upload_fav_icon_android_chrome_192
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->upload_fav_icon_android_chrome_192),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->upload_fav_icon_android_chrome_192_url;
        });
    }

    /**
     * Get URL for Android Chrome 512x512 favicon
     * Returns restaurant's custom favicon if available, otherwise falls back to global setting
     */
    public function uploadFavIconAndroidChrome512Url(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom favicon if exists, otherwise use global setting
            return $this->upload_fav_icon_android_chrome_512
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->upload_fav_icon_android_chrome_512),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->upload_fav_icon_android_chrome_512_url;
        });
    }

    /**
     * Get URL for Apple Touch Icon (180x180)
     * Returns restaurant's custom icon if available, otherwise falls back to global setting
     */
    public function uploadFavIconAppleTouchIconUrl(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom icon if exists, otherwise use global setting
            return $this->upload_fav_icon_apple_touch_icon
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->upload_fav_icon_apple_touch_icon),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->upload_fav_icon_apple_touch_icon_url;
        });
    }

    /**
     * Get URL for 16x16 favicon
     * Returns restaurant's custom favicon if available, otherwise falls back to global setting
     */
    public function uploadFavIcon16Url(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom favicon if exists, otherwise use global setting
            return $this->upload_favicon_16
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->upload_favicon_16),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->upload_fav_icon_16_url;
        });
    }

    /**
     * Get URL for 32x32 favicon
     * Returns restaurant's custom favicon if available, otherwise falls back to global setting
     */
    public function uploadFavIcon32Url(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom favicon if exists, otherwise use global setting
            return $this->upload_favicon_32
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->upload_favicon_32),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->upload_fav_icon_32_url;
        });
    }

    /**
     * Get URL for main favicon.ico file
     * Returns restaurant's custom favicon if available, otherwise falls back to global setting
     */
    public function faviconUrl(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom favicon if exists, otherwise use global setting
            return $this->favicon
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->favicon),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->favicon_url;
        });
    }

    /**
     * Get URL for webmanifest file (used for PWA support)
     * Returns restaurant's custom webmanifest if available, otherwise falls back to global setting
     */
    public function webmanifestUrl(): Attribute
    {
        return Attribute::get(function (): string {
            // Use restaurant's custom webmanifest if exists, otherwise use global setting
            return $this->webmanifest
                ? self::appendAssetVersion(
                    asset_url_local_s3($this->getFaviconBasePath() . $this->webmanifest),
                    $this->updated_at?->getTimestamp()
                )
                : global_setting()->webmanifest_url;
        });
    }
}
