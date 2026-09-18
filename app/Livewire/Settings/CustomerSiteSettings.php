<?php

namespace App\Livewire\Settings;

use Livewire\Component;
use Livewire\WithFileUploads;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use App\Models\CartHeaderSetting;
use App\Models\CartHeaderImage;
use App\Models\OfflinePaymentMethod;
use App\Models\PaymentGatewayCredential;
use App\Helper\Files;
use Illuminate\Support\Facades\Validator;

class CustomerSiteSettings extends Component
{

    use LivewireAlert, WithFileUploads;

    public $settings;
    public bool $customerLoginRequired;
    public bool $allowCustomerOrders;
    public bool $allowCustomerDeliveryOrders;
    public bool $allowCustomerPickupOrders;
    public bool $isWaiterRequestEnabled;
    public bool $isWaiterRequestEnabledOnDesktop;
    public bool $isWaiterRequestEnabledOnMobile;
    public bool $isWaiterRequestEnabledOpenByQr;
    public string $defaultReservationStatus;
    public $facebook;
    public $instagram;
    public $twitter;
    public $yelp;
    public $googleBusinessLink;
    public bool $tableRequired;
    public bool $allowDineIn;
    public $metaKeyword;
    public $metaDescription;
    public $wifiName;
    public $wifiPassword;
    public bool $showWifiIcon = false;
    public bool $enableTipShop;
    public bool $enableTipPos;
    public bool $pwaAlertShow;
    public bool $autoConfirmOrdersEnabled = false;
    public bool $autoConfirmOrdersBeforePayment;
    public bool $autoConfirmOrdersAfterPayment;
    public ?string $autoConfirmOrderType = null;
    public $pickupDaysRange;
    public bool $showVeg;
    public bool $showHalal;
    public bool $restrictQrOrderByLocation = false;
    public ?int $qrOrderRadiusMeters = null;
    public int $tableLockTimeoutMinutes;
    public $activeTab = 'settings';
    public $headerType = 'text';
    public $headerText;
    public $headerImages = [];
    public $newImages = [];
    public bool $newImagesValidated = false;
    public $cartHeaderSetting;
    public bool $isHeaderDisabled = false;

    public $paymentGateway;
    public bool $enableForDineIn = false;
    public bool $enableForDelivery = false;
    public bool $enableForPickup = false;
    public bool $hasAnyPaymentMethodEnabled = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    private const CART_HEADER_IMAGE_WIDTH = 1248;
    private const CART_HEADER_IMAGE_HEIGHT = 192;

    public function mount()
    {
        $this->defaultReservationStatus = $this->settings->default_table_reservation_status;
        $this->customerLoginRequired = $this->settings->customer_login_required;
        $this->allowCustomerOrders = $this->settings->allow_customer_orders;
        $this->allowCustomerDeliveryOrders = $this->settings->allow_customer_delivery_orders;
        $this->allowCustomerPickupOrders = $this->settings->allow_customer_pickup_orders;
        $this->pickupDaysRange = $this->settings->pickup_days_range;
        $this->isWaiterRequestEnabled = $this->settings->is_waiter_request_enabled;
        $this->enableTipShop = $this->settings->enable_tip_shop;
        $this->enableTipPos = $this->settings->enable_tip_pos;
        $this->autoConfirmOrdersBeforePayment = $this->settings->auto_confirm_orders_before_payment;
        $this->autoConfirmOrdersAfterPayment = $this->settings->auto_confirm_orders_after_payment;

        // Set checkbox enabled state based on whether either option is enabled
        $this->autoConfirmOrdersEnabled = $this->autoConfirmOrdersBeforePayment || $this->autoConfirmOrdersAfterPayment;

        // Set radio button value based on existing boolean values
        if ($this->autoConfirmOrdersBeforePayment) {
            $this->autoConfirmOrderType = 'before_payment';
        } elseif ($this->autoConfirmOrdersAfterPayment) {
            $this->autoConfirmOrderType = 'after_payment';
        } else {
            $this->autoConfirmOrderType = null;
        }

        $this->showVeg = $this->settings->show_veg;
        $this->showHalal = $this->settings->show_halal;
        $this->tableLockTimeoutMinutes = $this->settings->table_lock_timeout_minutes;
        $this->isWaiterRequestEnabledOnDesktop = $this->settings->is_waiter_request_enabled_on_desktop;
        $this->isWaiterRequestEnabledOnMobile = $this->settings->is_waiter_request_enabled_on_mobile;
        $this->isWaiterRequestEnabledOpenByQr = $this->settings->is_waiter_request_enabled_open_by_qr;

        // QR order location restriction
        $this->restrictQrOrderByLocation = (bool) ($this->settings->restrict_qr_order_by_location ?? false);
        $this->qrOrderRadiusMeters = $this->settings->qr_order_radius_meters ?? null;

        $this->tableRequired = $this->settings->table_required;
        $this->allowDineIn = $this->settings->allow_dine_in_orders;
        $this->facebook = $this->settings->facebook_link;
        $this->instagram = $this->settings->instagram_link;
        $this->twitter = $this->settings->twitter_link;
        $this->yelp = $this->settings->yelp_link;
        $this->googleBusinessLink = $this->settings->google_business_link;
        $this->metaKeyword = $this->settings->meta_keyword;
        $this->metaDescription = $this->settings->meta_description;
        $this->wifiName = $this->settings->wifi_name;
        $this->wifiPassword = $this->settings->wifi_password;
        $this->showWifiIcon = $this->settings->show_wifi_icon ?? false;
        $this->pwaAlertShow = $this->settings->is_pwa_install_alert_show;

        // Initialize header settings
        $this->cartHeaderSetting = $this->settings->cartHeaderSetting;
        if ($this->cartHeaderSetting) {
            $this->headerType = $this->cartHeaderSetting->header_type;
            $this->headerText = $this->cartHeaderSetting->header_text;
            $this->headerImages = $this->cartHeaderSetting->images;
            $this->isHeaderDisabled = $this->cartHeaderSetting->is_header_disabled ?? false;
        } else {
            $this->headerText = __('messages.frontHeroHeading');
            $this->isHeaderDisabled = false;
        }

        // Initialize newImages as empty array
        $this->newImages = [];
        $this->newImagesValidated = false;

        // Online payment required (service-specific) settings
        $this->paymentGateway = PaymentGatewayCredential::first();
        if ($this->paymentGateway) {
            $this->enableForDineIn = (bool) $this->paymentGateway->is_dine_in_payment_enabled;
            $this->enableForDelivery = (bool) $this->paymentGateway->is_delivery_payment_enabled;
            $this->enableForPickup = (bool) $this->paymentGateway->is_pickup_payment_enabled;
        }

        $restaurantId = restaurant() ? restaurant()->id : null;
        $hasAnyOfflinePaymentEnabled = OfflinePaymentMethod::where('restaurant_id', $restaurantId)
            ->where('status', 'active')
            ->exists();

        $hasAnyGatewayEnabled = $this->paymentGateway
            ? (
                (bool) $this->paymentGateway->razorpay_status
                || (bool) $this->paymentGateway->stripe_status
                || (bool) $this->paymentGateway->flutterwave_status
                || (bool) $this->paymentGateway->paypal_status
                || (bool) $this->paymentGateway->payfast_status
                || (bool) $this->paymentGateway->paystack_status
                || (bool) $this->paymentGateway->xendit_status
                || (bool) $this->paymentGateway->epay_status
                || (bool) $this->paymentGateway->mollie_status
                || (bool) ($this->paymentGateway->tap_status ?? false)
                || (bool) ($this->paymentGateway->tlync_status ?? false)
            )
            : false;

        $hasQrPaymentEnabled = $this->paymentGateway ? (bool) $this->paymentGateway->is_qr_payment_enabled : false;

        $this->hasAnyPaymentMethodEnabled = $hasAnyGatewayEnabled || $hasAnyOfflinePaymentEnabled || $hasQrPaymentEnabled;
    }

    public function updatedHeaderType($value)
    {
        $this->headerType = $value;
        $this->dispatch('headerTypeChanged', $value);
    }

    public function updatedAutoConfirmOrdersEnabled($value)
    {
        // When checkbox is unchecked, clear the radio selection
        if (!$value) {
            $this->autoConfirmOrderType = null;
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = false;
        }
    }

    public function updatedAutoConfirmOrderType($value)
    {
        // Update boolean values based on radio selection (mutually exclusive)
        if ($value === 'before_payment') {
            $this->autoConfirmOrdersBeforePayment = true;
            $this->autoConfirmOrdersAfterPayment = false;
        } elseif ($value === 'after_payment') {
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = true;
        } else {
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = false;
        }
    }

    public function updatedNewImages()
    {
        // When selecting new images, clear old validation/errors for them
        $this->clearNewImagesErrors();
        $this->newImagesValidated = false;

        if (!is_array($this->newImages) || count($this->newImages) === 0) {
            return;
        }

        $this->validate([
            'newImages.*' => 'nullable|image|max:2048',
        ]);

        $this->validateHeaderImages();
        $this->newImagesValidated = true;
    }

    public function validateHeaderImages()
    {
        $this->clearNewImagesErrors();

        if (is_array($this->newImages) && count($this->newImages) > 0) {
            foreach ($this->newImages as $index => $image) {
                if ($image) {
                    // Validate image dimensions
                    $imageInfo = @getimagesize($image->getRealPath());
                    if ($imageInfo) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];

                        if ($width !== self::CART_HEADER_IMAGE_WIDTH || $height !== self::CART_HEADER_IMAGE_HEIGHT) {
                            $this->addError('newImages.' . $index, __('modules.settings.imageDimensionsMustBeExact', [
                                'width' => self::CART_HEADER_IMAGE_WIDTH,
                                'height' => self::CART_HEADER_IMAGE_HEIGHT,
                                'currentWidth' => $width,
                                'currentHeight' => $height,
                            ]));
                        }
                    }
                }
            }
        }
    }

    private function clearNewImagesErrors(): void
    {
        $this->newImagesValidated = false;

        // Livewire's resetErrorBag('newImages') doesn't remove 'newImages.0', 'newImages.1', etc.
        $bag = $this->getErrorBag();
        foreach (array_keys($bag->toArray()) as $key) {
            if ($key === 'newImages' || str_starts_with($key, 'newImages.')) {
                $bag->forget($key);
            }
        }
        $this->setErrorBag($bag);
    }

    public function submitForm()
    {


        $rules = [
            'defaultReservationStatus' => 'required|in:Confirmed,Checked_In,Cancelled,No_Show,Pending',
            'tableLockTimeoutMinutes' => 'required|integer|min:1',
            'headerType' => 'required|in:text,image',
            'headerText' => 'required_if:headerType,text',
            'googleBusinessLink' => 'nullable|url',
            'wifiName' => [
                'nullable',
                'max:255',
                'required_if:showWifiIcon,true',
                'required_with:wifiPassword',
            ],
            'wifiPassword' => [
                'nullable',
                'max:255',
                'required_if:showWifiIcon,true',
                'required_with:wifiName',
            ],
        ];

        $this->validate($rules);

        // Validate header images (per-image). We will save ONLY valid images.
        $validNewImages = [];
        if ($this->headerType === 'image' && is_array($this->newImages) && count($this->newImages) > 0) {
            $this->clearNewImagesErrors();
            $this->newImagesValidated = true;

            foreach ($this->newImages as $index => $image) {
                if (!$image) {
                    continue;
                }

                $validator = Validator::make(
                    ['file' => $image],
                    ['file' => 'image|max:2048']
                );

                if ($validator->fails()) {
                    $this->addError('newImages.' . $index, $validator->errors()->first('file'));
                    continue;
                }

                $imageInfo = @getimagesize($image->getRealPath());
                if (!$imageInfo) {
                    $this->addError('newImages.' . $index, __('validation.image', ['attribute' => 'image']));
                    continue;
                }

                $width = $imageInfo[0];
                $height = $imageInfo[1];
                if ($width !== self::CART_HEADER_IMAGE_WIDTH || $height !== self::CART_HEADER_IMAGE_HEIGHT) {
                    $this->addError('newImages.' . $index, __('modules.settings.imageDimensionsMustBeExact', [
                        'width' => self::CART_HEADER_IMAGE_WIDTH,
                        'height' => self::CART_HEADER_IMAGE_HEIGHT,
                        'currentWidth' => $width,
                        'currentHeight' => $height,
                    ]));
                    continue;
                }

                $validNewImages[] = $image;
            }
        }

        if (!$this->allowDineIn && !$this->allowCustomerDeliveryOrders && !$this->allowCustomerPickupOrders) {
            $this->allowCustomerOrders = false;
        }

        $this->settings->default_table_reservation_status = $this->defaultReservationStatus;
        $this->settings->customer_login_required = $this->customerLoginRequired;
        $this->settings->allow_customer_orders = $this->allowCustomerOrders;
        $this->settings->allow_customer_delivery_orders = $this->allowCustomerDeliveryOrders;
        $this->settings->allow_customer_pickup_orders = $this->allowCustomerPickupOrders;
        $this->settings->pickup_days_range = $this->pickupDaysRange;
        $this->settings->is_waiter_request_enabled = $this->isWaiterRequestEnabled;
        $this->settings->is_waiter_request_enabled_on_desktop = $this->isWaiterRequestEnabledOnDesktop;
        $this->settings->is_waiter_request_enabled_on_mobile = $this->isWaiterRequestEnabledOnMobile;
        $this->settings->is_waiter_request_enabled_open_by_qr = $this->isWaiterRequestEnabledOpenByQr;
        $this->settings->table_required = $this->tableRequired;
        $this->settings->allow_dine_in_orders = $this->allowDineIn;
        $this->settings->facebook_link = $this->facebook;
        $this->settings->instagram_link = $this->instagram;
        $this->settings->twitter_link = $this->twitter;
        $this->settings->yelp_link = $this->yelp;
        $this->settings->google_business_link = $this->googleBusinessLink;
        $this->settings->meta_keyword = $this->metaKeyword;
        $this->settings->meta_description = $this->metaDescription;
        $this->settings->wifi_name = $this->wifiName;
        $this->settings->wifi_password = $this->wifiPassword;
        $this->settings->show_wifi_icon = $this->showWifiIcon;
        $this->settings->enable_tip_shop = $this->enableTipShop;
        $this->settings->enable_tip_pos = $this->enableTipPos;

        // Update boolean values based on checkbox and radio selection before saving
        if (!$this->autoConfirmOrdersEnabled) {
            // If checkbox is unchecked, disable both options
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = false;
            $this->autoConfirmOrderType = null;
        } elseif ($this->autoConfirmOrderType === 'before_payment') {
            $this->autoConfirmOrdersBeforePayment = true;
            $this->autoConfirmOrdersAfterPayment = false;
        } elseif ($this->autoConfirmOrderType === 'after_payment') {
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = true;
        } else {
            $this->autoConfirmOrdersBeforePayment = false;
            $this->autoConfirmOrdersAfterPayment = false;
        }

        $this->settings->auto_confirm_orders_before_payment = $this->autoConfirmOrdersBeforePayment;
        $this->settings->auto_confirm_orders_after_payment = $this->autoConfirmOrdersAfterPayment;
        $this->settings->is_pwa_install_alert_show = $this->pwaAlertShow;
        $this->settings->show_veg = $this->showVeg;
        $this->settings->show_halal = $this->showHalal;
        $this->settings->table_lock_timeout_minutes = $this->tableLockTimeoutMinutes;
        $this->settings->restrict_qr_order_by_location = $this->restrictQrOrderByLocation;
        $this->settings->qr_order_radius_meters = $this->restrictQrOrderByLocation ? $this->qrOrderRadiusMeters : null;
        $this->settings->save();

        // Save header settings
        $this->saveHeaderSettings($validNewImages);

        $this->dispatch('settingsUpdated');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function submitFormServiceSpecific()
    {
        if (!$this->paymentGateway) {
            $this->paymentGateway = PaymentGatewayCredential::first();
        }

        if (!$this->paymentGateway) {
            $this->alert('error', __('messages.somethingWentWrong'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            return;
        }

        $this->paymentGateway->update([
            'is_dine_in_payment_enabled' => $this->enableForDineIn,
            'is_delivery_payment_enabled' => $this->enableForDelivery,
            'is_pickup_payment_enabled' => $this->enableForPickup,
        ]);

        $this->dispatch('settingsUpdated');
        session()->forget('paymentGateway');

        $this->alert('success', __('messages.settingsUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function saveHeaderSettings(array $validNewImages = [])
    {
        if (!$this->cartHeaderSetting) {
            $this->cartHeaderSetting = CartHeaderSetting::create([
                'restaurant_id' => $this->settings->id,
                'header_type' => $this->headerType,
                'header_text' => $this->headerText,
                'is_header_disabled' => $this->isHeaderDisabled,
            ]);
        } else {
            $this->cartHeaderSetting->update([
                'header_type' => $this->headerType,
                'header_text' => $this->headerText,
                'is_header_disabled' => $this->isHeaderDisabled,
            ]);
        }

        // Handle image uploads using Files::uploadLocalOrS3
        if ($this->headerType === 'image' && count($validNewImages) > 0) {
            foreach ($validNewImages as $image) {
                try {
                    $imagePath = Files::uploadLocalOrS3(
                        $image,
                        'cart_header_images',
                        width: self::CART_HEADER_IMAGE_WIDTH,
                        height: self::CART_HEADER_IMAGE_HEIGHT
                    );
                    CartHeaderImage::create([
                        'cart_header_setting_id' => $this->cartHeaderSetting->id,
                        'image_path' => $imagePath,
                        'sort_order' => $this->cartHeaderSetting->images()->count(),
                    ]);
                } catch (\Exception $e) {
                    $this->alert('error', __('messages.imageUploadFailed') . ': ' . $e->getMessage(), [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                }
            }
        }

        // Clear the newImages after attempting upload (valid ones already saved; invalid ones stay visible via errors until next selection)
        $this->newImages = [];
        $this->newImagesValidated = false;
        $this->clearNewImagesErrors();

        // Refresh the header images
        $this->headerImages = $this->cartHeaderSetting->fresh()->images;
    }

    public function removeImage($imageId)
    {
        $image = CartHeaderImage::find($imageId);
        if ($image && $image->cart_header_setting_id === $this->cartHeaderSetting->id) {
            // Delete the file from storage
            if ($image->image_path) {
                Files::deleteFile($image->image_path, 'cart_header_images');
            }
            $image->delete();
            $this->headerImages = $this->cartHeaderSetting->fresh()->images;
        }
    }

    public function render()
    {
        return view('livewire.settings.customer-site-settings');
    }
}
