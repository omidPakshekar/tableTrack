<?php

namespace App\Livewire\Shop;

use App\Models\Kot;
use App\Models\Tax;
use App\Models\Area;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Table;
use Razorpay\Api\Api;
use App\Models\Country;
use App\Models\KotItem;
use App\Models\Payment;
use App\Models\Printer;
use Livewire\Component;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\KotPlace;
use App\Models\MenuItem;
use App\Models\OrderTax;
use App\Models\OrderItem;
use App\Models\OrderType;
use App\Models\TapPayment;
use App\Models\TlyncPayment;
use App\Services\TlyncPaymentService;
use App\Models\EpayPayment;
use App\Models\OrderCharge;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use App\Concerns\PrintsShopKot;
use App\Events\OrderUpdated;
use App\Models\ItemCategory;
use App\Models\PaypalPayment;
use App\Models\StripePayment;
use App\Models\XenditPayment;
use App\Models\ModifierOption;
use App\Traits\PrinterSetting;
use App\Events\NewOrderCreated;
use App\Models\RazorpayPayment;
use Illuminate\Validation\Rule;
use Mollie\Api\MollieApiClient;
use App\Models\RestaurantCharge;
use App\Models\MenuItemVariation;
use Livewire\Attributes\Computed;
use App\Models\AdminMolliePayment;
use App\Models\FlutterwavePayment;
use Illuminate\Support\Facades\DB;
use App\Models\AdminPayfastPayment;
use Illuminate\Support\Facades\Log;
use App\Events\SendNewOrderReceived;
use App\Models\AdminPaystackPayment;
use App\Notifications\SendOrderBill;
use Illuminate\Support\Facades\Http;
use App\Scopes\AvailableMenuItemScope;
use App\Models\PaymentGatewayCredential;
use App\Models\OfflinePaymentMethod;
use App\Models\CartHeaderSetting;
use App\Scopes\RestaurantScope;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use App\Services\RestaurantAvailabilityService;
use App\Services\ShopCartKotPrintUrls;
use App\Services\Pos\IngredientStockGuard;
use App\Services\Shop\BrowseCartMutator;
use App\Services\Shop\CustomerSiteCatalogBuilder;

class Cart extends Component
{

    use LivewireAlert;
    use PrinterSetting;
    use PrintsShopKot;

    // Note: HasLoyaltyIntegration trait is conditionally loaded
    // If the Loyalty module doesn't exist, stub methods below handle it gracefully

    public $search;
    public $tableID;
    public $roomID;
    public $filterCategories;
    public $kotList = [];
    public $showVariationModal = false;
    public $showCartVariationModal = false;
    public $showCustomerNameModal = false;
    public $showPaymentModal = false;
    public $showMenu = true;
    public $showCart = false;
    public $orderItemList = [];
    public $orderItemVariation = [];
    public $orderItemQty = [];
    public $cartItemQty = [];
    public $orderItemAmount = [];
    public $orderItemModifiersPrice = [];
    public $menuItem;
    public $subTotal;
    public $total;
    public $taxes;
    public $customer;
    public $customerId; // For loyalty integration trait
    public $customerName;
    public $customerPhone;
    public $customerPhoneCode;
    public $phoneCodeDetected = false;
    public $customerAddress;
    public $mapApiKey;
    public $mapProvider;
    public $phoneCodeSearch = '';
    public $phoneCodeIsOpen = false;
    public $allPhoneCodes;
    public $filteredPhoneCodes;
    public $orderNumber;
    public $paymentGateway;
    public $paymentOrder;
    public $showVeg;
    public $razorpayStatus;
    public $stripeStatus;
    public bool $showStripeOrderPaymentModal = false;
    public $cartQty;
    public $restaurantHash;
    public $restaurant;
    public $shopBranch;
    public $orderType;
    public $orderTypeId; // Add orderTypeId for pricing context
    public $orderTypeSlug; // Add orderTypeSlug
    public $showOrderTypeModal = false; // Modal for order type selection
    public $cameFromQR = false; // Track if user came from QR code (table or universal)
    public bool $cameFromTableQR = false; // Table-specific QR scan
    public bool $cameFromRoomQR = false; // Hotel room QR scan
    public bool $tableQrOrderingBlocked = false; // Dine-in disabled for customers on table QR
    public bool $roomQrOrderingBlocked = false; // Room service disabled for customers on room QR
    public $hotelStayId;
    public $room;
    public $payNow = false;
    public $offline_payment_status;
    public $menuId;
    public $orderID;
    public $order;
    public $table;
    public $tables;
    public $getTable;
    public $qrCodeImage;
    public $enableQrPayment;
    public $showQrCode = false;
    public $showPaymentDetail = false;
    public $showTableModal = false;
    public $canCreateOrder;
    public $orderBeingProcessed = false;
    public $showModifiersModal = false;
    public $itemModifiersSelected = [];
    public $selectedModifierItem;
    public $showItemDetailModal = false;
    public $selectedItem;
    public $extraCharges;
    public $orderNote;
    public $showItemVariationsModal = false;
    public $showDeliveryAddressModal = false;
    public $addressLat;
    public $addressLng;
    public $deliveryAddress;
    public $deliveryFee = null;
    public $maxPreparationTime;
    public $etaMin;
    public $etaMax;
    public $itemNotes = [];
    public $orderItemTaxDetails = [];
    public $totalTaxAmount = 0;
    public $taxMode;
    public $taxBase = 0;
    public $showPickupDateTimeModal = false;
    public $pickupRange;
    public $now;
    public $minDate;
    public $maxDate;
    public $defaultDate;
    public $deliveryDateTime;
    public $pickupDate;
    public $pickupTime;
    public $showHalal;
    public $headerType = 'text';
    public $headerText;
    public $headerImages = [];
    public $isHeaderDisabled = false;
    public $showLocationModal = false;
    public $is_within_radius = true;
    public $menuItemsLoaded = 50;
    public $menuItemsPerLoad = 50;
    public $offlinePaymentMethods = [];
    public $selectedOfflinePaymentMethod = null;
    public $pendingTlyncOrderId = null;

    private function getActivePaymentOptions(): array
    {
        $pg = $this->paymentGateway;

        if (!$pg) {
            return [];
        }

        $options = [];

        if ((bool) ($pg->stripe_status ?? false)) {
            $options[] = 'stripe';
        }
        if ((bool) ($pg->razorpay_status ?? false)) {
            $options[] = 'razorpay';
        }
        if ((bool) ($pg->flutterwave_status ?? false)) {
            $options[] = 'flutterwave';
        }
        if ((bool) ($pg->paypal_status ?? false)) {
            $options[] = 'paypal';
        }
        if ((bool) ($pg->payfast_status ?? false)) {
            $options[] = 'payfast';
        }
        if ((bool) ($pg->paystack_status ?? false)) {
            $options[] = 'paystack';
        }
        if ((bool) ($pg->xendit_status ?? false)) {
            $options[] = 'xendit';
        }
        if ((bool) ($pg->epay_status ?? false)) {
            $options[] = 'epay';
        }
        if ((bool) ($pg->mollie_status ?? false)) {
            $options[] = 'mollie';
        }
        if ((bool) ($pg->tap_status ?? false)) {
            $options[] = 'tap';
        }
        if ((bool) ($pg->tlync_status ?? false)) {
            $options[] = 'tlync';
        }

        if ((bool) ($pg->is_qr_payment_enabled ?? false) && !empty($pg->qr_code_image_url)) {
            $options[] = 'qr';
        }

        if (!empty($this->offlinePaymentMethods) && count($this->offlinePaymentMethods) > 0) {
            foreach ($this->offlinePaymentMethods as $method) {
                if (!empty($method?->name)) {
                    $options[] = 'offline:' . $method->name;
                }
            }
        }

        return $options;
    }

    private function isOnlinePaymentRequired(): bool
    {
        $pg = $this->paymentGateway;

        if (!$pg) {
            return false;
        }

        $slug = $this->orderTypeSlug ?? $this->orderType;

        return match ($slug) {
            'dine_in' => (bool) $pg->is_dine_in_payment_enabled,
            'delivery' => (bool) $pg->is_delivery_payment_enabled,
            'pickup' => (bool) $pg->is_pickup_payment_enabled,
            default => false,
        };
    }

    private function autoStartSinglePaymentOption(int $orderId): mixed
    {
        $options = $this->getActivePaymentOptions();

        if (count($options) !== 1) {
            return false;
        }

        $option = $options[0];

        if ($option === 'qr') {
            $this->showPaymentModal = true;
            $this->showQrCode = true;
            $this->showPaymentDetail = false;
            $this->selectedOfflinePaymentMethod = null;
            return true;
        }

        if (str_starts_with($option, 'offline:')) {
            $methodName = Str::after($option, 'offline:');
            $this->showPaymentModal = true;
            $this->showQrCode = false;
            $this->showPaymentDetail = true;
            $this->selectedOfflinePaymentMethod = $methodName;
            return true;
        }

        // Online gateways: trigger directly (no gateway chooser modal).
        $this->showPaymentModal = false;

        $result = match ($option) {
            'stripe' => $this->initiateStripePayment($orderId),
            'razorpay' => $this->initiatePayment($orderId),
            'flutterwave' => $this->initiateFlutterwavePayment($orderId),
            'paypal' => $this->initiatePaypalPayment($orderId),
            'payfast' => $this->initiatePayfastPayment($orderId),
            'paystack' => $this->initiatePaystackPayment($orderId),
            'xendit' => $this->initiateXenditPayment($orderId),
            'epay' => $this->initiateEpayPayment($orderId),
            'mollie' => $this->initiateMolliePayment($orderId),
            'tap' => $this->initiateTapPayment($orderId),
            'tlync' => $this->initiateTlyncPayment($orderId),
            default => null,
        };

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        return true;
    }
    /** When true, order-type choice is handled by the client catalog UI (not Livewire modal). */
    public bool $pendingClientOrderTypeSelection = false;

    /** Set after the customer explicitly picks an order type (chips, modal, or single-type auto). */
    public bool $orderTypeConfirmedByUser = false;

    private bool $clientShopCatalogResolved = false;

    private ?array $clientShopCatalogCache = null;

    // Loyalty properties - defined here so they exist even if trait doesn't
    public $loyaltyPointsRedeemed = 0;
    public $loyaltyDiscountAmount = 0;
    public $availableLoyaltyPoints = 0;
    public $pointsToRedeem = 0;
    public $maxRedeemablePoints = 0;
    public $minRedeemPoints = 0;
    public $showLoyaltyRedemptionModal = false;
    public $loyaltyPointsValue = 0;
    public $maxLoyaltyDiscount = 0;

    public function mount()
    {
        if ($this->tableID) {
            $this->table = Table::where('hash', $this->tableID)->firstOrFail();
            $restaurant = $this->table->branch->restaurant;

            $fetchActiveOrder = Order::where('table_id', $this->table->id)->where('status', 'kot')->whereDate('date_time', '=', now($restaurant->timezone)->toDateString())->first();

            if ($fetchActiveOrder) {
                $this->orderID = $fetchActiveOrder->id;
                $this->order = $fetchActiveOrder;
            }

            $this->restaurant = $restaurant;
            $this->restaurantHash = $restaurant->hash;
        }

        if ($this->roomID) {
            if (! function_exists('module_enabled') || ! module_enabled('Hotel')) {
                abort(404);
            }

            $this->room = \Modules\Hotel\Entities\Room::with(['currentStay', 'branch.restaurant', 'restaurant'])
                ->where('hash', $this->roomID)
                ->firstOrFail();

            $restaurant = $this->room->restaurant ?? $this->room->branch->restaurant;

            if (! isHotelModuleEnabled($restaurant)) {
                abort(404);
            }

            $this->hotelStayId = $this->room->currentStay?->id;
            $this->restaurant = $restaurant;
            $this->restaurantHash = $restaurant->hash;
        }

        if (!$this->restaurant) {
            abort(404);
        }

        if ($this->shouldRedirectToBookTable()) {
            $this->redirect(
                route('book_a_table', ['hash' => $this->restaurant->hash]) . '?branch=' . $this->shopBranch->id,
                navigate: true,
            );

            return;
        }

        $this->restaurant->loadMissing('euAllergenSetting');

        $this->mapApiKey = global_setting()->google_map_api_key ?? null;
        $this->mapProvider = global_setting()->map_provider ?? 'google';

        // Detect if user came from QR code (table QR, room QR, or universal restaurant QR)
        $this->cameFromTableQR = ! is_null($this->tableID);
        $this->cameFromRoomQR = isHotelModuleEnabled($this->restaurant) && $this->room && ! is_null($this->roomID);
        $this->cameFromQR = request()->query('hash') === $this->restaurant->hash ||
                           request()->boolean('from_qr') ||
                           $this->cameFromTableQR ||
                           $this->cameFromRoomQR;
        $this->paymentGateway = PaymentGatewayCredential::withoutGlobalScopes()->where('restaurant_id', $this->restaurant->id)->first();
        $this->taxes = Tax::withoutGlobalScopes()->where('branch_id', $this->shopBranch->id)->get();

        // Load enabled offline payment methods
        $this->offlinePaymentMethods = OfflinePaymentMethod::where('restaurant_id', $this->restaurant->id)->where('status', 'active')->orderBy('created_at', 'desc')->get();
        // Load additional charges for this restaurant (only enabled ones)
        $this->extraCharges = RestaurantCharge::withoutGlobalScopes()
            ->where('restaurant_id', $this->restaurant->id)
            ->where('is_enabled', true)
            ->get();
        $this->customer = customer();
        $this->customerId = $this->customer?->id; // Set customerId for loyalty integration trait

        // Load loyalty points if module is enabled and customer is logged in
        if ($this->isLoyaltyEnabled() && $this->customerId) {
            try {
                if (module_enabled('Loyalty')) {
                    $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                    $restaurantId = restaurant()->id;
                    $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $this->customerId);
                    $this->updateLoyaltyValues();
                }
            } catch (\Exception $e) {
                // Silently fail if module doesn't exist
            }
        }

        $this->razorpayStatus = (bool)($this->paymentGateway->razorpay_status ?? false);
        $this->stripeStatus = (bool)($this->paymentGateway->stripe_status ?? false);

        // Initialize phone codes
        $this->allPhoneCodes = collect(Country::pluck('phonecode')->unique()->filter()->values());
        $this->filteredPhoneCodes = $this->allPhoneCodes;
        $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
        $this->phoneCodeDetected = empty($this->customer?->phone_code) && !empty($detectedPhoneCode);
        $this->customerPhoneCode = $this->customer?->phone_code
            ?? $detectedPhoneCode
            ?? $this->restaurant->phone_code
            ?? $this->allPhoneCodes->first();

        // Table QR: lock dine-in when enabled for customers; universal QR uses normal order-type flow
        if ($this->cameFromTableQR && $this->isCustomerDineInEnabled()) {
            session(['shop_force_dine_in' => true]);
            session()->forget('shop_table_qr_ordering_blocked');

            $dineInType = OrderType::query()
                ->where('branch_id', $this->shopBranch->id)
                ->where('enable_from_customer_site', true)
                ->where('type', 'dine_in')
                ->availableForRestaurant()
                ->orderByDesc('is_default')
                ->first();

            if ($dineInType) {
                $this->applyOrderTypeId((int) $dineInType->id);
            } else {
                $this->orderType = 'dine_in';
                $this->setDefaultOrderType();
            }

            $this->showOrderTypeModal = false;
            $this->pendingClientOrderTypeSelection = false;
            $this->orderTypeConfirmedByUser = true;
        } elseif ($this->cameFromRoomQR) {
            session()->forget('shop_force_dine_in');
            session()->forget('shop_table_qr_ordering_blocked');
            session()->forget('shop_room_qr_ordering_blocked');

            if ($this->isCustomerRoomServiceEnabled()) {
                $roomServiceType = OrderType::query()
                    ->where('branch_id', $this->shopBranch->id)
                    ->where('enable_from_customer_site', true)
                    ->where(function ($query) {
                        $query->where('type', 'room_service')
                            ->orWhere('slug', 'room_service');
                    })
                    ->availableForRestaurant()
                    ->orderByDesc('is_default')
                    ->first();

                $this->applyOrderTypeId((int) $roomServiceType->id);

                $this->showOrderTypeModal = false;
                $this->pendingClientOrderTypeSelection = false;
                $this->orderTypeConfirmedByUser = true;
            } else {
                $this->roomQrOrderingBlocked = true;
                session(['shop_room_qr_ordering_blocked' => true]);
            }
        } else {
            session()->forget('shop_force_dine_in');
            session()->forget('shop_room_qr_ordering_blocked');

            if ($this->cameFromTableQR) {
                $this->tableQrOrderingBlocked = true;
                session(['shop_table_qr_ordering_blocked' => true]);
            } else {
                session()->forget('shop_table_qr_ordering_blocked');
            }

            $this->applyCustomerSiteOrderTypeDefaults();
        }

        if (request()->has('current_order')) {
            $this->orderID = request()->get('current_order');
            $this->order = Order::find($this->orderID);
            if ($this->order->status == 'paid') {
                $this->redirect(module_enabled('Subdomain') ? url('/') : route('shop_restaurant', ['hash' => $this->order->branch->restaurant->hash]));
            }
        }

        if ($this->shouldResetBrowseCartForNewOrder()) {
            $this->clearClientBrowseCart();
        }

        // Fetch QR code image from database
        $this->qrCodeImage = $this->restaurant->qr_code_image;

        // Only call these if order type is already selected (skip while client JS order-type picker is open)
        if (!$this->showOrderTypeModal && !$this->pendingClientOrderTypeSelection) {
            $this->updatedOrderType($this->orderType);
        }
        $this->taxMode = $this->restaurant->tax_mode ?? 'order';

        $this->pickupRange = $this->restaurant->pickup_days_range ?? 1;
        $dateFormat = $this->restaurant->date_format ?? 'd-m-Y';
        $restaurantTz = $this->restaurant->timezone ?? config('app.timezone');
        $this->minDate = now($restaurantTz)->displayDate($dateFormat);
        $this->maxDate = now($restaurantTz)->addDays($this->pickupRange - 1)->endOfDay()->displayDate($dateFormat);

        // Initialize pickupDate and pickupTime from deliveryDateTime if it exists (restaurant timezone)
        if ($this->deliveryDateTime) {
            try {
                $dateTime = \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $this->deliveryDateTime, $restaurantTz);
                $this->pickupDate = $dateTime->displayDate($dateFormat);
                $this->pickupTime = $dateTime->format('H:i');
            } catch (\Exception $e) {
                $this->pickupDate = now($restaurantTz)->displayDate($dateFormat);
                $this->pickupTime = now($restaurantTz)->format('H:i');
            }
        } else {
            $this->pickupDate = now($restaurantTz)->displayDate($dateFormat);
            $this->pickupTime = now($restaurantTz)->format('H:i');
        }

        $this->defaultDate = old('deliveryDateTime', $this->deliveryDateTime ?? $this->minDate);

        $this->taxMode = $this->order?->tax_mode ?? ($this->restaurant->tax_mode ?? 'order');

        // Initialize header settings
        $this->initializeHeaderSettings();

        // Handle location for QR orders only when restrictions are enabled
        if ($this->cameFromQR && $this->restaurant->restrict_qr_order_by_location && !empty($this->restaurant->qr_order_radius_meters)) {
            // Check session first for stored location
            $sessionLocation = session('customer_location');

            if ($sessionLocation && !empty($sessionLocation['lat']) && !empty($sessionLocation['lng'])) {
                // Load location from session
                $this->addressLat = $sessionLocation['lat'];
                $this->addressLng = $sessionLocation['lng'];
                if (!empty($sessionLocation['address'])) {
                    $this->deliveryAddress = $sessionLocation['address'];
                }

                // Re-validate radius if needed
                $this->checkRadiusRestriction();
            } else {
                // Show location modal only if came from QR, restrictions enabled, and no session location
                if (empty($this->addressLat) || empty($this->addressLng)) {
                    $this->showLocationModal = true;
                }
            }
        }

        if (! $this->pendingClientOrderTypeSelection && ! $this->showOrderTypeModal && (int) ($this->orderTypeId ?? 0) > 0) {
            $this->applySimpleBrowseSessionCartMerge();
        }

        if (!$this->showOrderTypeModal && !$this->pendingClientOrderTypeSelection && $this->isDeliveryOrder()) {
            $this->promptDeliveryLocationIfNeeded();
        }
    }

    // Loyalty methods - use trait if available, otherwise stub methods handle it

    /**
     * Check if loyalty module is enabled (stub method if trait doesn't exist)
     */
    public function isLoyaltyEnabled()
    {
        // Check if module is enabled
        if (!module_enabled('Loyalty')) {
            return false;
        }

        // Check if module is in restaurant's package
        if (function_exists('restaurant_modules')) {
            $restaurantModules = restaurant_modules();
            if (!in_array('Loyalty', $restaurantModules)) {
                return false;
            }
        }

        // Check platform-specific setting for Customer Site
        try {
            if (module_enabled('Loyalty')) {
                $restaurantId = restaurant()->id ?? null;
                if ($restaurantId) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    return $settings->enabled && ($settings->enable_for_customer_site ?? true);
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    /**
     * Update loyalty values (stub method if trait doesn't exist)
     */
    public function updateLoyaltyValues()
    {
        if (!$this->isLoyaltyEnabled() || !$this->customerId) {
            return;
        }

        // If module exists, implement the logic directly
        if (module_enabled('Loyalty')) {
            try {
                $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                $restaurantId = restaurant()->id;
                $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $this->customerId);

                // Load loyalty settings
                if (module_enabled('Loyalty')) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);

                    if ($settings && $settings->isEnabled()) {
                        $valuePerPoint = $settings->value_per_point ?? 1;
                        $maxDiscountPercent = $settings->max_discount_percent ?? 0;

                        // Calculate loyalty points value (total value of all available points)
                        $this->loyaltyPointsValue = $this->availableLoyaltyPoints * $valuePerPoint;

                        // Calculate max discount (percentage of subtotal)
                        $this->maxLoyaltyDiscount = ($this->subTotal * $maxDiscountPercent) / 100;

                        // Calculate max redeemable points based on max discount
                        $this->maxRedeemablePoints = $this->maxLoyaltyDiscount > 0 ? floor($this->maxLoyaltyDiscount / $valuePerPoint) : 0;
                        $this->minRedeemPoints = $settings->min_redeem_points ?? 0;
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error updating loyalty values: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reset loyalty redemption (stub method if trait doesn't exist)
     */
    public function resetLoyaltyRedemption()
    {
        if (module_enabled('Loyalty')) {
            $traits = class_uses_recursive(static::class);
            if (in_array(\Modules\Loyalty\Traits\HasLoyaltyIntegration::class, $traits)) {
                // Trait exists and is used, it will handle this
                return;
            }
        }
        // Stub: reset loyalty properties to defaults
        $this->loyaltyPointsRedeemed = 0;
        $this->loyaltyDiscountAmount = 0;
        $this->availableLoyaltyPoints = 0;
        $this->pointsToRedeem = 0;
        $this->maxRedeemablePoints = 0;
        $this->minRedeemPoints = 0;
        $this->showLoyaltyRedemptionModal = false;
    }

    /**
     * Get loyalty order data for saving to database (stub method if trait doesn't exist)
     */
    public function getLoyaltyOrderData()
    {
        if (module_enabled('Loyalty')) {
            $traits = class_uses_recursive(static::class);
            if (in_array(\Modules\Loyalty\Traits\HasLoyaltyIntegration::class, $traits)) {
                // Trait exists and is used, try to call trait method if it exists
                if (method_exists($this, 'traitGetLoyaltyOrderData')) {
                    return $this->traitGetLoyaltyOrderData();
                }
            }
        }
        // Stub: return empty array if module doesn't exist
        return [
            'loyalty_points_redeemed' => $this->loyaltyPointsRedeemed ?? 0,
            'loyalty_discount_amount' => $this->loyaltyDiscountAmount ?? 0,
        ];
    }

    /**
     * Set default order type and ID
     */
    private function setDefaultOrderType()
    {
        // Get default order type for the current order type
        $orderTypeModel = OrderType::where('branch_id', $this->shopBranch->id)
            ->where('type', $this->orderType)
            ->first();

        if ($orderTypeModel) {
            $this->orderTypeId = $orderTypeModel->id;
            $this->orderTypeSlug = $orderTypeModel->slug;
        }
    }

    public function requestCustomerLocation()
    {
        $this->dispatch('requestGeolocation');
    }

    private function isDeliveryOrder(): bool
    {
        return $this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery';
    }

    private function loadSavedDeliveryLocation(): void
    {
        if (!empty($this->addressLat) && !empty($this->addressLng)) {
            return;
        }

        $sessionLocation = session('customer_location');

        if (!empty($sessionLocation['lat']) && !empty($sessionLocation['lng'])) {
            $this->addressLat = $sessionLocation['lat'];
            $this->addressLng = $sessionLocation['lng'];
            $this->deliveryAddress = $sessionLocation['address'] ?? $this->deliveryAddress;
            $this->customerAddress = $this->deliveryAddress;

            return;
        }

        if (!$this->customer) {
            return;
        }

        $savedAddress = CustomerAddress::query()
            ->where('customer_id', $this->customer->id)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByDesc('id')
            ->first();

        if ($savedAddress) {
            $this->addressLat = $savedAddress->lat;
            $this->addressLng = $savedAddress->lng;
            $this->deliveryAddress = $savedAddress->address;
            $this->customerAddress = $savedAddress->address;

            return;
        }

        if ($this->customer->delivery_address) {
            $this->deliveryAddress = $this->customer->delivery_address;
            $this->customerAddress = $this->customer->delivery_address;
        }
    }

    private function promptDeliveryLocationIfNeeded(): void
    {
        if (!$this->isDeliveryOrder()) {
            return;
        }

        $this->loadSavedDeliveryLocation();

        if (!empty($this->addressLat) && !empty($this->addressLng)) {
            return;
        }

        $this->showLocationModal = true;
    }

    private function persistCustomerDeliveryLocation(): void
    {
        if (!$this->customer || empty($this->addressLat) || empty($this->addressLng)) {
            return;
        }

        $address = trim((string) ($this->deliveryAddress ?? $this->customerAddress ?? ''));

        if ($address === '') {
            $address = $this->addressLat . ', ' . $this->addressLng;
        }

        $this->deliveryAddress = $address;
        $this->customerAddress = $address;

        try {
            $this->customer->delivery_address = $address;
            $this->customer->save();

            $alreadyExists = CustomerAddress::query()
                ->where('customer_id', $this->customer->id)
                ->where('address', $address)
                ->when(!empty($this->addressLat) && !empty($this->addressLng), function ($q) {
                    $q->where('lat', $this->addressLat)->where('lng', $this->addressLng);
                })
                ->exists();

            if ($alreadyExists) {
                return;
            }

            $maxAddresses = 3;
            $count = CustomerAddress::query()->where('customer_id', $this->customer->id)->count();

            if ($count >= $maxAddresses) {
                return;
            }

            $hasHome = CustomerAddress::query()
                ->where('customer_id', $this->customer->id)
                ->where('label', 'Home')
                ->exists();

            CustomerAddress::create([
                'customer_id' => $this->customer->id,
                'label' => $hasHome ? 'Delivery' : 'Home',
                'address' => $address,
                'lat' => $this->addressLat,
                'lng' => $this->addressLng,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Cart: failed saving customer delivery location', [
                'customer_id' => $this->customer?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    /**
     * Haversine formula to calculate distance between two points (meters)
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000; // meters
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);
        $latDiff = $lat2 - $lat1;
        $lngDiff = $lng2 - $lng1;
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos($lat1) * cos($lat2) * sin($lngDiff / 2) * sin($lngDiff / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Check if customer is within allowed radius for QR orders
     * Returns true if allowed, false if restricted
     */
    private function checkRadiusRestriction()
    {
        // Early return if restrictions don't apply
        if (!$this->cameFromQR || !$this->restaurant->restrict_qr_order_by_location || empty($this->restaurant->qr_order_radius_meters)) {
            return true; // No restriction, allow
        }

        if (empty($this->addressLat) || empty($this->addressLng)) {
            $this->showLocationModal = true;
            return false; // Location required
        }

        // Get branch coordinates
        $branchLat = $this->shopBranch->lat ?? $this->shopBranch->latitude ?? null;
        $branchLng = $this->shopBranch->lng ?? $this->shopBranch->longitude ?? null;

        if (empty($branchLat) || empty($branchLng)) {
            return true; // Allow if branch coordinates missing
        }

        // Calculate distance
        $distance = $this->calculateDistance(
            $this->addressLat, $this->addressLng,
            $branchLat, $branchLng
        );

        $this->is_within_radius = $distance <= $this->restaurant->qr_order_radius_meters;

        return $this->is_within_radius;
    }

    private function isCartIngredientStockBlocked(): bool
    {
        if (! $this->shopBranch) {
            return false;
        }

        $result = IngredientStockGuard::validateCartFromOrderState(
            $this->shopBranch->id,
            $this->orderItemList ?? [],
            $this->orderItemQty ?? [],
            $this->orderItemVariation ?? [],
            $this->itemModifiersSelected ?? [],
        );

        if ($result['ok']) {
            return false;
        }

        $this->alert('error', $result['error'] ?? IngredientStockGuard::insufficientStockMessage(), [
            'toast' => false,
            'position' => 'center',
            'showCancelButton' => true,
            'cancelButtonText' => __('app.close'),
        ]);

        return true;
    }

    private function isIngredientStockBlockedForLine(
        int $menuItemId,
        ?int $menuItemVariationId,
        array $modifierOptionIds,
        int $quantity,
    ): bool {
        if (! $this->shopBranch) {
            return false;
        }

        $result = IngredientStockGuard::validateOrderLine(
            $this->shopBranch->id,
            $menuItemId,
            $menuItemVariationId,
            $modifierOptionIds,
            $quantity,
        );

        if ($result['ok']) {
            return false;
        }

        $this->alert('error', $result['error'] ?? IngredientStockGuard::insufficientStockMessage(), [
            'toast' => false,
            'position' => 'center',
            'showCancelButton' => true,
            'cancelButtonText' => __('app.close'),
        ]);

        return true;
    }

    public function setCustomerLocation($lat = null, $lng = null, $address = null)
    {
        if (is_null($lat) || is_null($lng)) {
            return;
        }

        $this->addressLat = $lat;
        $this->addressLng = $lng;
        if ($address) {
            $this->deliveryAddress = $address;
            $this->customerAddress = $address;
        }

        // Store location in session for current customer/order
        session([
            'customer_location' => [
                'lat' => $lat,
                'lng' => $lng,
                'address' => $address ?? $this->deliveryAddress,
                'stored_at' => now()->toDateTimeString(),
            ]
        ]);

        $this->showLocationModal = false;

        if ($this->isDeliveryOrder()) {
            $this->persistCustomerDeliveryLocation();

            $this->dispatch('initCartCustomerAddressMap', [
                'lat' => $this->addressLat,
                'lng' => $this->addressLng,
                'address' => $this->deliveryAddress,
            ]);

            if ($address) {
                $this->alert('success', __('app.deliveryLocationSaved'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
            }
        }

        // QR order radius enforcement
        if ($this->cameFromQR && !empty($this->restaurant->qr_order_radius_meters)) {
            // Check both lat/lng and latitude/longitude column names for branch
            $branchLat = $this->shopBranch->lat ?? $this->shopBranch->latitude ?? null;
            $branchLng = $this->shopBranch->lng ?? $this->shopBranch->longitude ?? null;

            if (!empty($branchLat) && !empty($branchLng)) {
                $distance = $this->calculateDistance(
                    $lat, $lng,
                    $branchLat,
                    $branchLng
                );
                $this->is_within_radius = $distance <= $this->restaurant->qr_order_radius_meters;

                if (!$this->is_within_radius) {
                    $this->alert('error', __('app.outsideAllowedArea'), [
                        'toast' => false,
                        'position' => 'center',
                    ]);

                    // Clear any existing cart items if outside radius
                    $this->orderItemList = [];
                    $this->orderItemQty = [];
                    $this->orderItemVariation = [];
                    $this->orderItemAmount = [];
                    $this->cartItemQty = [];
                    $this->orderItemModifiersPrice = [];
                    $this->itemModifiersSelected = [];
                    $this->itemNotes = [];
                    $this->calculateTotal();
                }
            } else {
                // If branch coordinates are missing, allow by default
                $this->is_within_radius = true;
            }
        } else {
            $this->is_within_radius = true;
        }

        // Recalculate delivery-related estimates if needed
        $this->calculateMaxPreparationTime();
        $this->calculateTotal();
    }

    /**
     * Handle order type change and update pricing context
     */
    public function updatedOrderTypeId($value)
    {
        if (!$value || $this->shouldForceDineInFromQr()) {
            return;
        }

        $this->applyOrderTypeId((int) $value);
    }

    /**
     * Hotel room context for orders placed via room QR scan.
     *
     * @return array<string, mixed>
     */
    private function buildHotelRoomOrderContext(): array
    {
        if (! isHotelModuleEnabled($this->restaurant) || ! $this->cameFromRoomQR || ! $this->room) {
            return [];
        }

        $context = [
            'hotel_room_id' => $this->room->id,
        ];

        $stayId = $this->room->currentStay()->value('id') ?? $this->hotelStayId;
        if ($stayId) {
            $context['context_type'] = 'HOTEL_ROOM';
            $context['context_id'] = $stayId;
            $context['bill_to'] = 'POST_TO_ROOM';
        }

        return $context;
    }

    /**
     * Apply order type id to cart state (charges, line pricing, totals).
     */
    private function applyOrderTypeId(int $value): void
    {
        if ($value <= 0) {
            return;
        }

        $orderType = OrderType::find($value);

        if (!$orderType) {
            return;
        }

        $this->orderTypeId = $value;
        $this->orderType = $orderType->type;
        $this->orderTypeSlug = $orderType->slug;

        // Get extra charges for this order type
        $mainExtraCharges = RestaurantCharge::withoutGlobalScopes()
            ->whereJsonContains('order_types', $this->orderTypeSlug)
            ->where('is_enabled', true)
            ->where('restaurant_id', $this->restaurant->id)
            ->get();

        // Update extra charges
        if (!$this->orderID) {
            // Only clear delivery-related fields if the order type is not delivery
            if ($this->orderTypeSlug !== 'delivery') {
                $this->addressLat = null;
                $this->addressLng = null;
                $this->deliveryAddress = null;
                $this->deliveryFee = null;
            }

            $this->calculateMaxPreparationTime();
            $this->extraCharges = $mainExtraCharges;
        } else {
            // For existing orders, keep existing charges if order type is unchanged
            $orderTypeFromOrder = $this->order->order_type_id
                ? (OrderType::where('id', $this->order->order_type_id)->value('slug') ?? $this->order->order_type)
                : $this->order->order_type;

            $this->extraCharges = $orderTypeFromOrder === $this->orderTypeSlug ? $this->order->extraCharges : $mainExtraCharges;
        }

        // Recalculate prices for all items in cart when order type changes
        foreach ($this->orderItemList ?? [] as $key => $item) {
            if ($this->orderTypeId) {
                $item->setPriceContext($this->orderTypeId, null);
                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, null);
                }
            }

            // Recalculate modifier prices
            if (isset($this->itemModifiersSelected[$key]) && is_array($this->itemModifiersSelected[$key])) {
                $modifierPrice = 0;
                foreach ($this->itemModifiersSelected[$key] as $modifierId) {
                    $modifier = ModifierOption::find($modifierId);
                    if ($modifier) {
                        if ($this->orderTypeId) {
                            $modifier->setPriceContext($this->orderTypeId, null);
                        }
                        $modifierPrice += $modifier->price;
                    }
                }
                $this->orderItemModifiersPrice[$key] = $modifierPrice;
            }

            // Recalculate item amount
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $item->price;
            $this->orderItemAmount[$key] = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
        }

        $this->calculateTotal();

        $this->dispatchShopClientOrderTypeChanged();

        if ($this->orderTypeSlug === 'delivery') {
            $this->promptDeliveryLocationIfNeeded();
        }
    }

    public function hydrate(): void
    {
        $pendingRaw = session()->pull('shop_browse_order_type_id');

        if ($pendingRaw) {
            $pendingId = (int) $pendingRaw;

            if ($pendingId > 0) {
                if (session('shop_force_dine_in')) {
                    $pendingType = OrderType::query()
                        ->where('id', $pendingId)
                        ->where('branch_id', $this->shopBranch->id)
                        ->where('type', 'dine_in')
                        ->first();

                    if ($pendingType) {
                        $this->applyOrderTypeId($pendingId);
                    }

                    $this->pendingClientOrderTypeSelection = false;
                } elseif ($pendingId === (int) ($this->orderTypeId ?? 0)) {
                    $this->pendingClientOrderTypeSelection = false;
                } else {
                    $this->applyOrderTypeId($pendingId);
                    $this->pendingClientOrderTypeSelection = false;
                }
            }
        }

        if (! $this->pendingClientOrderTypeSelection && (int) ($this->orderTypeId ?? 0) > 0) {
            $this->applySimpleBrowseSessionCartMerge();
        }
    }

    /**
     * Apply browse-cart session lines after a fetch mutation (dispatched from Alpine; no $wire.$call).
     */
    #[On('shop-browse-merge-cart-from-session')]
    public function mergeBrowseCartFromSession(): void
    {
        $this->applySimpleBrowseSessionCartMerge();
    }

    /**
     * Handle order type selection from modal
     */
    public function selectOrderTypeFromModal($orderTypeId)
    {
        if (!$orderTypeId || $this->shouldForceDineInFromQr()) {
            return;
        }

        $this->applyOrderTypeId((int) $orderTypeId);
        $this->showOrderTypeModal = false;
        $this->pendingClientOrderTypeSelection = false;
        $this->orderTypeConfirmedByUser = true;
    }

    /**
     * Handle calls from the wire:ignored client catalog (Alpine) via Livewire.dispatchTo.
     *
     * @param  mixed  $detail  Event detail from the browser (shape varies by Livewire version)
     */
    #[On('client-menu-remote')]
    public function clientMenuRemote(mixed $detail = null): void
    {
        $payload = $detail;

        if (is_array($payload) && array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        if (! is_array($payload)) {
            return;
        }

        $method = $payload['method'] ?? null;
        $args = $payload['args'] ?? [];

        if (! is_string($method) || $method === '') {
            return;
        }

        if (is_array($args)) {
            $args = array_values($args);
        } elseif (is_object($args)) {
            $args = array_values((array) $args);
        } else {
            return;
        }

        match ($method) {
            'showItemDetail' => $this->showItemDetail((int) ($args[0] ?? 0)),
            'addQty' => $this->addQty((string) ($args[0] ?? '')),
            'subQty' => $this->subQty((string) ($args[0] ?? '')),
            'addCartItems' => $this->addCartItems(
                (int) ($args[0] ?? 0),
                (int) ($args[1] ?? 0),
                (int) ($args[2] ?? 0),
            ),
            'subCartItems' => $this->subCartItems((int) ($args[0] ?? 0)),
            'subModifiers' => $this->subModifiers((int) ($args[0] ?? 0)),
            'showItemVariations' => $this->showItemVariations((int) ($args[0] ?? 0)),
            'selectOrderTypeFromModal' => $this->selectOrderTypeFromModal($args[0] ?? null),
            default => null,
        };
    }

    private function isPlainNumericCartKey(mixed $cartKey): bool
    {
        if (is_int($cartKey)) {
            return $cartKey > 0;
        }

        return is_string($cartKey) && ctype_digit($cartKey) && (int) $cartKey > 0;
    }

    private function cartHasVariationLineForMenuItem(int $menuItemId): bool
    {
        $prefix = $menuItemId . '_';

        foreach (array_keys($this->orderItemList ?? []) as $cartKey) {
            if (is_string($cartKey) && str_starts_with($cartKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function purgeSimpleBrowseLine(int $menuItemId): void
    {
        unset(
            $this->orderItemList[$menuItemId],
            $this->orderItemVariation[$menuItemId],
            $this->orderItemAmount[$menuItemId],
            $this->orderItemQty[$menuItemId],
            $this->orderItemModifiersPrice[$menuItemId],
            $this->itemNotes[$menuItemId],
            $this->cartItemQty[$menuItemId],
        );
    }

    private function ensureSimpleBrowseLineQty(int $menuItemId, int $qty): void
    {
        if ($qty < 1) {
            return;
        }

        $item = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->where('show_on_customer_site', true)
            ->where('branch_id', $this->shopBranch->id)
            ->find($menuItemId);

        if (! $item) {
            return;
        }

        if ($this->orderTypeId) {
            $item->setPriceContext((int) $this->orderTypeId, null);
        }

        $this->orderItemList[$menuItemId] = $item;
        $this->orderItemQty[$menuItemId] = $qty;
        $this->cartItemQty[$menuItemId] = $qty;
        unset($this->orderItemVariation[$menuItemId]);

        $basePrice = $this->orderItemList[$menuItemId]->price;
        $this->orderItemAmount[$menuItemId] = $qty * ($basePrice + ($this->orderItemModifiersPrice[$menuItemId] ?? 0));
        $this->itemNotes[$menuItemId] = $this->itemNotes[$menuItemId] ?? '';
    }

    private function applySimpleBrowseSessionCartMerge(): void
    {
        if (! $this->restaurant?->hash) {
            return;
        }

        $key = BrowseCartMutator::sessionKey($this->restaurant->hash);

        if (! session()->has($key)) {
            return;
        }

        $map = session($key, []);
        if (! is_array($map)) {
            $map = [];
        }

        $normalized = [];
        foreach ($map as $k => $v) {
            $ki = (int) $k;
            if ($ki < 1) {
                continue;
            }
            $normalized[$ki] = (int) $v;
        }

        foreach (array_keys($this->orderItemList ?? []) as $cartKey) {
            if (! $this->isPlainNumericCartKey($cartKey)) {
                continue;
            }
            $mid = (int) $cartKey;
            if ($this->cartHasVariationLineForMenuItem($mid)) {
                continue;
            }
            $want = $normalized[$mid] ?? 0;
            if ($want < 1) {
                $this->purgeSimpleBrowseLine($mid);
            }
        }

        foreach ($normalized as $mid => $want) {
            if ($want < 1) {
                continue;
            }
            if ($this->cartHasVariationLineForMenuItem((int) $mid)) {
                continue;
            }
            $this->ensureSimpleBrowseLineQty((int) $mid, $want);
        }

        $this->calculateTotal();
    }

    private function syncBrowseSimpleCartSessionFromComponent(): void
    {
        if (! $this->restaurant?->hash) {
            return;
        }

        $key = BrowseCartMutator::sessionKey($this->restaurant->hash);
        $map = [];

        foreach ($this->cartItemQty ?? [] as $k => $q) {
            if (! $this->isPlainNumericCartKey($k)) {
                continue;
            }
            $mid = (int) $k;
            if ($this->cartHasVariationLineForMenuItem($mid)) {
                continue;
            }
            $qi = (int) $q;
            if ($mid < 1 || $qi < 1) {
                continue;
            }
            $map[(string) $mid] = $qi;
        }

        session([$key => $map]);
    }

    /**
     * Fresh customer order from menu (clears JS browse cart + Livewire cart state).
     */
    public function startNewShopOrder(): void
    {
        $this->clearClientBrowseCart();
    }

    private function shouldResetBrowseCartForNewOrder(): bool
    {
        return request()->boolean('new_order') && ! request()->has('current_order');
    }

    private function clearClientBrowseCart(): void
    {
        if ($this->restaurant?->hash) {
            session()->forget(BrowseCartMutator::sessionKey($this->restaurant->hash));
        }

        $this->orderItemList = [];
        $this->orderItemVariation = [];
        $this->orderItemQty = [];
        $this->cartItemQty = [];
        $this->orderItemAmount = [];
        $this->orderItemModifiersPrice = [];
        $this->itemModifiersSelected = [];
        $this->itemNotes = [];
        $this->orderItemTaxDetails = [];
        $this->subTotal = 0;
        $this->total = 0;
        $this->totalTaxAmount = 0;
        $this->taxBase = 0;
        $this->cartQty = 0;
        $this->showCart = false;
        $this->showMenu = true;

        $this->calculateTotal();
        $this->dispatch('shop-client-cart-reset');
        $this->dispatch('shop-client-cart-qty-sync', cartItemQty: []);
        $this->dispatch('shop-client-show-menu', showMenu: true);
    }

    public function initializeHeaderSettings()
    {
        $cartHeaderSetting = CartHeaderSetting::withoutGlobalScope(RestaurantScope::class)
            ->where('restaurant_id', $this->restaurant->id)
            ->with('images')
            ->first();

        if ($cartHeaderSetting) {
            $this->headerType = $cartHeaderSetting->header_type;
            $this->headerText = $cartHeaderSetting->header_text;
            $this->isHeaderDisabled = (bool) ($cartHeaderSetting->is_header_disabled ?? false);
            $this->headerImages = $cartHeaderSetting->images
                ->map(fn ($image) => [
                    'id' => $image->id,
                    'image_url' => $image->image_url,
                    'alt_text' => $image->alt_text,
                ])
                ->values()
                ->all();
        } else {
            $this->headerType = 'text';
            $this->headerText = __('messages.frontHeroHeading');
            $this->headerImages = [];
            $this->isHeaderDisabled = false;
        }
    }

    public function filterMenuItems($id)
    {
        $this->menuId = $id;
        $this->resetMenuItemsLoaded();
    }

    public function filterMenu($id = null)
    {
        $this->filterCategories = $id;
        $this->resetMenuItemsLoaded();
    }

    // Reset loaded items when search changes
    public function updatedSearch()
    {
        $this->resetMenuItemsLoaded();
    }

    // Reset loaded items when veg filter changes
    public function updatedShowVeg()
    {
        $this->resetMenuItemsLoaded();
    }

    // Reset loaded items when halal filter changes
    public function updatedShowHalal()
    {
        $this->resetMenuItemsLoaded();
    }

    // Helper method to reset menu items loaded
    private function resetMenuItemsLoaded()
    {
        $this->menuItemsLoaded = $this->menuItemsPerLoad;
    }

    public function showItemVariations($id)
    {
        $this->showItemVariationsModal = true;
        $this->menuItem = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)->where('show_on_customer_site', true)->findOrFail($id);
    }

    public function addCartItems($id, $variationCount, $modifierCount)
    {
        if (is_numeric($id)) {
            $id = (int) $id;
        }
        $variationCount = (int) $variationCount;
        $modifierCount = (int) $modifierCount;

        if ($this->tableQrOrderingBlocked) {
            $this->alert('error', __('messages.tableQrDineInDisabled'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        if ($this->roomQrOrderingBlocked) {
            $this->alert('error', __('messages.roomQrRoomServiceDisabled'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        if (
            ! $this->orderTypeConfirmedByUser
            && $this->getOrderTypesProperty()->count() > 1
            && ! $this->shouldForceDineInFromQr()
            && ! $this->isCustomerOrderTypePopupDisabled()
        ) {
            $this->showOrderTypeModal = true;

            return;
        }

        if (! $this->orderTypeConfirmedByUser && $this->applyRestaurantDefaultOrderTypeIfPopupDisabled()) {
            // Popup disabled: default order type applied; continue adding to cart.
        }

        // Check radius restriction before allowing cart operations
        if (!$this->checkRadiusRestriction()) {
            // Check if location is set
            if (empty($this->addressLat) || empty($this->addressLng)) {
                $this->showLocationModal = true;
                $this->alert('error', __('app.locationAccessRequired'), [
                    'toast' => false,
                    'position' => 'center',
                ]);
            } else {
                $this->alert('error', __('app.outsideAllowedAreaMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close')
                ]);
            }
            return;
        }

        if (!$this->canCreateOrder) {
            $this->alert('error', __('messages.CartAddPermissionDenied'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        // Check order limit
        $orderStats = $this->shopBranch ? getRestaurantOrderStats($this->shopBranch->id) : null;
        if (! branchOrderStatsAllowNewOrder($orderStats)) {
            $this->alert('error', __('messages.orderLimitReached'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $this->menuItem = MenuItem::where('show_on_customer_site', true)->find($id);

        if (! $this->menuItem || ! $this->menuItem->in_stock || ! ($this->menuItem->is_available ?? true)) {
            $this->alert('error', IngredientStockGuard::insufficientStockMessage(), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        if ($this->isIngredientStockBlockedForLine($id, null, [], 1)) {
            return;
        }


        if ($variationCount > 0) {
            $this->showVariationModal = true;
        } elseif ($modifierCount > 0) {
            $this->selectedModifierItem = $id;
            $this->showModifiersModal = true;
        } else {
            $this->syncCart($id);
        }

        // Ensure itemNotes key is initialized
        if (!isset($this->itemNotes[$id])) {
            $this->itemNotes[$id] = '';
        }

        // Close item detail modal after add button is clicked
        $this->showItemDetailModal = false;
    }

    public function subCartItems($id)
    {
        $this->menuItem = MenuItem::find($id);
        $this->showCartVariationModal = true;
    }

    public function subModifiers($id)
    {
        $this->menuItem = MenuItem::find($id);
        // $this->showModifiersModal = true;
    }

    public function syncCart($id)
    {
        if (is_numeric($id)) {
            $id = (int) $id;
        }

        // Check radius restriction before adding items
        if (!$this->checkRadiusRestriction()) {
            if (empty($this->addressLat) || empty($this->addressLng)) {
                $this->showLocationModal = true;
                $this->alert('error', __('app.locationAccessRequired'), [
                    'toast' => false,
                    'position' => 'center',
                ]);
            } else {
                $this->alert('error', __('app.outsideAllowedAreaMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close')
                ]);
            }
            return;
        }

        if (!isset($this->orderItemList[$id])) {
            if (! ($this->menuItem->in_stock ?? true) || ! ($this->menuItem->is_available ?? true)) {
                $this->alert('error', IngredientStockGuard::insufficientStockMessage(), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close'),
                ]);

                return;
            }

            $variation = $this->orderItemVariation[$id] ?? null;
            $modifiers = $this->itemModifiersSelected[$id] ?? [];

            if ($this->isIngredientStockBlockedForLine(
                (int) ($variation?->menu_item_id ?? $this->menuItem->id),
                $variation?->id,
                $modifiers,
                (int) (($this->orderItemQty[$id] ?? 0) + 1),
            )) {
                return;
            }

            $this->orderItemList[$id] = $this->menuItem;
            $this->orderItemQty[$id] = $this->orderItemQty[$id] ?? 1;

            // Set price context before using price
            if ($this->orderTypeId) {
                if (isset($this->orderItemVariation[$id])) {
                    $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, null);
                }
                if (isset($this->orderItemList[$id])) {
                    $this->orderItemList[$id]->setPriceContext($this->orderTypeId, null);
                }
            }

            $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
            $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));
            $this->cartItemQty[$id] = isset($this->cartItemQty[$this->menuItem->id]) ? ($this->cartItemQty[$this->menuItem->id] + 1) : 1;
            $this->calculateTotal();
        } else {
            $this->addQty($id);
        }

        if (!isset($this->itemNotes[$id])) {
            $this->itemNotes[$id] = '';
        }

        $this->syncBrowseSimpleCartSessionFromComponent();
    }

    #[On('addQty')]
    public function addQty($id)
    {
        // Check radius restriction before allowing quantity increase
        if (!$this->checkRadiusRestriction()) {
            if (empty($this->addressLat) || empty($this->addressLng)) {
                $this->showLocationModal = true;
                $this->alert('error', __('app.locationAccessRequired'), [
                    'toast' => false,
                    'position' => 'center',
                ]);
            } else {
                $this->alert('error', __('app.outsideAllowedAreaMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close')
                ]);
            }
            return;
        }

        $this->showCartVariationModal = false;

        $nextQty = isset($this->orderItemQty[$id]) ? ($this->orderItemQty[$id] + 1) : 1;
        $variation = $this->orderItemVariation[$id] ?? null;
        $menuItem = $this->orderItemList[$id] ?? null;

        if ($menuItem && $this->isIngredientStockBlockedForLine(
            (int) ($variation?->menu_item_id ?? $menuItem->id),
            $variation?->id,
            $this->itemModifiersSelected[$id] ?? [],
            $nextQty,
        )) {
            return;
        }

        $this->orderItemQty[$id] = $nextQty;
        $this->cartItemQty[$id] = isset($this->cartItemQty[$id]) ? ($this->cartItemQty[$id] + 1) : 1;

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$id])) {
                $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, null);
            }
            if (isset($this->orderItemList[$id])) {
                $this->orderItemList[$id]->setPriceContext($this->orderTypeId, null);
            }
        }

        $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
        $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));
        $this->calculateTotal();
        $this->syncBrowseSimpleCartSessionFromComponent();
    }

    #[On('subQty')]
    public function subQty($id)
    {
        // If the cart line doesn't exist, ignore (prevents undefined array key errors on stale Livewire updates)
        if (!isset($this->orderItemQty[$id])) {
            return;
        }

        $this->showCartVariationModal = false;

        $this->orderItemQty[$id] = (isset($this->orderItemQty[$id]) && $this->orderItemQty[$id] > 1) ? ($this->orderItemQty[$id] - 1) : 0;

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$id])) {
                $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, null);
            }
            if (isset($this->orderItemList[$id])) {
                $this->orderItemList[$id]->setPriceContext($this->orderTypeId, null);
            }
        }

        $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price ?? 0;
        $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));
        $menuID = explode('_', $id);

        if (isset($menuID[0])) {
            $menuID = str_replace('"', '', $menuID[0]);
        }

        $this->cartItemQty[$menuID] = isset($this->cartItemQty[$menuID]) ? ($this->cartItemQty[$menuID] - 1) : 0;

        if ($this->orderItemQty[$id] == 0) {
            unset($this->orderItemList[$id]);
            unset($this->orderItemVariation[$id]);
            unset($this->orderItemAmount[$id]);
            unset($this->orderItemQty[$id]);
        }

        if ($this->cartItemQty[$menuID] == 0) {
            unset($this->cartItemQty[$menuID]);
        }

        $this->calculateTotal();
        $this->syncBrowseSimpleCartSessionFromComponent();
    }

    public function calculateTotal()
    {
        $this->cartQty = 0;

        foreach ($this->orderItemQty ?? [] as $qty) {
            if ((int) $qty > 0) {
                $this->cartQty++;
            }
        }

        $this->dispatch('updateCartCount', count: $this->cartQty);

        $this->total = 0;
        $this->subTotal = 0;
        $this->totalTaxAmount = 0;
        $this->orderItemTaxDetails = [];

        if (is_array($this->orderItemAmount)) {
            // Calculate item taxes first for proper subtotal calculation
            if ($this->taxMode === 'item') {
                $this->updateOrderItemTaxDetails();
            }

            foreach ($this->orderItemAmount as $key => $value) {
                $this->total += $value;

                // For inclusive taxes, subtract tax from subtotal
                if ($this->taxMode === 'item' && isset($this->orderItemTaxDetails[$key])) {
                    $taxDetail = $this->orderItemTaxDetails[$key];
                    $isInclusive = $this->restaurant->tax_inclusive ?? false;

                    if ($isInclusive) {
                        // For inclusive tax: subtotal = item amount - tax amount
                        $this->subTotal += ($value - ($taxDetail['tax_amount'] ?? 0));
                    } else {
                        // For exclusive tax: subtotal = item amount (tax will be added later)
                        $this->subTotal += $value;
                    }
                } else {
                    // No item taxes or order-level taxes
                    $this->subTotal += $value;
                }
            }
        }

        // Update loyalty values if customer is set (to recalculate max discount based on current subtotal)
        if ($this->isLoyaltyEnabled() && $this->customerId && $this->loyaltyPointsRedeemed == 0) {
            $this->updateLoyaltyValues();
        }

        // Apply loyalty discount if points are redeemed (BEFORE taxes, like regular discount)
        if ($this->isLoyaltyEnabled() && $this->loyaltyPointsRedeemed > 0 && $this->customerId) {
            // Recalculate loyalty discount based on current subtotal using trait method
            $this->recalculateLoyaltyDiscount(restaurant()->id, $this->subTotal ?? 0);

            // Apply discount to total (subtract from subtotal before taxes)
            if ($this->loyaltyDiscountAmount > 0) {
                $this->total -= $this->loyaltyDiscountAmount;
            }
        }

        // Step 2: Calculate service charges on net
        $applicableExtraCharges = $this->filteredExtraCharges();
        $discountedSubtotal = $this->subTotal - ($this->loyaltyDiscountAmount ?? 0);
        $chargeTotals = RestaurantCharge::calculateTotals($applicableExtraCharges, $discountedSubtotal);
        $serviceTotal = $chargeTotals['total'];
        $this->total += $serviceTotal;

        // Step 3: Calculate tax_base based on setting
        $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? false;

        // Calculate net after all discounts (regular + loyalty)
        $regularDiscount = $this->discountAmount ?? 0;
        $loyaltyDiscount = $this->loyaltyDiscountAmount ?? 0;
        $netAfterDiscounts = $this->subTotal - $regularDiscount - $loyaltyDiscount;

        $this->taxBase = RestaurantCharge::resolveTaxBase(
            $netAfterDiscounts,
            $applicableExtraCharges,
            $includeChargesInTaxBase
        );

        // Step 4: Calculate taxes on tax_base (only once, after all discounts and charges are applied)
        // For item-based taxes, updateOrderItemTaxDetails() was already called at line 960
        // For order-based taxes, calculate on the correct taxBase
        $this->recalculateTaxTotals($this->taxBase);

        $this->total += (float)$this->deliveryFee ?: 0;

        $this->dispatchShopClientBrowserCartSync();
    }

    /**
     * Whether the floating shop cart summary should show (covers string qty / stale cartQty edge cases).
     */
    public function shopCartStripShouldDisplay(): bool
    {
        if (((int) ($this->cartQty ?? 0)) > 0) {
            return true;
        }

        foreach ($this->orderItemQty ?? [] as $qty) {
            if ((int) $qty > 0) {
                return true;
            }
        }

        return is_array($this->orderItemAmount ?? null) && count($this->orderItemAmount) > 0;
    }

    /**
     * Line count for the floating cart banner (distinct cart lines with qty &gt; 0).
     */
    public function shopCartBannerLineCount(): int
    {
        $n = 0;
        foreach ($this->orderItemQty ?? [] as $qty) {
            if ((int) $qty > 0) {
                $n++;
            }
        }

        if ($n > 0) {
            return $n;
        }

        return max(0, (int) ($this->cartQty ?? 0));
    }

    private function dispatchShopClientBrowserCartSync(): void
    {
        $this->dispatch('shop-client-cart-qty-sync', cartItemQty: $this->cartItemQty ?? []);
    }

    private function dispatchShopClientOrderTypeChanged(): void
    {
        if ($this->orderTypeId) {
            $this->dispatch('shop-client-order-type-changed', orderTypeId: (int) $this->orderTypeId);
        }
    }

    /**
     * Client-side catalog JSON for the Alpine menu. Must be present on Livewire updates too; returning null
     * on X-Livewire requests broke DOM morphing and blocked cart UI updates after add-to-cart.
     */
    private function getClientShopCatalogPayload(): ?array
    {
        if ($this->clientShopCatalogResolved) {
            return $this->clientShopCatalogCache;
        }

        $this->clientShopCatalogResolved = true;

        try {
            $this->restaurant->loadMissing('euAllergenSetting');
            $this->clientShopCatalogCache = CustomerSiteCatalogBuilder::build(
                $this->restaurant,
                $this->shopBranch,
                $this->table ?? null,
                $this->cameFromTableQR,
                app()->getLocale(),
                (int) ($this->orderTypeId ?? 0),
            );
        } catch (\Throwable $e) {
            Log::warning('shop_client_catalog_build_failed', [
                'message' => $e->getMessage(),
                'branch_id' => $this->shopBranch->id ?? null,
            ]);
            $this->clientShopCatalogCache = null;
        }

        return $this->clientShopCatalogCache;
    }

    /**
     * UI config for Alpine browse layer (labels, flags, order limits). Must be present on Livewire updates.
     *
     * @return array<string, mixed>|null
     */
    private function getClientShopBrowseConfig(): ?array
    {
        $orderStats = $this->shopBranch ? getRestaurantOrderStats($this->shopBranch->id) : null;
        $orderLimitOk = branchOrderStatsAllowNewOrder($orderStats);

        $orderTypesQuery = OrderType::query()
            ->where('branch_id', $this->shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->availableForRestaurant()
            ->orderByDesc('is_default')
            ->orderBy('id');

        if ($this->shouldForceDineInFromQr()) {
            $orderTypesQuery->where('type', 'dine_in');
        }

        $orderTypesPick = $orderTypesQuery->get()
            ->reject(function (OrderType $ot) {
                $isRoomService = $ot->type === 'room_service' || $ot->slug === 'room_service';

                if ($this->cameFromRoomQR) {
                    return ! $isRoomService;
                }

                return $isRoomService;
            })
            ->map(fn (OrderType $ot) => [
                'id' => $ot->id,
                'slug' => $ot->slug,
                'type' => $ot->type,
                'name' => $ot->translated_name,
                'description' => $this->orderTypeCustomerDescription($ot),
            ])
            ->values()
            ->all();

        $euSelectableForBrowse = $this->restaurant->selectableEuAllergenKeys();

        return [
            'showMenu' => (bool) $this->showMenu,
            'euAllergensEnabled' => $euSelectableForBrowse !== [],
            'orderLimitOk' => $orderLimitOk,
            'livewire_component_name' => $this->getName() ?: 'shop.cart',
            'showVeg' => (bool) ($this->restaurant->show_veg ?? false),
            'showHalal' => (bool) ($this->restaurant->show_halal ?? false),
            'hideImages' => (bool) ($this->restaurant->hide_menu_item_image_on_customer_site ?? false),
            'canCreateOrder' => (bool) $this->canCreateOrder && ! $this->tableQrOrderingBlocked && ! $this->roomQrOrderingBlocked,
            'allowCustomerOrders' => (bool) ($this->restaurant->allow_customer_orders ?? false),
            'initialCartItemQty' => $this->cartItemQty ?? [],
            'use_js_order_type_modal' => false,
            'disable_order_type_popup' => $this->isCustomerOrderTypePopupDisabled(),
            'default_order_type_id' => (int) ($this->restaurant->default_order_type_id ?? 0),
            'book_table_url' => $this->getBookTableUrl(),
            'show_book_table_escape' => $this->showBookTableEscapeOnOrderTypeModal(),
            'cart_storage_key' => 'tt_shop_cart_qty_' . $this->restaurant->hash,
            'order_type_sync_url' => route('shop.sync_browse_order_type', ['hash' => $this->restaurant->hash]),
            'browse_cart_mutate_url' => route('shop.browse_cart_mutate', ['hash' => $this->restaurant->hash]),
            'came_from_qr' => (bool) $this->cameFromQR,
            'force_dine_in_qr' => $this->shouldForceDineInFromQr(),
            'table_qr_ordering_blocked' => (bool) $this->tableQrOrderingBlocked,
            'room_qr_ordering_blocked' => (bool) $this->roomQrOrderingBlocked,
            'branch_id' => (int) $this->shopBranch->id,
            'order_types_pick' => $orderTypesPick,
            'labels' => [
                'showAll' => __('app.showAll'),
                'showMore' => __('modules.menu.showMore'),
                'showLess' => __('modules.menu.showLess'),
                'searchPlaceholder' => __('placeholders.searchMenuItems'),
                'typeVeg' => __('modules.menu.typeVeg'),
                'typeHalal' => __('modules.menu.typeHalal'),
                'noMenuAdded' => __('messages.noMenuAdded'),
                'noItemAdded' => __('messages.noItemAdded'),
                'add' => __('app.add'),
                'cancel' => __('app.cancel'),
                'itemDescription' => __('modules.menu.itemDescription'),
                'allergensHeading' => __('modules.settings.euAllergensCustomerDisplayHeading'),
                'dietaryLabelsHeading' => __('modules.menu.dietaryLabelsSectionTitle'),
                'outOfStock' => 'Out of stock',
                'preparationTime' => __('modules.menu.preparationTime'),
                'minutes' => __('modules.menu.minutes'),
                'showVariations' => __('modules.menu.showVariations'),
                'allItemsLoaded' => __('messages.allItemsLoaded'),
                'itemLabel' => __('modules.menu.item'),
                'selectOrderType' => __('modules.order.selectOrderType'),
                'selectOrderTypeDescription' => __('modules.order.selectOrderTypeDescription'),
                'selectOrderTypeWithDeliveryDescription' => __('modules.order.selectOrderTypeWithDeliveryDescription'),
                'bookTable' => __('menu.bookTable'),
                'bookTableInstead' => __('messages.bookTableInstead'),
                'dineInDescription' => __('messages.dineInDescription'),
                'deliveryDescription' => __('messages.deliveryDescription'),
                'pickupDescription' => __('messages.pickupDescription'),
                'changeOrderType' => __('messages.changeOrderType'),
                'currentOrderType' => __('messages.currentOrderType'),
                'orderTypeNotSelectedYet' => __('messages.orderTypeNotSelectedYet'),
                'browseMenuContinue' => __('messages.browseMenuContinue'),
                'close' => __('app.close'),
            ],
        ];
    }

    public function removeExtraCharge($chargeId, $orderType = null)
    {
        $charges = collect($this->extraCharges ?? []);
        $charge = $charges->firstWhere('id', $chargeId);

        // Skip work when the charge is not present
        if (!$charge) {
            return;
        }

        // Detach only when an order exists
        if ($this->order) {
            $this->order->extraCharges()->detach($chargeId);
        }

        // Keep the in-memory list in sync
        $this->extraCharges = $charges
            ->reject(fn($item) => $item->id == $chargeId)
            ->values();

        $this->calculateTotal();

        if ($this->order) {
            $this->order->update([
                'sub_total' => $this->subTotal,
                'total' => $this->total,
                'tax_base' => $this->taxBase,
            ]);
        }
    }

    #[Computed]
    public function getApplicableExtraChargesProperty()
    {
        return $this->filteredExtraCharges();
    }

    private function filteredExtraCharges()
    {
        $orderType = $this->orderTypeSlug ?? $this->orderType ?? null;

        if (!$orderType) {
            return collect();
        }

        return collect($this->extraCharges ?? [])
            ->filter(function ($charge) use ($orderType) {
                $orderTypes = $charge->order_types ?? [];

                if (is_string($orderTypes)) {
                    $decoded = json_decode($orderTypes, true);
                    $orderTypes = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                }

                if (!is_array($orderTypes) || empty($orderTypes)) {
                    return false;
                }

                return in_array($orderType, $orderTypes, true);
            })
            ->values();
    }

    /**
     * Legacy method kept for backward compatibility
     * Use updatedOrderTypeId for new implementation
     */
    public function updatedOrderType($value)
    {

        // Find the order type by slug or type
        $orderTypeModel = OrderType::where('branch_id', $this->shopBranch->id)
            ->where('is_active', true)
            ->where(function ($q) use ($value) {
                $q->where('slug', $value)
                    ->orWhere('type', $value);
            })
            ->first();

        if ($orderTypeModel) {
            $this->orderTypeId = $orderTypeModel->id;
            $mainExtraCharges = RestaurantCharge::withoutGlobalScopes()
                ->whereJsonContains('order_types', $value)
                ->where('is_enabled', true)
                ->where('restaurant_id', $this->restaurant->id)
                ->get();
            // Early return for new orders
            if (!$this->orderID) {
                // Only clear delivery-related fields if the order type is not delivery
                if ($value !== 'delivery') {
                    $this->addressLat = null;
                    $this->addressLng = null;
                    $this->deliveryAddress = null;
                    $this->deliveryFee = null;
                }

                $this->calculateMaxPreparationTime();
                $this->extraCharges = $mainExtraCharges;
                $this->calculateTotal();
                return;
            }

            // Early return if no valid order or order is paid
            if (!$this->order || $this->order->status === 'paid') {
                return;
            }

            // Efficiently get the slug from the order's order type
            $orderTypeFromOrder = $this->order->order_type_id
                ? (OrderType::where('id', $this->order->order_type_id)->value('slug') ?? $this->order->order_type)
                : $this->order->order_type;

            // Keep existing charges if order type is unchanged, otherwise set new ones
            $this->extraCharges = $orderTypeFromOrder === $value ? $this->order->extraCharges : $mainExtraCharges;

            $this->calculateTotal();
        }
    }

    #[On('setPosVariation')]
    public function setPosVariation($variationId)
    {
        $this->showVariationModal = false;
        $menuItemVariation = MenuItemVariation::find($variationId);

        // Set price context before using variation
        if ($this->orderTypeId) {
            $menuItemVariation->setPriceContext($this->orderTypeId, null);
        }

        $modifiersAvailable = $menuItemVariation->menuItem->modifiers->count();

        if ($modifiersAvailable) {
            $this->selectedModifierItem = $menuItemVariation->menu_item_id . '_' . $variationId;
            $this->showModifiersModal = true;
        } else {
            $this->orderItemVariation[$menuItemVariation->menu_item_id . '_' . $variationId] = $menuItemVariation;
            $this->cartItemQty[$menuItemVariation->menu_item_id] = isset($this->cartItemQty[$menuItemVariation->menu_item_id]) ? ($this->cartItemQty[$menuItemVariation->menu_item_id] + 1) : 1;
            $this->orderItemAmount[$menuItemVariation->menu_item_id . '_' . $variationId] = (1 * (isset($this->orderItemVariation[$menuItemVariation->menu_item_id . '_' . $variationId]) ? $this->orderItemVariation[$menuItemVariation->menu_item_id . '_' . $variationId]->price : $this->orderItemList[$menuItemVariation->menu_item_id . '_' . $variationId]->price));
            $this->syncCart($menuItemVariation->menu_item_id . '_' . $variationId);
        }
    }

    #[On('setCustomer')]
    public function setCustomer($customer)
    {

        $customer = Customer::find($customer['id']);
        $this->customer = $customer;

        // For pickup orders, continue flow after customer is known
        if ($this->orderType === 'pickup' || $this->orderTypeSlug === 'pickup') {
            // If we still need mandatory customer details, show that modal first
            if (is_null($this->customer) || is_null($this->customer->name) || is_null($this->customer->phone)) {
                $this->customerName = $this->customer?->name;
                $this->customerPhone = $this->customer?->phone;
                $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
                $this->phoneCodeDetected = empty($this->customer?->phone_code) && !empty($detectedPhoneCode);
                $this->customerPhoneCode = $this->customer?->phone_code
                    ?? $detectedPhoneCode
                    ?? $this->restaurant->phone_code
                    ?? $this->allPhoneCodes->first();
                $this->showCustomerNameModal = true;
                if ($this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery') {
                    $this->dispatch('initCartCustomerAddressMap', [
                        'lat' => $this->addressLat ?? $this->shopBranch?->lat ?? 26.9125,
                        'lng' => $this->addressLng ?? $this->shopBranch?->lng ?? 75.7875,
                        'address' => $this->deliveryAddress ?? $this->customerAddress ?? null,
                    ]);
                }
                return;
            }

            // Otherwise move to pickup date/time selection if not set
            if (empty($this->deliveryDateTime)) {
                $this->showPickupDateTimeModal = true;
            }
        }
        $this->customerId = $this->customer?->id; // Set customerId for loyalty integration trait

        // Load loyalty points if module is enabled (but don't auto-open modal - user clicks button)
        if ($this->isLoyaltyEnabled() && $this->customerId) {
            try {
                if (module_enabled('Loyalty')) {
                    $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                    $restaurantId = restaurant()->id;
                    $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $this->customerId);
                    $this->updateLoyaltyValues();
                }
            } catch (\Exception $e) {
                // Silently fail if module doesn't exist
            }
        }
    }

    /**
     * Open loyalty redemption modal and load loyalty values
     */
    public function openLoyaltyRedemptionModal()
    {
        if ($this->isLoyaltyEnabled() && $this->customerId) {
            // Load loyalty points and values
            try {
                if (!module_enabled('Loyalty')) {
                    return;
                }

                $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                $restaurantId = restaurant()->id;

                // Get available points
                $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $this->customerId);

                if ($this->availableLoyaltyPoints > 0) {
                    // Update loyalty values
                    $this->updateLoyaltyValues();

                    // Set default points to redeem
                    $this->pointsToRedeem = $this->minRedeemPoints > 0 ? $this->minRedeemPoints : ($this->maxRedeemablePoints > 0 ? $this->maxRedeemablePoints : 0);

                    // Open modal
                    $this->showLoyaltyRedemptionModal = true;
                } else {
                    $this->alert('info', __('loyalty::app.noPointsAvailable'), [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                }
            } catch (\Exception $e) {
                // Silently fail if module doesn't exist
            }
        }
    }

    /**
     * Close loyalty redemption modal and reset values if not redeemed
     */
    public function closeLoyaltyRedemptionModal()
    {
        // Only reset if points were not actually redeemed (i.e., just previewing)
        // If loyaltyPointsRedeemed is 0, it means redemption was never applied, so clear the discount
        if ($this->loyaltyPointsRedeemed == 0) {
            // Clear preview discount amount (this was just a preview, not actual redemption)
            $this->loyaltyDiscountAmount = 0;
            $this->pointsToRedeem = 0;
        }
        $this->showLoyaltyRedemptionModal = false;
    }

    /**
     * Redeem loyalty points for cart (before order is placed)
     */
    public function redeemLoyaltyPoints($points = null)
    {
        if (!$this->isLoyaltyEnabled() || !$this->customerId) {
            $this->alert('error', __('loyalty::app.loyaltyProgramNotEnabled'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Use points parameter if provided, otherwise use pointsToRedeem from input
        $pointsToRedeem = $points ?? $this->pointsToRedeem ?? 0;

        if ($pointsToRedeem <= 0) {
            $this->alert('error', __('loyalty::app.invalidPointsAmount'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Validate points
        if ($pointsToRedeem > $this->availableLoyaltyPoints) {
            $this->alert('error', __('loyalty::app.insufficientLoyaltyPointsAvailable'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Check if module service exists
        if (!module_enabled('Loyalty')) {
            $this->alert('error', 'Loyalty module is not available', [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $restaurantId = restaurant()->id;

            // Get loyalty settings
            if (!module_enabled('Loyalty')) {
                $this->alert('error', 'Loyalty module is not available', [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }

            $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
            if (!$settings || !$settings->isEnabled()) {
                $this->alert('error', __('loyalty::app.loyaltyProgramNotEnabled'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }

            // Check minimum redeem points
            if ($settings->min_redeem_points > 0 && $pointsToRedeem < $settings->min_redeem_points) {
                $this->alert('error', __('loyalty::app.minPointsRequired', ['min_points' => $settings->min_redeem_points]), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }

            // Calculate discount amount
            $valuePerPoint = $settings->value_per_point ?? 1;
            $pointsDiscount = $pointsToRedeem * $valuePerPoint;

            // Calculate max discount (percentage of subtotal)
            $subtotal = $this->subTotal ?? 0;
            $maxDiscountPercent = $settings->max_discount_percent ?? 0;
            $maxDiscountAmount = ($subtotal * $maxDiscountPercent) / 100;

            // Use the smaller of points discount or max discount
            $discountAmount = min($pointsDiscount, $maxDiscountAmount);

            // Set loyalty redemption values (will be applied when order is placed)
            $this->loyaltyPointsRedeemed = $pointsToRedeem;
            $this->loyaltyDiscountAmount = $discountAmount;

            // Close modal
            $this->showLoyaltyRedemptionModal = false;

            // Recalculate total with loyalty discount
            $this->calculateTotal();

            $this->alert('success', __('loyalty::app.pointsRedeemedSuccessfully'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to redeem loyalty points in Cart: ' . $e->getMessage());
            $this->alert('error', __('loyalty::app.failedToRedeemPoints'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    #[On('showCartItems')]
    public function showCartItems()
    {
        $this->showCart = true;
        $this->showMenu = false;
        $this->dispatch('shop-client-show-menu', showMenu: false);
    }

    #[On('showMenuItems')]
    public function showMenuItems()
    {
        $this->showCart = false;
        $this->showMenu = true;
        $this->dispatch('shop-client-show-menu', showMenu: true);
    }

    public function updatedPhoneCodeIsOpen($value)
    {
        if (!$value) {
            $this->reset(['phoneCodeSearch']);
            $this->updatedPhoneCodeSearch();
        }
    }

    public function updatedPhoneCodeSearch()
    {
        $this->filteredPhoneCodes = $this->allPhoneCodes->filter(function ($phonecode) {
            return str_contains($phonecode, $this->phoneCodeSearch);
        })->values();
    }

    public function selectPhoneCode($phonecode)
    {
        $this->customerPhoneCode = $phonecode;
        $this->phoneCodeIsOpen = false;
        $this->phoneCodeSearch = '';
        $this->updatedPhoneCodeSearch();
    }

    public function submitCustomerName()
    {
        $rules = [
            'customerPhone' => 'required',
        ];

        if (!$this->pendingTlyncOrderId || empty($this->customerName)) {
            $rules['customerName'] = 'required';
        }

        if (!$this->pendingTlyncOrderId) {
            $rules['customerPhoneCode'] = 'required';
        } elseif (!$this->normalizeTlyncPhone($this->customerPhone)) {
            $this->addError('customerPhone', __('messages.tlyncInvalidPhoneNumber'));
            return;
        }

        // Require address when order type is delivery
        if ($this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery') {
            $rules['deliveryAddress'] = 'required';
            $rules['addressLat'] = 'required|numeric';
            $rules['addressLng'] = 'required|numeric';
        }

        $this->validate($rules);

        if ($this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery') {
            $this->customerAddress = $this->deliveryAddress;
        }

        if ($this->pendingTlyncOrderId && !$this->customer) {
            $this->customer = Customer::create([
                'restaurant_id' => $this->restaurant->id,
                'name' => $this->customerName,
                'phone' => $this->normalizeTlyncPhone($this->customerPhone),
                'phone_code' => '218',
            ]);

            $order = Order::find($this->pendingTlyncOrderId);
            if ($order) {
                $order->customer_id = $this->customer->id;
                $order->save();
            }
        } else {
            $this->customer->name = $this->customerName;
            $this->customer->phone = $this->pendingTlyncOrderId
                ? $this->normalizeTlyncPhone($this->customerPhone)
                : $this->customerPhone;
            $this->customer->phone_code = $this->pendingTlyncOrderId ? '218' : $this->customerPhoneCode;
            $this->customer->delivery_address = $this->customerAddress;
            $this->customer->save();
        }

        // Also persist into customer_addresses for delivery orders (non-blocking).
        if ($this->isDeliveryOrder()) {
            $this->persistCustomerDeliveryLocation();
        }

        session(['customer' => $this->customer]);
        $this->customerId = $this->customer->id; // Set customerId for loyalty integration trait
        $this->dispatch('setCustomer', customer: $this->customer);

        // Load loyalty points if module is enabled (but don't auto-open modal - user clicks button)
        if ($this->isLoyaltyEnabled() && $this->customerId) {
            try {
                $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                $restaurantId = restaurant()->id;
                $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $this->customerId);
                $this->updateLoyaltyValues();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to load loyalty points in submitCustomerName: ' . $e->getMessage());
            }
        }

        $this->showCustomerNameModal = false;

        if ($this->pendingTlyncOrderId) {
            $orderId = $this->pendingTlyncOrderId;
            $this->pendingTlyncOrderId = null;
            return $this->initiateTlyncPayment($orderId);
        }

        // For pickup orders, show pickup date/time modal after customer name is submitted
        if ($this->orderType == 'pickup' || $this->orderTypeSlug == 'pickup') {
            if (empty($this->deliveryDateTime)) {
                $this->showPickupDateTimeModal = true;
                return;
            }
        }

        $this->placeOrder($this->payNow);
    }

    public function selectTableOrder($tableID = null)
    {
        if ($this->getTable) {
            $this->tableID = $tableID;
            $this->getTable = false;
            $this->showTableModal = false;
            $this->placeOrder($this->payNow);
        }
    }

    public function getShouldShowWaiterButtonMobileProperty()
    {

        $this->dispatch('refreshComponent');

        if (!$this->restaurant->is_waiter_request_enabled || !$this->restaurant->is_waiter_request_enabled_on_mobile) {
            return false;
        }

        $cameFromQR = request()->query('hash') === $this->restaurant->hash || request()->boolean('from_qr');

        if ($this->restaurant->is_waiter_request_enabled_open_by_qr && !$cameFromQR) {
            return false;
        }

        return true;
    }

    /**
     * Resolved order-type slug for mobile shop UI (Livewire state, session, or type fallback).
     */
    public function getShopMobileOrderTypeSlugProperty(): ?string
    {
        return $this->orderTypeSlug
            ?? session('shop_order_type_slug')
            ?? ($this->orderType ?: null);
    }

    public function getShouldShowMobileWaiterButtonProperty(): bool
    {
        return $this->shouldShowWaiterButtonMobile
            && $this->shopMobileOrderTypeSlug === 'dine_in';
    }

    /**
     * Check if customer can create order based on radius restrictions
     */
    public function getCanCreateOrderProperty()
    {
        // For QR orders, check if within radius
        if ($this->cameFromQR && !empty($this->restaurant->qr_order_radius_meters)) {
            // If location is not set, allow for now (will be checked when adding items)
            if (empty($this->addressLat) || empty($this->addressLng)) {
                return true; // Allow to show UI, but will be blocked when adding itemss
            }

            // Return whether within radius
            return $this->is_within_radius ?? true;
        }

        // For non-QR orders or if no radius restriction, allow
        return true;
    }

    public function getAvailableTable()
    {
        $this->tables = Area::where('branch_id', $this->shopBranch->id)
            ->withCount([
                'tables' => function ($query) {
                    $query->where('status', 'active');
                }
            ])
            ->with([
                'tables' => function ($query) {
                    $query->where('status', 'active');
                }
            ])
            ->get();
    }

    public function updatedPickupDate()
    {
        $this->updateDeliveryDateTime();
    }

    public function updatedPickupTime()
    {
        $this->updateDeliveryDateTime();
    }

    private function updateDeliveryDateTime()
    {
        if (!$this->pickupDate || !$this->pickupTime || !$this->restaurant) {
            return;
        }

        $dateFormat = $this->restaurant->date_format ?? 'd-m-Y';
        $timezone = $this->restaurant->timezone ?? config('app.timezone');

        try {
            $parsed = parseRestaurantDate($this->pickupDate, $dateFormat, $timezone);
            if (!preg_match('/^(\d{1,2}):(\d{2})/', trim((string) $this->pickupTime), $m)) {
                throw new \InvalidArgumentException('Invalid pickup time');
            }
            $parsed->setTime((int) $m[1], (int) $m[2], 0);
            $this->deliveryDateTime = $parsed->format('Y-m-d\TH:i');
        } catch (\Exception $e) {
            $this->deliveryDateTime = now($timezone)->format('Y-m-d\TH:i');
        }
    }

    public function savePickupDateTime()
    {
        // Ensure deliveryDateTime is set from pickup fields even if user did not change defaults.
        $this->updateDeliveryDateTime();

        $this->validate([
            'deliveryDateTime' => 'required|date',
        ]);

        $this->showPickupDateTimeModal = false;
        $this->placeOrder($this->payNow);
    }

    public function showPickupDateTime()
    {
        $this->showPickupDateTimeModal = true;
        $tz = $this->restaurant->timezone ?? config('app.timezone');
        $dateFormat = $this->restaurant->date_format ?? 'd-m-Y';
        $this->pickupDate = now($tz)->displayDate($dateFormat);
        $this->pickupTime = now($tz)->format('H:i');
        $this->updateDeliveryDateTime();
    }

    public function placeOrder($pay = false, $updateOrder = null, $method = null)
    {
        if ($this->tableQrOrderingBlocked) {
            $this->alert('error', __('messages.tableQrDineInDisabled'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        if ($this->roomQrOrderingBlocked) {
            $this->alert('error', __('messages.roomQrRoomServiceDisabled'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch);

        if (!($availability['is_open'] ?? true)) {
            $this->alert('error', RestaurantAvailabilityService::getMessage($availability, $this->restaurant), [
                'toast' => false,
                'position' => 'center',
            ]);
            return;
        }

        // FINAL QR RADIUS CHECK - always validate fresh
        if ($this->cameFromQR && !empty($this->restaurant->qr_order_radius_meters)) {
            // Check both lat/lng and latitude/longitude column names for branch
            $branchLat = $this->shopBranch->lat ?? $this->shopBranch->latitude ?? null;
            $branchLng = $this->shopBranch->lng ?? $this->shopBranch->longitude ?? null;

            if (!empty($branchLat) && !empty($branchLng)) {
                // Require customer coordinates for QR orders with radius configured
                if (empty($this->addressLat) || empty($this->addressLng)) {
                    $this->showLocationModal = true;
                    $this->alert('error', __('app.locationAccessRequiredRadius'), [
                        'toast' => false,
                        'position' => 'center',
                    ]);
                    return;
                }

                // Recalculate distance to ensure fresh check
                $distance = $this->calculateDistance(
                    $this->addressLat, $this->addressLng,
                    $branchLat, $branchLng
                );
                $this->is_within_radius = $distance <= $this->restaurant->qr_order_radius_meters;

                if (!$this->is_within_radius) {
                    $this->alert('error', __('app.orderNotAllowedMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                        'toast' => false,
                        'position' => 'center',
                    ]);
                    return;
                }
            }
        }

        // Restrict QR order if outside allowed radius (double-check flag)
        if ($this->cameFromQR && !$this->is_within_radius) {
            $this->alert('error', __('app.orderNotAllowedMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                'toast' => false,
                'position' => 'center',
            ]);
            return;
        }

        if ($updateOrder) {
            $this->orderBeingProcessed = true;

            try {
                $this->order = Order::find($updateOrder);

                Payment::create([
                    'order_id' => $this->order->id,
                    'branch_id' => $this->shopBranch->id,
                    'payment_method' => $method,
                    'amount' => $this->total,
                ]);

                Order::where('id', $this->order->id)->update([
                    'status' => 'pending_verification',
                ]);
                $this->sendNotifications($this->order);

                $this->alert('success', __('messages.orderSaved'), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close')
                ]);

                $this->clearClientBrowseCart();
                $this->redirect(route('order_success', [$this->order->uuid]));
            } catch (\Throwable $e) {
                $this->orderBeingProcessed = false;

                throw $e;
            }

            return;
        }

        if ($this->orderType == 'delivery') {
            $deliverySetting = $this->shopBranch->deliverySetting ?? null;
        }

        if ($this->isDeliveryOrder()) {
            $this->loadSavedDeliveryLocation();

            if (empty($this->addressLat) || empty($this->addressLng)) {
                $this->showLocationModal = true;
                $this->alert('error', __('app.deliveryLocationAccessRequired'), [
                    'toast' => false,
                    'position' => 'center',
                ]);
                $this->payNow = $pay;

                return;
            }
        }

        if ($this->customer && (is_null($this->customer->name) || ($this->orderType == 'delivery' && is_null($this->customerAddress)) && is_null($deliverySetting))) {
            $this->customerName = $this->customer->name;
            $this->customerAddress = $this->customer->delivery_address;
            $this->deliveryAddress = $this->customer->delivery_address;
            $this->customerPhone = $this->customer->phone;
            $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
            $this->phoneCodeDetected = empty($this->customer?->phone_code) && !empty($detectedPhoneCode);
            $this->customerPhoneCode = $this->customer->phone_code
                ?? $detectedPhoneCode
                ?? $this->restaurant->phone_code
                ?? $this->allPhoneCodes->first();
            $this->showCustomerNameModal = true;
            if ($this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery') {
                $this->dispatch('initCartCustomerAddressMap', [
                    'lat' => $this->addressLat ?? $this->shopBranch?->lat ?? 26.9125,
                    'lng' => $this->addressLng ?? $this->shopBranch?->lng ?? 75.7875,
                    'address' => $this->deliveryAddress ?? $this->customerAddress ?? null,
                ]);
            }
            $this->payNow = $pay;
            return;
        }

        // Show customer name modal for pickup orders if customer name/phone is missing
        if (
            $this->customer &&
            (
                is_null($this->customer->name) ||
                (
                    ($this->orderType == 'pickup' || $this->orderTypeSlug == 'pickup') &&
                    is_null($this->customer->phone)
                )
            )
        ) {
            $this->customerName = $this->customer->name;
            $this->customerPhone = $this->customer->phone;
            $detectedPhoneCode = (new User())->getPhoneCodeFromIp();
            $this->phoneCodeDetected = empty($this->customer?->phone_code) && !empty($detectedPhoneCode);
            $this->customerPhoneCode = $this->customer->phone_code
                ?? $detectedPhoneCode
                ?? $this->restaurant->phone_code
                ?? $this->allPhoneCodes->first();
            $this->showCustomerNameModal = true;
            if ($this->orderType === 'delivery' || $this->orderTypeSlug === 'delivery') {
                $this->dispatch('initCartCustomerAddressMap', [
                    'lat' => $this->addressLat ?? $this->shopBranch?->lat ?? 26.9125,
                    'lng' => $this->addressLng ?? $this->shopBranch?->lng ?? 75.7875,
                    'address' => $this->deliveryAddress ?? $this->customerAddress ?? null,
                ]);
            }
            $this->payNow = $pay;
            return;
        }

        if ($this->customer && $this->orderType === 'delivery' && empty($this->addressLat) && empty($this->addressLng) && empty($this->deliveryAddress) && isset($deliverySetting)) {
            $this->customerAddress = $this->customer->delivery_address;
            $this->showDeliveryAddressModal = true;
            $this->payNow = $pay;
            return;
        }

        // Show pickup date/time modal for pickup orders if not already set
        if (($this->orderType == 'pickup' || $this->orderTypeSlug == 'pickup') && empty($this->deliveryDateTime)) {
            $this->payNow = $pay;
            $this->showPickupDateTimeModal = true;
            return;
        }

        if ($this->orderType == 'dine_in' && $this->getTable) {
            $this->getAvailableTable();
            $this->payNow = $pay;
            $this->showTableModal = true;
            return;
        }

        if (!$pay && is_null($updateOrder) && $this->isOnlinePaymentRequired()) {
            $this->alert('error', __('messages.onlinePaymentRequired'), [
                'toast' => false,
                'position' => 'center',
            ]);

            return;
        }

        $this->orderBeingProcessed = true;

        try {
        if (!is_null($this->tableID)) {
            $table = Table::where('hash', $this->tableID)->firstOrFail();
        }

        if ($this->isCartIngredientStockBlocked()) {
            return;
        }

        if ($this->order && ($this->order->status == 'kot' || $this->order->status == 'draft')) {
            $order = $this->order;
            $draftUpdate = [];

            if (! is_null($this->tableID)) {
                $draftUpdate['table_id'] = $table->id;
            }

            $draftUpdate = array_merge($draftUpdate, $this->buildHotelRoomOrderContext());

            if ($draftUpdate !== []) {
                $order->update($draftUpdate);
            }
        } else {
            $orderNumberData = Order::generateOrderNumber($this->shopBranch);

            // Use the already selected order type ID if available
            if ($this->orderTypeId) {
                $orderTypeModel = OrderType::find($this->orderTypeId);
                $orderTypeId = $orderTypeModel->id ?? null;
                $orderTypeName = $orderTypeModel->order_type_name ?? $this->orderType;
                // Ensure slug is set in case it wasn't already
                if (!$this->orderTypeSlug && $orderTypeModel) {
                    $this->orderTypeSlug = $orderTypeModel->slug;
                }
            } else {
                // Fallback to finding default order type
                $orderTypeModel = OrderType::where('is_default', 1)
                    ->where('type', $this->orderType)
                    ->first();

                $orderTypeId = $orderTypeModel->id ?? null;
                $orderTypeName = $orderTypeModel->order_type_name ?? $this->orderType;
                $this->orderTypeSlug = $orderTypeModel->slug ?? $this->orderType;
            }





            $orderData = [
                'order_number' => $orderNumberData['order_number'],
                'formatted_order_number' => $orderNumberData['formatted_order_number'],
                'branch_id' => $this->shopBranch->id,
                'table_id' => $table->id ?? null,
                'date_time' => now(),
                'customer_id' => $this->customer->id ?? null,
                'sub_total' => $this->subTotal,
                'total' => $this->total,
                'order_type' => $this->orderTypeSlug ?? $this->orderType,
                'order_type_id' => $orderTypeId,
                'custom_order_type_name' => $orderTypeName,
                'order_note' => $this->orderNote,
                'pickup_date' => $this->deliveryDateTime,
                'delivery_address' => $this->customerAddress,
                'status' => $this->restaurant->auto_confirm_orders_before_payment ? 'kot' : 'draft',
                'order_status' => $this->restaurant->auto_confirm_orders_before_payment ? 'confirmed' : 'placed',
                'auto_confirm_orders_after_payment' => $this->restaurant->auto_confirm_orders_after_payment,
                'auto_confirm_orders_before_payment' => $this->restaurant->auto_confirm_orders_before_payment,
                'customer_lat' => $this->addressLat ?? null,
                'customer_lng' => $this->addressLng ?? null,
                'delivery_fee' => $this->deliveryFee ?? 0,
                'is_within_radius' => true,
                'delivery_started_at' => null,
                'delivered_at' => null,
                'estimated_eta_min' => $this->etaMin ?? null,
                'estimated_eta_max' => $this->etaMax ?? null,
                'placed_via' => 'shop',
                'tax_base' => $this->taxBase,
                'tax_mode' => $this->taxMode,
            ];

            // Add loyalty points redemption if module is enabled and points are redeemed
            if ($this->isLoyaltyEnabled() && $this->loyaltyPointsRedeemed > 0) {
                $orderData['loyalty_points_redeemed'] = $this->loyaltyPointsRedeemed;
                $orderData['loyalty_discount_amount'] = $this->loyaltyDiscountAmount;
            }

            $orderData = array_merge($orderData, $this->buildHotelRoomOrderContext());

            $order = Order::create($orderData);
        }

        if ($this->customer && $this->isDeliveryOrder()) {
            $this->persistCustomerDeliveryLocation();
        }

        $transactionId = uniqid('TXN_', true) . '_' . random_int(100000, 999999);

        session(['transaction_id' => $transactionId]);

        // CRITICAL: Create order_items FIRST so we can link kot_items to them
        // This ensures kot_items have price and amount from order_items
        $orderItems = [];

        // Only create KOT if there are items to add (new items for existing order, or all items for new order)
        $kot = null;
        if (!empty($this->orderItemList)) {
            $kot = Kot::create([
                'branch_id' => $this->shopBranch->id,
                'kot_number' => (Kot::generateKotNumber($this->shopBranch) + 1),
                'order_id' => $order->id,
                'order_type_id' => $order->order_type_id,
                'token_number' => Kot::generateTokenNumber($this->shopBranch->id, $order->order_type_id),
                'note' => $this->orderNote,
                'transaction_id' => $transactionId
            ]);
        }

        // Only create order items for new items (existing items remain untouched)
        foreach ($this->orderItemList ?? [] as $key => $value) {
            $menuItemId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menu_item_id : $this->orderItemList[$key]->id;

            $menuItemVariationId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->id : null;

            $orderItem = OrderItem::create([
                'branch_id' => $this->shopBranch->id,
                'order_id' => $order->id,
                'menu_item_id' => $menuItemId,
                'menu_item_variation_id' => $menuItemVariationId,
                'quantity' => $this->orderItemQty[$key],
                'price' => (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $value->price),
                'amount' => $this->orderItemAmount[$key],
                'transaction_id' => $transactionId,
                'note' => $this->itemNotes[$key] ?? null,
                // Add tax fields for item-level tax mode
                'tax_amount' => $this->orderItemTaxDetails[$key]['tax_amount'] ?? null,
                'tax_percentage' => $this->orderItemTaxDetails[$key]['tax_percent'] ?? null,
                'tax_breakup' => isset($this->orderItemTaxDetails[$key]['tax_breakup']) ? json_encode($this->orderItemTaxDetails[$key]['tax_breakup']) : null,
            ]);


            $this->itemModifiersSelected[$key] = $this->itemModifiersSelected[$key] ?? [];
            $orderItem->modifierOptions()->sync($this->itemModifiersSelected[$key]);

            // Store order item for linking with kot_item
            $orderItems[$key] = $orderItem;
        }

        // Now create kot_items with price and amount from order_items (only for new items)
        if ($kot) {
            foreach ($this->orderItemList ?? [] as $key => $value) {
                $orderItem = $orderItems[$key] ?? null;

                // CRITICAL: Ensure order_item exists before creating kot_item
                if (!$orderItem || !$orderItem->id) {
                    Log::error('Missing order_item for key: ' . $key, [
                        'order_id' => $order->id,
                        'orderItemList_keys' => array_keys($this->orderItemList ?? []),
                        'orderItems_keys' => array_keys($orderItems),
                    ]);
                    continue; // Skip this kot_item if order_item is missing
                }

                // Get price and amount from order_item (which has the correct values)
                $itemPrice = $orderItem->price;
                $itemAmount = $orderItem->amount;

                $menuItemId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menu_item_id : $this->orderItemList[$key]->id;

                $menuItemVariationId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->id : null;

                $kotItem = KotItem::create([
                    'kot_id' => $kot->id,
                    'order_item_id' => $orderItem->id, // Link to order_item - must exist
                    'menu_item_id' => $menuItemId,
                    'menu_item_variation_id' => $menuItemVariationId,
                    'quantity' => $this->orderItemQty[$key],
                    'price' => $itemPrice, // Copy from order_item
                    'amount' => $itemAmount, // Copy from order_item
                    'transaction_id' => $transactionId,
                    'note' => $this->itemNotes[$key] ?? null,
                ]);

                $this->itemModifiersSelected[$key] = $this->itemModifiersSelected[$key] ?? [];
                $kotItem->modifierOptions()->sync($this->itemModifiersSelected[$key]);
            }
        }

        // Create order taxes BEFORE calculating totals (so they're available for calculation)
        if ($this->taxMode === 'order') {
            foreach ($this->taxes ?? [] as $key => $value) {
                OrderTax::firstOrCreate([
                    'order_id' => $order->id,
                    'tax_id' => $value->id
                ]);
            }
        }

        if ($this->orderID) {
            $order->extraCharges()->detach();
        }

        foreach ($this->extraCharges ?? [] as $key => $value) {
            if (!OrderCharge::where('order_id', $order->id)->where('charge_id', $value->id)->exists()) {
                OrderCharge::create([
                    'order_id' => $order->id,
                    'charge_id' => $value->id
                ]);
            }
        }

        // Reload order with all relationships
        $order->refresh();
        $order->load(['taxes.tax', 'items', 'charges.charge', 'kot.items.menuItem', 'kot.items.menuItemVariation', 'kot.items.modifierOptions']);

        // Recalculate totals using all KOT items for KOT orders to avoid overwriting previous totals
        $this->subTotal = 0;
        if ($order->status === 'kot' && $order->kot && $order->kot->count() > 0) {
            foreach ($order->kot as $orderKot) {
                foreach ($orderKot->items->where('status', '!=', 'cancelled') as $kotItem) {
                    if (!is_null($kotItem->amount)) {
                        $this->subTotal += (float)$kotItem->amount;
                        continue;
                    }

                    $menuItem = $kotItem->menuItem;
                    $variation = $kotItem->menuItemVariation;
                    $itemPrice = $variation ? ($variation->price ?? 0) : ($menuItem->price ?? 0);
                    $modifierPrice = $kotItem->modifierOptions ? $kotItem->modifierOptions->sum('price') : 0;
                    $this->subTotal += ($itemPrice + $modifierPrice) * ($kotItem->quantity ?? 1);
                }
            }
        } else {
            $this->subTotal = $order->items->sum('amount') ?? 0;
        }

        $discountedBase = $this->subTotal;
        $discountedBase -= ($order->discount_amount ?? 0);
        $discountedBase -= ($order->loyalty_discount_amount ?? 0);
        $discountedBase = max(0, (float)$discountedBase);

        // Step 1: Calculate service charges on discounted base
        $charges = collect($order->charges ?? [])
            ->map(fn ($orderCharge) => $orderCharge->charge)
            ->filter();

        $chargeTotals = RestaurantCharge::calculateTotals($charges, $discountedBase);
        $serviceTotal = $chargeTotals['total'];

        // Step 2: Calculate tax base
        $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? true;
        $this->taxBase = RestaurantCharge::resolveTaxBase($discountedBase, $charges, $includeChargesInTaxBase);

        // Step 3: Calculate taxes
        $this->totalTaxAmount = 0;
        if ($this->taxMode === 'order') {
            foreach ($this->taxes ?? [] as $tax) {
                $taxAmount = ($tax->tax_percent / 100) * $this->taxBase;
                $this->totalTaxAmount += $taxAmount;
            }
        } else {
            if ($order->items && $order->items->count() > 0) {
                $this->totalTaxAmount = (float)($order->items->sum('tax_amount') ?? 0);
            } elseif ($order->kot && $order->kot->count() > 0) {
                $this->totalTaxAmount = (float)$order->kot->sum(function ($kot) {
                    return $kot->items->sum('tax_amount');
                });
            }
        }

        // Step 4: Build total
        $this->total = $discountedBase + $serviceTotal;
        if ($this->taxMode === 'order') {
            $this->total += $this->totalTaxAmount;
        } else {
            $isInclusive = $this->restaurant->tax_inclusive ?? false;
            if (!$isInclusive) {
                $this->total += $this->totalTaxAmount;
            }
        }

        // Step 5: Add delivery and tip
        $this->total += (float)$this->deliveryFee ?: 0;
        $this->total += $order->tip_amount ?? 0;

        // Update order with calculated values
        Order::where('id', $order->id)->update([
            'sub_total' => round($this->subTotal, 2),
            'total' => round($this->total, 2),
            'total_tax_amount' => round($this->totalTaxAmount, 2),
            'tax_base' => $this->taxBase,
            'tax_mode' => $this->taxMode,
        ]);

        // Deduct loyalty points if module is enabled and points are redeemed
        // This happens AFTER taxes are calculated so we can recalculate totals correctly
        if ($this->isLoyaltyEnabled() && $this->loyaltyPointsRedeemed > 0 && $this->loyaltyDiscountAmount > 0 && $order->customer_id) {
            try {
                $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                $result = $loyaltyService->redeemPoints($order, $this->loyaltyPointsRedeemed);

                if ($result['success']) {
                    // Reload order to get updated loyalty_discount_amount
                    $order->refresh();
                    $order->load(['taxes.tax', 'items', 'charges.charge', 'restaurant']);

                    // Recalculate total with loyalty discount
                    // Start fresh from item amounts to ensure correct calculation
                    $correctSubTotal = $order->items->sum('amount') ?? 0;
                    $correctTotal = $correctSubTotal;

                    // Apply discounts
                    $correctTotal -= ($order->discount_amount ?? 0);
                    $correctTotal -= ($order->loyalty_discount_amount ?? 0);
                    $discountedBase = $correctTotal;

                    // Calculate taxes on discounted amount (ensure float precision)
                    $correctTaxAmount = 0.0;
                    if ($order->tax_mode === 'order' && $order->taxes && $order->taxes->count() > 0) {
                        // Order-level taxes - calculate on discounted base
                        // IMPORTANT: Don't round individual tax amounts, only round the final sum
                        foreach ($order->taxes as $orderTax) {
                            $tax = $orderTax->tax ?? null;
                            if ($tax) {
                                $taxPercent = (float)($tax->tax_percent ?? 0);
                                // Calculate tax amount with full precision (no rounding)
                                $taxAmount = ($taxPercent / 100.0) * (float)$discountedBase;
                                // Add to running total with full precision
                                $correctTaxAmount += $taxAmount;
                                $correctTotal += $taxAmount; // Always add order-level taxes
                            }
                        }
                        // Round ONLY the final sum to 2 decimal places
                        $correctTaxAmount = round($correctTaxAmount, 2);
                    } else {
                        // Item-level taxes - sum from order items
                        $correctTaxAmount = $order->items->sum('tax_amount') ?? 0;
                        // Check if taxes are inclusive or exclusive
                        $isInclusive = ($order->restaurant->tax_inclusive ?? $this->restaurant->tax_inclusive ?? false);
                        if (!$isInclusive && $correctTaxAmount > 0) {
                            // For exclusive taxes, add to total
                            // CRITICAL: Always add exclusive taxes to ensure total includes tax
                            $correctTotal += $correctTaxAmount;
                        }
                        // For inclusive taxes, tax is already included in item prices (amount field)
                        // So we don't add it to total, but we still track it for total_tax_amount
                    }

                    // Apply extra charges (on discounted base)
                    if ($order->charges && $order->charges->count() > 0) {
                        foreach ($order->charges as $orderCharge) {
                            $charge = $orderCharge->charge;
                            if ($charge) {
                                $correctTotal += $charge->getAmount($discountedBase);
                            }
                        }
                    }

                    // Add tip and delivery
                    $correctTotal += ($order->tip_amount ?? 0);
                    $correctTotal += ($order->delivery_fee ?? 0);

                    // Update total and tax_amount in database
                    \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update([
                        'total' => round($correctTotal, 2),
                        'total_tax_amount' => round($correctTaxAmount, 2),
                    ]);

                    $order->refresh();
                    $this->total = $order->total;
                } else {
                    // Redemption failed - clear discount
                    $order->update([
                        'loyalty_points_redeemed' => 0,
                        'loyalty_discount_amount' => 0,
                    ]);
                    $this->resetLoyaltyRedemption();
                    $this->alert('error', $result['message'] ?? __('messages.loyaltyRedemptionFailed'), [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to redeem loyalty points in Cart: ' . $e->getMessage());
                $order->update([
                    'loyalty_points_redeemed' => 0,
                    'loyalty_discount_amount' => 0,
                ]);
                $this->resetLoyaltyRedemption();
            }
        }

        $autoConfirmFlags = ShopCartKotPrintUrls::shopAutoConfirmFlags($order, $this->restaurant);
        $autoConfirmBeforePayment = $autoConfirmFlags['before'];

        // Auto-confirm on: print at confirmation (before-payment at placement incl. pay now; after-payment only after payment — see ShopCartKotPrintUrls).
        // Auto-confirm off: pay later prints immediately below; pay now prints when payment completes.
        if ($kot && $order->status != 'draft' && $autoConfirmBeforePayment) {
            if (!$autoConfirmFlags['tie']) {
                if (!$pay) {
                    $this->printKot($order, $kot);
                }
            } else {
                $this->printKot($order, $kot);
            }
        }

        event(new OrderUpdated($order, 'updated'));

        if (!is_null($this->tableID)) {
            $table->available_status = 'running';
            $table->saveQuietly();
        }

        if ($pay) {
            $this->paymentOrder = $order;

            $autoStartResult = $this->autoStartSinglePaymentOption((int) $order->id);
            if ($autoStartResult === false) {
                $this->showPaymentModal = true;
                $this->orderBeingProcessed = false;

                return;
            }

            if ($autoStartResult instanceof \Symfony\Component\HttpFoundation\Response) {
                return $autoStartResult;
            }

            $this->orderBeingProcessed = false;

            return;
        } else {
            Order::where('id', $order->id)->update([
                'status' => 'kot'
            ]);

            $order->refresh();
            if ($kot && !$autoConfirmBeforePayment) {
                if (!$autoConfirmFlags['tie'] || !$autoConfirmFlags['after']) {
                    $this->printKot($order, $kot);
                }
            }

            $this->sendNotifications($order);

            $this->alert('success', __('messages.orderSaved'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close')
            ]);

            cache()->forget('branch_' . $this->shopBranch->id . '_order_stats');
            $this->clearClientBrowseCart();
            $this->redirect(route('order_success', [$order->uuid]));
        }
        } catch (\Throwable $e) {
            $this->orderBeingProcessed = false;

            throw $e;
        }
    }

    public function initiatePayment($id)
    {
        $total = round($this->total, 2);

        $payment = RazorpayPayment::create([
            'order_id' => $id,
            'amount' => $total,
        ]);

        $orderData = [
            'amount' => ($total * 100),
            'currency' => $this->restaurant->currency->currency_code,
        ];

        $apiKey = $this->restaurant->paymentGateways->razorpay_key;
        $secretKey = $this->restaurant->paymentGateways->razorpay_secret;

        try {
            $api = new Api($apiKey, $secretKey);

            $razorpayOrder = $api->order->create($orderData);

            $payment->razorpay_order_id = $razorpayOrder->id;
            $payment->save();

            $this->dispatch('paymentInitiated', payment: $payment);

        } catch (\Throwable $e) {

            Log::error('Razorpay order creation failed', [
                'order_id' => $id,
                'currency' => $orderData['currency'],
                'message' => $e->getMessage(),
            ]);

            $payment->delete();

            $this->alert('error', __('messages.paymentInitiationFailed'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            $this->orderBeingProcessed = false;

            return;
        }
    }

    public function initiateStripePayment($id)
    {
        $payment = StripePayment::create([
            'order_id' => $id,
            'amount' => $this->total
        ]);

        $this->showPaymentModal = false;
        $this->showStripeOrderPaymentModal = true;
        $this->dispatch('stripeOrderEmbeddedInit', stripePaymentId: $payment->id);
    }

    public function closeStripeOrderPaymentModal(): void
    {
        $this->showStripeOrderPaymentModal = false;
    }

    #[On('razorpayPaymentCompleted')]
    public function razorpayPaymentCompleted($razorpayPaymentID, $razorpayOrderID, $razorpaySignature)
    {
        $this->orderBeingProcessed = true;

        $payment = RazorpayPayment::where('razorpay_order_id', $razorpayOrderID)
            ->where('payment_status', 'pending')
            ->first();

        if ($payment) {
            $payment->razorpay_payment_id = $razorpayPaymentID;
            $payment->payment_status = 'completed';
            $payment->payment_date = now()->toDateTimeString();
            $payment->razorpay_signature = $razorpaySignature;
            $payment->save();

            $order = Order::find($payment->order_id);
            $order->amount_paid = $this->total;
            $order->status = 'paid';
            $order->save();

            Payment::create([
                'order_id' => $payment->order_id,
                'branch_id' => $this->shopBranch->id,
                'payment_method' => 'razorpay',
                'amount' => $payment->amount,
                'transaction_id' => $razorpayPaymentID
            ]);

            if ($order->placed_via === 'shop' && ShopCartKotPrintUrls::shouldPrintKotAfterShopOnlinePayment($order, $this->restaurant)) {
                $this->printKot($order->fresh(['kot.items.menuItem', 'kot.items.menuItemVariation', 'kot.items.modifierOptions']));
            }

            $this->sendNotifications($order);

            $this->alert('success', __('messages.orderSaved'), [
                'toast' => false,
                'position' => 'center',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close')
            ]);

            // Check if order was placed via kiosk and redirect accordingly
            if ($order->placed_via === 'kiosk') {
                $this->redirect(route('kiosk.order-confirmation', $payment->order->uuid));
            } else {
                $this->redirect(route('order_success', $payment->order->uuid));
            }
        } else {
            $this->orderBeingProcessed = false;
        }
    }

    public function initiateFlutterwavePayment($id)
    {
        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $apiSecret = $paymentGateway->flutterwave_secret;
            $amount = $this->total;
            $tx_ref = 'txn_' . time();

            $user = $this->customer ?? $this->restaurant;


            $data = [
                'tx_ref' => $tx_ref,
                'amount' => $amount,
                'currency' => $this->restaurant->currency->currency_code,
                'redirect_url' => route('flutterwave.success'),
                'payment_options' => 'card',
                'customer' => [
                    'email' => $user->email ?? 'no-email@example.com',
                    'name' => $user->name ?? 'Guest',
                    'phone_number' => $user->phone ?? '0000000000',
                ],
            ];
            $response = Http::withHeaders([
                'Authorization' => "Bearer $apiSecret",
                'Content-Type' => 'application/json'
            ])->post('https://api.flutterwave.com/v3/payments', $data);

            $responseData = $response->json();

            if (isset($responseData['status']) && $responseData['status'] === 'success') {
                FlutterwavePayment::create([
                    'order_id' => $id,
                    'flutterwave_payment_id' => $tx_ref,
                    'amount' => $amount,
                    'payment_status' => 'pending',
                ]);

                return redirect($responseData['data']['link']);
            } else {
                return redirect()->route('flutterwave.failed')->withErrors(['error' => 'Payment initiation failed', 'message' => $responseData]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function initiatePaypalPayment($id)
    {
        $amount = $this->total;
        $currency = strtoupper($this->restaurant->currency->currency_code);

        $unsupportedCurrencies = ['INR'];
        if (in_array($currency, $unsupportedCurrencies)) {
            $order = Order::find($id);
            session()->flash('flash.banner', __('messages.paypalCurrencyNotSupported'));
            session()->flash('flash.bannerStyle', 'warning');
            return redirect()->route('order_success', $order->uuid ?? $id);
        }

        $clientId = $this->paymentGateway->paypal_payment_client_id;
        $secret = $this->paymentGateway->paypal_payment_secret;

        $paypalPayment = new PaypalPayment();
        $paypalPayment->order_id = $id;
        $paypalPayment->amount = $amount;
        $paypalPayment->payment_status = 'pending';
        $paypalPayment->save();

        $returnUrl = route('paypal.success');
        $cancelUrl = route('paypal.cancel');

        $paypalData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($amount, 2, '.', '')
                ],
                'reference_id' => (string)$paypalPayment->id
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl
            ]
        ];
        info('Paypal Data: ' . json_encode($paypalData));

        $auth = base64_encode("$clientId:$secret");

        $response = Http::withHeaders([
            'Authorization' => "Basic $auth",
            'Content-Type' => 'application/json'
        ])->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', $paypalData);

        if ($response->successful()) {
            $paypalResponse = $response->json();

            $paypalPayment->paypal_payment_id = $paypalResponse['id'];
            $paypalPayment->payment_status = 'pending';
            $paypalPayment->save();

            $approvalLink = collect($paypalResponse['links'])->firstWhere('rel', 'approve')['href'];
            return redirect($approvalLink);
        }
        $paypalPayment->payment_status = 'failed';
        $paypalPayment->save();

        return redirect()->route('paypal.cancel');
    }

    public function initiateEpayPayment($id)
    {
        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $amount = $this->total;
            $isSandbox = $paymentGateway->epay_mode === 'sandbox';

            $clientId = $isSandbox ? $paymentGateway->test_epay_client_id : $paymentGateway->epay_client_id;
            $clientSecret = $isSandbox ? $paymentGateway->test_epay_client_secret : $paymentGateway->epay_client_secret;
            $terminalId = $isSandbox ? $paymentGateway->test_epay_terminal_id : $paymentGateway->epay_terminal_id;

            $order = Order::find($id);
            if (!$order) {
                session()->flash('flash.banner', __('messages.orderNotFound'));
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->back();
            }

            if (!$clientId || !$clientSecret || !$terminalId) {
                session()->flash('flash.banner', __('messages.epayCredentialsNotConfigured'));
                session()->flash('flash.bannerStyle', 'warning');
                return redirect()->route('order_success', $order->uuid);
            }

            // Generate secret hash (random string for security)
            $secretHash = bin2hex(random_bytes(16));

            // Create payment record first to get unique ID
            $epayPayment = EpayPayment::create([
                'order_id' => $id,
                'amount' => $amount,
                'payment_status' => 'pending',
                'epay_secret_hash' => $secretHash,
            ]);

            // Generate unique invoice ID for THIS payment attempt - must be 6-15 digits
            // Use payment ID + timestamp to ensure uniqueness across all attempts
            // Format: payment_id (padded) + last 4 digits of timestamp = always unique
            $paymentIdStr = (string)$epayPayment->id;
            $timestampSuffix = substr((string)time(), -4); // Last 4 digits of timestamp
            $invoiceIdBase = $paymentIdStr . $timestampSuffix;

            // Ensure it's between 6-15 digits as per Epay requirements
            if (strlen($invoiceIdBase) < 6) {
                // Pad with zeros to reach minimum 6 digits
                $invoiceId = str_pad($invoiceIdBase, 6, '0', STR_PAD_LEFT);
            } elseif (strlen($invoiceIdBase) > 15) {
                // Truncate to 15 digits if too long
                $invoiceId = substr($invoiceIdBase, -15);
            } else {
                $invoiceId = $invoiceIdBase;
            }

            // Update payment record with invoice ID
            $epayPayment->epay_invoice_id = $invoiceId;
            $epayPayment->save();

            // Get access token with payment details - returns full token object
            $tokenResponse = $this->getEpayAccessToken($paymentGateway, $isSandbox, $invoiceId, $secretHash, $amount);
            if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
                $epayPayment->payment_status = 'failed';
                $epayPayment->save();
                $order = Order::find($id);
                session()->flash('flash.banner', __('messages.epayFailedToAuthenticate'));
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->route('order_success', $order->uuid ?? $id);
            }

            // Store full token response as JSON in payment record
            $epayPayment->epay_access_token = json_encode($tokenResponse);
            $epayPayment->save();

            // Store invoiceId in session for success/cancel callbacks (Epay doesn't always send it in redirect)
            session([
                'epay_invoice_id' => $invoiceId,
                'epay_order_id' => $id,
                'epay_payment_id' => $epayPayment->id,
            ]);

            // Reload payment with all relationships for JavaScript
            $epayPayment->load(['order.customer']);

            // Dispatch event to trigger payment directly on current page (like Razorpay)
            $this->dispatch('epayPaymentInitiated', payment: $epayPayment);
        } catch (\Exception $e) {
            Log::error('Epay Payment Initiation Error: ' . $e->getMessage());
            $order = Order::find($id);
            session()->flash('flash.banner', __('messages.paymentInitiationFailedWithError', ['message' => $e->getMessage()]));
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->route('order_success', $order->uuid ?? $id);
        }
    }

    private function getEpayAccessToken($paymentGateway, $isSandbox, $invoiceId, $secretHash, $amount)
    {
        $clientId = $isSandbox ? $paymentGateway->test_epay_client_id : $paymentGateway->epay_client_id;
        $clientSecret = $isSandbox ? $paymentGateway->test_epay_client_secret : $paymentGateway->epay_client_secret;
        $terminalId = $isSandbox ? $paymentGateway->test_epay_terminal_id : $paymentGateway->epay_terminal_id;

        if (!$clientId || !$clientSecret || !$terminalId) {
            session()->flash('flash.banner', __('messages.epayCredentialsNotConfigured'));
            session()->flash('flash.bannerStyle', 'warning');
            return null;
        }

        // Correct token URL according to documentation
        $tokenUrl = $isSandbox
            ? 'https://test-epay-oauth.epayment.kz/oauth2/token'
            : 'https://epay-oauth.homebank.kz/oauth2/token';

        $currency = strtoupper($this->restaurant->currency->currency_code);
        $postLink = route('epay.webhook', ['hash' => $this->restaurant->hash]);
        $failurePostLink = route('epay.webhook', ['hash' => $this->restaurant->hash]);

        $response = Http::asForm()->post($tokenUrl, [
            'grant_type' => 'client_credentials',
            'scope' => 'payment',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'invoiceID' => $invoiceId,
            'secret_hash' => $secretHash,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'terminal' => $terminalId,
            'postLink' => $postLink,
            'failurePostLink' => $failurePostLink,
        ]);

        if ($response->successful()) {
            $tokenData = $response->json();
            // Return the complete token object, not just access_token
            // The auth field in halyk.pay() expects the full token response
            return $tokenData;
        }

        $errorResponse = $response->json();
        Log::error('Epay Token Error: ' . json_encode($errorResponse));
        session()->flash('flash.banner', __('messages.epayFailedToAuthenticateCheckCredentials'));
        session()->flash('flash.bannerStyle', 'danger');
        return null;
    }

    function generateSignature($data, $passPhrase)
    {
        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
            }
        }
        $getString = substr($pfOutput, 0, -1);
        if ($passPhrase !== null) {
            $getString .= '&passphrase=' . urlencode(trim($passPhrase));
        }

        return md5($getString);
    }

    public function initiatePayfastPayment($id)
    {
        $paymentGateway = $this->restaurant->paymentGateways;
        $isSandbox = $paymentGateway->payfast_mode === 'sandbox';
        $merchantId = $isSandbox ? $paymentGateway->test_payfast_merchant_id : $paymentGateway->payfast_merchant_id;
        $merchantKey = $isSandbox ? $paymentGateway->test_payfast_merchant_key : $paymentGateway->payfast_merchant_key;
        $passphrase = $isSandbox ? $paymentGateway->test_payfast_passphrase : $paymentGateway->payfast_passphrase;
        $amount = number_format($this->total, 2, '.', '');
        $itemName = "Order Payment #$id";
        $reference = 'pf_' . time();
        $data = [
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url' => route('payfast.success', ['reference' => $reference]),
            'cancel_url' => route('payfast.failed', ['reference' => $reference]),
            'notify_url' => route('payfast.notify', ['company' => $this->restaurant->hash, 'reference' => $reference]),

            'name_first' => auth()->user()->name,
            'email_address' => auth()->user()->email,
            'm_payment_id' => $id, // Your internal ID
            'amount' => $amount,
            'item_name' => $itemName,
        ];


        $signature = $this->generateSignature($data, $passphrase);
        $data['signature'] = $signature;

        AdminPayfastPayment::create([
            'order_id' => $id,
            'payfast_payment_id' => $reference,
            'amount' => $amount,
            'payment_status' => 'pending',
        ]);

        $payfastBaseUrl = $isSandbox ? 'https://sandbox.payfast.co.za/eng/process' : 'https://api.payfast.co.za/eng/process';
        $redirectUrl = $payfastBaseUrl . '?' . http_build_query($data);
        return redirect($redirectUrl);
    }

    public function initiatePaystackPayment($id)
    {
        try {
            $paymentGateway = $this->restaurant->paymentGateways;

            $secretKey = $paymentGateway->paystack_secret_data;
            $user = auth()->user();
            $amount = $this->total;
            $reference = 'psk_' . time();
            $data = [
                'reference' => $reference,
                'amount' => (int)($amount * 100), // Paystack expects amount in kobo
                'email' => $user->email ?? 'guest@example.com',
                'currency' => $this->restaurant->currency->currency_code,
                'callback_url' => route('paystack.success'),
                'metadata' => [
                    'cancel_action' => route('paystack.failed', ['reference' => $reference])
                ]

            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer $secretKey",
                'Content-Type' => 'application/json'
            ])->post('https://api.paystack.co/transaction/initialize', $data);

            $responseData = $response->json();
            if (isset($responseData['status']) && $responseData['status'] === true) {
                AdminPaystackPayment::create([
                    'order_id' => $id,
                    'paystack_payment_id' => $reference,
                    'amount' => $amount,
                    'payment_status' => 'pending',
                ]);

                return redirect($responseData['data']['authorization_url']);
            } else {

                session()->flash('error', __('messages.paymentInitiationFailed'));
                return redirect()->route('paystack.failed');
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function initiateXenditPayment($id)
    {
        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $secretKey = $paymentGateway->xendit_secret_key;
            $amount = $this->total;
            $externalId = 'xendit_' . time();

            $user = $this->customer ?? auth()->user();

            $data = [
                'external_id' => $externalId,
                'amount' => $amount,
                'description' => 'Order Payment #' . $id,
                'currency' => 'PHP',
                'success_redirect_url' => route('xendit.success', ['external' => $externalId]),
                'failure_redirect_url' => route('xendit.failed'),
                'payment_methods' => ['CREDIT_CARD', 'BCA', 'BNI', 'BSI', 'BRI', 'MANDIRI', 'OVO', 'DANA', 'LINKAJA', 'SHOPEEPAY'],
                'should_send_email' => true,
                'customer' => [
                    'given_names' => $user->name ?? 'Guest',
                    'email' => $user->email ?? 'guest@example.com',
                    'mobile_number' => $user->phone ?? '+6281234567890',
                ],
                'items' => [
                    [
                        'name' => 'Order #' . $id,
                        'quantity' => 1,
                        'price' => $amount,
                        'category' => 'FOOD_AND_BEVERAGE'
                    ]
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
                'Content-Type' => 'application/json'
            ])->post('https://api.xendit.co/v2/invoices', $data);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['id'])) {
                XenditPayment::create([
                    'order_id' => $id,
                    'xendit_payment_id' => $externalId,
                    'xendit_invoice_id' => $responseData['id'],
                    'xendit_external_id' => $externalId,
                    'amount' => $amount,
                    'payment_status' => 'pending',
                ]);

                return redirect($responseData['invoice_url']);
            } else {
                session()->flash('error', __('messages.xenditPaymentInitiationFailed', ['message' => $responseData['message'] ?? 'Unknown error']));
                return redirect()->route('xendit.failed');
            }
        } catch (\Exception $e) {
            session()->flash('error', __('messages.xenditPaymentError', ['message' => $e->getMessage()]));
            return redirect()->route('xendit.failed');
        }
    }

    public function initiateMolliePayment($id)
    {
        try {

            $paymentGateway = $this->restaurant->paymentGateways;
            $isSandbox = $paymentGateway->mollie_mode === 'test';
            $apiKey = $isSandbox ? $paymentGateway->test_mollie_key : $paymentGateway->live_mollie_key;
            $amount = $this->total;
            $currency = $this->restaurant->currency->currency_code;
            // Initialize Mollie API client
            $mollie = new MollieApiClient();
            $mollie->setApiKey($apiKey);

            // Format amount - Mollie expects amount in smallest currency unit (e.g., cents for EUR)
            // Format as string with 2 decimal places
            $amountValue = number_format($amount, 2, '.', '');
            // Create payment
            $payment = $mollie->payments->create([

                "amount" => [
                    "currency" => $currency,
                    "value" => $amountValue,
                ],
                "description" => "Order Payment #" . $id,
                "redirectUrl" => route('mollie.success', ['order_id' => $id]),
                // Pass restaurant hash using expected route parameter name
                "webhookUrl"  => route('mollie.webhook', ['hash' => $this->restaurant->hash]),

            ]);


            // Store payment record
            AdminMolliePayment::create([
                'order_id' => $id,
                'mollie_payment_id' => $payment->id,
                'amount' => $amount,
                'payment_status' => 'pending',
            ]);


            // Redirect to Mollie checkout page
            return redirect($payment->getCheckoutUrl());


        } catch (\Exception $e) {
            Log::error('Mollie payment error: ' . $e->getMessage());

        }
    }

    public function initiateTapPayment($id)
    {
        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $amount = $this->total;
            $isSandbox = $paymentGateway->tap_mode === 'sandbox';

            $secretKey = $isSandbox ? $paymentGateway->test_tap_secret_key : $paymentGateway->live_tap_secret_key;
            $publicKey = $isSandbox ? $paymentGateway->test_tap_public_key : $paymentGateway->live_tap_public_key;
            $merchantId = $paymentGateway->tap_merchant_id;

            $order = Order::find($id);
            if (!$order) {
                session()->flash('flash.banner', __('messages.orderNotFound'));
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->back();
            }

            if (!$secretKey || !$publicKey || !$merchantId) {
                session()->flash('flash.banner', __('messages.tapCredentialsNotConfigured'));
                session()->flash('flash.bannerStyle', 'warning');
                return redirect()->route('order_success', $order->uuid);
            }

            $currency = strtoupper($this->restaurant->currency->currency_code);
            $customer = $this->customer ?? $order->customer;

            // Create payment record first
            $tapPayment = TapPayment::create([
                'order_id' => $id,
                'amount' => $amount,
                'payment_status' => 'pending',
            ]);

            // Prepare charge data for Tap Charge API
            $chargeData = [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
                'threeDSecure' => true,
                'save_card' => false,
                'description' => 'Order Payment #' . $id,
                'statement_descriptor' => 'Order #' . $id,
                'metadata' => [
                    'udf1' => 'Order ID: ' . $id,
                    'udf2' => 'Restaurant: ' . $this->restaurant->name,
                ],
                'reference' => [
                    'transaction' => 'txn_' . $id,
                    'order' => 'ord_' . $id,
                ],
                'receipt' => [
                    'email' => false,
                    'sms' => false,
                ],
                'customer' => [
                    'first_name' => $customer->name ?? 'Guest',
                    'email' => $customer->email ?? 'guest@example.com',
                    'phone' => [
                        'country_code' => $customer->phone_code ?? '966',
                        'number' => $customer->phone ?? '000000000',
                    ],
                ],
                'merchant' => [
                    'id' => $merchantId,
                ],
                'source' => [
                    'id' => 'src_all',
                ],
                'redirect' => [
                    'url' => route('tap.success', ['order_id' => $id]),
                ],
                'post' => [
                    'url' => route('tap.webhook', ['hash' => $this->restaurant->hash]),
                ],
            ];

            // Make API call to Tap Charge API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.tap.company/v2/charges', $chargeData);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['id'])) {
                // Update payment record with charge ID
                $tapPayment->tap_payment_id = $responseData['id'];
                $tapPayment->save();

                // Store order ID in session for fallback
                session(['tap_order_id' => $id]);

                $checkoutUrl = $responseData['transaction']['url'] ?? null;

                if ($checkoutUrl) {
                    return redirect()->away($checkoutUrl);
                } else {
                    if (isset($responseData['status']) && $responseData['status'] === 'CAPTURED') {
                        return redirect()->route('tap.success', ['order_id' => $id, 'tap_id' => $responseData['id']]);
                    } else {
                        session()->flash('flash.banner', __('messages.paymentInitiationFailedTryAgain'));
                        session()->flash('flash.bannerStyle', 'danger');
                        return redirect()->route('order_success', $order->uuid);
                    }
                }
            } else {
                // Payment initiation failed
                $tapPayment->payment_status = 'failed';
                $tapPayment->payment_error_response = $responseData;
                $tapPayment->save();

                $errorMessage = $responseData['errors'][0]['message'] ?? __('messages.paymentInitiationFailedTryAgain');
                session()->flash('flash.banner', $errorMessage);
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->route('order_success', $order->uuid);
            }
        } catch (\Exception $e) {
            Log::error('Tap Payment Initiation Error: ' . $e->getMessage());
            $order = Order::find($id);
            session()->flash('flash.banner', __('messages.paymentInitiationFailedWithError', ['message' => $e->getMessage()]));
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->route('order_success', $order->uuid ?? $id);
        }
    }

    private function normalizeTlyncPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '218')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (preg_match('/^9\d{8}$/', $digits)) {
            return '0' . $digits;
        }

        return null;
    }

    public function initiateTlyncPayment($id)
    {
        $this->showPaymentModal = false;

        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $amount = $this->total;
            $mode = ($paymentGateway->tlync_mode ?? 'test') === 'live' ? 'live' : 'test';
            $storeId = $mode === 'live' ? $paymentGateway->live_tlync_store_id : $paymentGateway->test_tlync_store_id;
            $storeToken = $mode === 'live' ? $paymentGateway->live_tlync_store_token : $paymentGateway->test_tlync_store_token;

            $order = Order::find($id);
            if (!$order) {
                session()->flash('flash.banner', __('messages.orderNotFound'));
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->back();
            }

            if (!$storeId || !$storeToken) {
                session()->flash('flash.banner', __('messages.tlyncCredentialsNotConfigured'));
                session()->flash('flash.bannerStyle', 'warning');
                return redirect()->route('order_success', $order->uuid);
            }

            $customer = $this->customer ?? $order->customer;
            $phone = $this->normalizeTlyncPhone($this->customerPhone ?? $customer?->phone);

            if (!$phone) {
                $this->pendingTlyncOrderId = $id;
                if (!$this->customer && $customer) {
                    $this->customer = $customer;
                }
                $this->customerName = $this->customerName ?: ($customer?->name ?? '');
                $this->customerPhone = $customer?->phone ?? '';
                $this->showCustomerNameModal = true;
                return;
            }

            if (! tlync_public_base_url()) {
                session()->flash('flash.banner', __('messages.tlyncPublicBaseUrlRequired'));
                session()->flash('flash.bannerStyle', 'warning');
                return redirect()->route('order_success', $order->uuid);
            }

            $tlyncPayment = TlyncPayment::create([
                'order_id' => $id,
                'amount' => $amount,
                'phone' => $phone,
                'payment_status' => 'pending',
            ]);

            $customRef = $tlyncPayment->id . '|' . $id . '|' . $this->restaurant->hash;
            $tlyncPayment->custom_ref = $customRef;
            $tlyncPayment->save();

            $customerEmail = $customer?->email;
            $email = (is_string($customerEmail) && filter_var($customerEmail, FILTER_VALIDATE_EMAIL))
                ? $customerEmail
                : null;

            $tlync = app(TlyncPaymentService::class);
            $payload = $tlync->buildInitiatePayload(
                $storeId,
                $customRef,
                $amount,
                $phone,
                $this->restaurant->hash,
                $tlyncPayment->id,
                $email,
                $this->restaurant->name
            );

            Log::info('T-Lync initiate payload', $payload);

            $result = $tlync->initiate($storeId, $storeToken, $mode, $payload);

            if ($result['success'] && $result['url']) {
                session([
                    'tlync_payment_id' => $tlyncPayment->id,
                    'tlync_order_id' => $id,
                ]);

                $this->js('window.location.href = ' . json_encode($result['url']));

                return;
            }

            $tlyncPayment->payment_status = 'failed';
            $tlyncPayment->payment_error_response = $result['data'];
            $tlyncPayment->save();

            session()->flash('flash.banner', $result['error'] ?? __('messages.paymentInitiationFailedTryAgain'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('order_success', $order->uuid);
        } catch (\Exception $e) {
            Log::error('T-Lync Payment Initiation Error: ' . $e->getMessage());
            $order = Order::find($id);
            session()->flash('flash.banner', __('messages.paymentInitiationFailedWithError', ['message' => $e->getMessage()]));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('order_success', $order->uuid ?? $id);
        }
    }

    public function hidePaymentModal()
    {
        $this->showPaymentModal = false;
        $this->showQrCode = false;
        $this->showPaymentDetail = false;
        $this->selectedOfflinePaymentMethod = null;
        Order::where('id', $this->paymentOrder->id)->where('status', 'draft')->delete();

        Kot::where('transaction_id', session('transaction_id'))->delete();
        KotItem::where('transaction_id', session('transaction_id'))->delete();
        OrderItem::where('transaction_id', session('transaction_id'))->delete();

        session()->forget('transaction_id');

        $this->paymentOrder = null;
    }

    public function sendNotifications($order)
    {
        NewOrderCreated::dispatch($order);

        SendNewOrderReceived::dispatch($order);
        if ($order->customer_id) {
            try {
                $order->customer->notify(new SendOrderBill($order));
            } catch (\Exception $e) {
                Log::error('Error sending order bill email: ' . $e->getMessage());
            }
        }
    }

    public function toggleQrCode()
    {
        $this->showQrCode = !$this->showQrCode;
    }

    public function togglePaymenntDetail()
    {
        $this->showPaymentDetail = !$this->showPaymentDetail;
    }

    /**
     * Select an offline payment method (bank transfer / cash etc) and show its description.
     * The actual order placement happens when the user clicks the modal footer "paymentDone" button.
     */
    public function selectOfflinePaymentMethod($method)
    {
        if (empty($method)) {
            return;
        }

        $this->selectedOfflinePaymentMethod = $method;
        $this->showQrCode = false;
        $this->showPaymentDetail = true;
    }

    #[On('closeModifiersModal')]
    public function closeModifiersModal()
    {
        $this->selectedModifierItem = null;
        $this->showModifiersModal = false;
    }

    #[On('setPosModifier')]
    public function setPosModifier($modifierIds)
    {
        // Check radius restriction before adding items with modifiers
        if (!$this->checkRadiusRestriction()) {
            if (empty($this->addressLat) || empty($this->addressLng)) {
                $this->showLocationModal = true;
                $this->alert('error', __('app.locationAccessRequired'), [
                    'toast' => false,
                    'position' => 'center',
                ]);
            } else {
                $this->alert('error', __('app.outsideAllowedAreaMeters', ['meters' => $this->restaurant->qr_order_radius_meters]), [
                    'toast' => false,
                    'position' => 'center',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('app.close')
                ]);
            }
            return;
        }

        $this->showModifiersModal = false;

        $sortNumber = Str::of(implode('', Arr::flatten($modifierIds)))
            ->split(1)->sort()->implode('');

        $keyId = $this->selectedModifierItem . '-' . $sortNumber;

        if (isset(explode('_', $this->selectedModifierItem)[1])) {
            $menuItemVariation = MenuItemVariation::find(explode('_', $this->selectedModifierItem)[1]);

            // Set price context on variation
            if ($this->orderTypeId) {
                $menuItemVariation->setPriceContext($this->orderTypeId, null);
            }

            $this->orderItemVariation[$keyId] = $menuItemVariation;
            $this->selectedModifierItem = explode('_', $this->selectedModifierItem)[0];
            $this->orderItemAmount[$keyId] = 1 * ($this->orderItemVariation[$keyId]->price ?? $this->orderItemList[$keyId]->price);
        }

        $this->cartItemQty[$keyId] = ($this->cartItemQty[$keyId] ?? 0) + 1;
        $this->itemModifiersSelected[$keyId] = Arr::flatten($modifierIds);

        // Set price context on modifiers before calculating total
        $modifierTotal = 0;
        foreach ($this->itemModifiersSelected[$keyId] ?? [] as $modifierId) {
            $modifier = ModifierOption::find($modifierId);
            if ($modifier) {
                if ($this->orderTypeId) {
                    $modifier->setPriceContext($this->orderTypeId, null);
                }
                $modifierTotal += $modifier->price;
            }
        }

        $this->orderItemModifiersPrice[$keyId] = $modifierTotal;

        $this->syncCart($keyId);
    }

    public function getModifierOptionsProperty()
    {
        return ModifierOption::whereIn('id', collect($this->itemModifiersSelected)->flatten()->all())->get()->keyBy('id');
    }

    public function showItemDetail($id)
    {
        // Load counts so the detail modal button behaves like the item card
        $this->selectedItem = MenuItem::withCount(['variations', 'modifierGroups'])->find($id);
        $this->showItemDetailModal = true;
    }

    #[On('selectedDeliveryDetails')]
    public function handleSelectedDeliveryDetails($details)
    {
        $this->addressLat = $details['lat'] ?? null;
        $this->addressLng = $details['lng'] ?? null;
        $this->deliveryAddress = $details['address'] ?? null;
        $this->customerAddress = $this->deliveryAddress;

        if (empty(trim((string) ($this->deliveryAddress ?? ''))) && $this->addressLat && $this->addressLng) {
            $this->deliveryAddress = $this->addressLat . ', ' . $this->addressLng;
            $this->customerAddress = $this->deliveryAddress;
        }

        $this->deliveryFee = $details['deliveryFee'] ?? null;
        $this->etaMin = $details['eta_min'];
        $this->etaMax = $details['eta_max'];

        session([
            'customer_location' => [
                'lat' => $this->addressLat,
                'lng' => $this->addressLng,
                'address' => $this->deliveryAddress,
                'stored_at' => now()->toDateTimeString(),
            ],
        ]);

        $this->persistCustomerDeliveryLocation();

        $this->calculateMaxPreparationTime();
        $this->calculateTotal();
        $this->showDeliveryAddressModal = false;
    }

    public function calculateMaxPreparationTime()
    {
        $this->maxPreparationTime = !empty($this->orderItemList) ? max(array_map(fn($item) => $item->preparation_time ?? 0, $this->orderItemList)) : 0;
    }

    // Centralized tax calculation methods to eliminate code duplication
    private function recalculateTaxTotals($taxBase = null)
    {
        $this->totalTaxAmount = 0;

        if ($this->taxMode === 'order') {
            // Order-based taxation: calculate on tax_base (net + service_total)
            $baseForTax = $taxBase ?? $this->subTotal;

            foreach ($this->taxes ?? [] as $tax) {
                $taxAmount = ($tax->tax_percent / 100) * $baseForTax;
                $this->totalTaxAmount += $taxAmount;
                $this->total += $taxAmount;
            }
        } elseif ($this->taxMode === 'item' && !empty($this->orderItemAmount)) {
            // Item-based taxation - taxes are already calculated in calculateTotal()
            $totalInclusiveTax = 0;
            $totalExclusiveTax = 0;
            $isInclusive = $this->restaurant->tax_inclusive ?? false;

            // Calculate total tax amounts
            foreach ($this->orderItemTaxDetails ?? [] as $itemTaxDetail) {
                $taxAmount = $itemTaxDetail['tax_amount'] ?? 0;

                if ($isInclusive) {
                    $totalInclusiveTax += $taxAmount;
                } else {
                    $totalExclusiveTax += $taxAmount;
                }
            }

            $this->totalTaxAmount = $totalInclusiveTax + $totalExclusiveTax;

            // For exclusive taxes, add them to the total
            // (Inclusive taxes are already included in the item prices)
            if ($totalExclusiveTax > 0) {
                $this->total += $totalExclusiveTax;
            }
        }
    }

    public function updateOrderItemTaxDetails()
    {
        $this->orderItemTaxDetails = [];

        if ($this->taxMode !== 'item' || !is_array($this->orderItemAmount)) {
            return;
        }

        foreach ($this->orderItemAmount as $key => $value) {
            $menuItem = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menuItem : $this->orderItemList[$key];

            // Set price context before using prices
            if ($this->orderTypeId) {
                $menuItem->setPriceContext($this->orderTypeId, null);
                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, null);
                }
            }

            $qty = $this->orderItemQty[$key] ?? 1;
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $menuItem->price;
            $modifierPrice = $this->orderItemModifiersPrice[$key] ?? 0;
            $itemPriceWithModifiers = $basePrice + $modifierPrice;
            $taxes = $menuItem->taxes ?? collect();
            $isInclusive = $this->restaurant->tax_inclusive;
            $taxResult = MenuItem::calculateItemTaxes($itemPriceWithModifiers, $taxes, $isInclusive);
            $this->orderItemTaxDetails[$key] = [
                'tax_amount' => $taxResult['tax_amount'] * $qty,
                'tax_percent' => $taxResult['tax_percentage'],
                'tax_breakup' => $taxResult['tax_breakdown'],
                'tax_type' => $taxResult['inclusive'],
                'base_price' => $itemPriceWithModifiers,
                'display_price' => $isInclusive ? ($itemPriceWithModifiers - ($taxResult['tax_amount'] ?? 0)) : $itemPriceWithModifiers,
                'qty' => $qty,
            ];
        }
    }

    /**
     * Get the display price for an item (base price without tax for inclusive items)
     */
    public function getItemDisplayPrice($key)
    {
        if ($this->taxMode === 'item' && isset($this->orderItemTaxDetails[$key])) {
            return $this->orderItemTaxDetails[$key]['display_price'] ?? 0;
        }

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$key])) {
                $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, null);
            }
            if (isset($this->orderItemList[$key])) {
                $this->orderItemList[$key]->setPriceContext($this->orderTypeId, null);
            }
        }

        // For non-item tax mode, return the original price
        $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $this->orderItemList[$key]->price;
        $modifierPrice = $this->orderItemModifiersPrice[$key] ?? 0;
        return $basePrice + $modifierPrice;
    }

    #[Computed]
    public function getMenuItemsProperty()
    {
        $locale = session('locale', app()->getLocale());

        $query = MenuItem::select('menu_items.*', 'item_categories.category_name')
            ->join('item_categories', 'menu_items.item_category_id', '=', 'item_categories.id')
            ->where('menu_items.branch_id', $this->shopBranch->id)
            ->where('show_on_customer_site', true);

        if (!empty($this->filterCategories)) {
            $query->where('menu_items.item_category_id', $this->filterCategories);
        }

        // Filter menu items by table assignment when user came from QR.
        // If the table has assigned menus, show only those menus.
        // If the table does NOT have any assigned menu, show all menus.
        if ($this->cameFromTableQR && $this->table && $this->table->id) {
            $assignedMenuIds = DB::table('menu_table')
                ->where('table_id', $this->table->id)
                ->where('is_active', true)
                ->pluck('menu_id')
                ->toArray();

            if (!empty($assignedMenuIds)) {
                $query->whereIn('menu_items.menu_id', $assignedMenuIds);
            }
        }

        if (!empty($this->menuId)) {
            $query->where('menu_items.menu_id', $this->menuId);
        }

        if ($this->showVeg == 1) {
            $query->where('menu_items.type', 'veg');
        }

        if ($this->showHalal == 1) {
            $query->where('menu_items.type', 'halal');
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('item_name', 'like', '%' . $this->search . '%')
                    ->orWhereHas('translations', function ($q) {
                        $q->where('item_name', 'like', '%' . $this->search . '%');
                    });
            });
        }

        // Apply limit BEFORE loading heavy relationships
        $items = $query->orderBy('item_categories.sort_order')
            ->orderBy('menu_items.item_category_id')
            ->orderBy('menu_items.sort_order')
            ->limit($this->menuItemsLoaded)
            ->get();

        // Load relationships and counts only on the limited items
        $items->load('category');
        $items->loadCount(['variations', 'modifierGroups']);

        // Group by category (preserve category sort_order, not alphabetical label order)
        $groupedItems = $items
            ->groupBy('item_category_id')
            ->sortBy(fn ($group, $categoryId) => [
                (int) ($group->first()->category->sort_order ?? 0),
                (int) $categoryId,
            ])
            ->mapWithKeys(function ($group) use ($locale) {
                $label = $group->first()->category->getTranslation('category_name', $locale);

                return [$label => $group->values()];
            });

        // Set price context on menu items in the query results
        if ($this->orderTypeId) {
            foreach ($groupedItems as $categoryItems) {
                foreach ($categoryItems as $item) {
                    $item->setPriceContext($this->orderTypeId, null);
                    // Set price context on variations
                    if ($item->relationLoaded('variations')) {
                        foreach ($item->variations as $variation) {
                            $variation->setPriceContext($this->orderTypeId, null);
                        }
                    }
                }
            }
        }

        return $groupedItems;
    }

    #[Computed]
    public function getTotalMenuItemsCountProperty()
    {
        $query = MenuItem::where('branch_id', $this->shopBranch->id)
            ->where('show_on_customer_site', true);

        // Filter menu items by table assignment when user came from QR
        // If the table has assigned menus, show only those menus.
        // If the table does NOT have any assigned menu, show all menus.
        if ($this->cameFromTableQR && $this->table && $this->table->id) {
            $assignedMenuIds = DB::table('menu_table')
                ->where('table_id', $this->table->id)
                ->where('is_active', true)
                ->pluck('menu_id')
                ->toArray();

            if (!empty($assignedMenuIds)) {
                $query->whereIn('menu_id', $assignedMenuIds);
            }
            // If no menus assigned, show all items (don't filter)
        }

        if (!empty($this->filterCategories)) {
            $query->where('item_category_id', $this->filterCategories);
        }

        if (!empty($this->menuId)) {
            $query->where('menu_id', $this->menuId);
        }

        if ($this->showVeg == 1) {
            $query->where('type', 'veg');
        }

        if ($this->showHalal == 1) {
            $query->where('type', 'halal');
        }

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('item_name', 'like', '%' . $this->search . '%')
                    ->orWhereHas('translations', function ($q) {
                        $q->where('item_name', 'like', '%' . $this->search . '%');
                    });
            });
        }

        return $query->count();
    }

    #[Computed]
    public function getAllItemsLoadedProperty()
    {
        return $this->menuItemsLoaded >= $this->totalMenuItemsCount;
    }

    public function loadMoreMenuItems()
    {
        if ($this->allItemsLoaded) {
            return;
        }

        $this->menuItemsLoaded += $this->menuItemsPerLoad;
    }

    #[Computed]
    public function getCategoryListProperty()
    {
        return ItemCategory::withoutGlobalScopes()
            ->withCount(['items as items_count' => function ($query) {
                $query->where('menu_items.is_available', 1);

                if (!empty($this->menuId)) {
                    $query->where('menu_items.menu_id', $this->menuId);
                }

                if ($this->showVeg == 1) {
                    $query->where('menu_items.type', 'veg');
                }

                if ($this->showHalal == 1) {
                    $query->where('menu_items.type', 'halal');
                }
            }])
            ->where('branch_id', $this->shopBranch->id)
            ->having('items_count', '>', 0)
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function getMenuListProperty()
    {
        $query = Menu::withoutGlobalScopes()
            ->where('branch_id', $this->shopBranch->id);

        // Filter menus by table assignment when user came from QR
        // If the table has assigned menus, show only those menus.
        // If the table does NOT have any assigned menu, show all menus.
        if ($this->cameFromTableQR && $this->table && $this->table->id) {
            $assignedMenuIds = DB::table('menu_table')
                ->where('table_id', $this->table->id)
                ->where('is_active', true)
                ->pluck('menu_id')
                ->toArray();

            if (!empty($assignedMenuIds)) {
                $query->whereIn('id', $assignedMenuIds);
            }
            // If no menus assigned, show all menus (don't filter)
        }

        return $query->withCount('items')

            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function getOrderTypesProperty()
    {
        return OrderType::where('branch_id', $this->shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->availableForRestaurant()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    private function shouldRedirectToBookTable(): bool
    {
        $wantsBooking = request()->boolean('book_table')
            || request()->query('intent') === 'book'
            || request()->query('intent') === 'reservation';

        if (! $wantsBooking) {
            return false;
        }

        return $this->showBookTableEscapeOnOrderTypeModal();
    }

    private function showBookTableEscapeOnOrderTypeModal(): bool
    {
        if (! $this->restaurant->enable_customer_reservation) {
            return false;
        }

        $package = $this->restaurant->package;
        if (! $package) {
            return false;
        }

        $modules = $package->modules->pluck('name')->toArray();
        $additionalFeatures = json_decode($package->additional_features ?? '[]', true) ?: [];

        return in_array('Table Reservation', array_merge($modules, $additionalFeatures), true);
    }

    private function getBookTableUrl(): ?string
    {
        if (! $this->showBookTableEscapeOnOrderTypeModal()) {
            return null;
        }

        return route('book_a_table', ['hash' => $this->restaurant->hash]) . '?branch=' . $this->shopBranch->id;
    }

    private function shouldForceDineInFromQr(): bool
    {
        return $this->cameFromTableQR && ! $this->tableQrOrderingBlocked;
    }

    private function isCustomerDineInEnabled(): bool
    {
        return OrderType::query()
            ->where('branch_id', $this->shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->where('type', 'dine_in')
            ->availableForRestaurant()
            ->exists();
    }

    private function isCustomerRoomServiceEnabled(): bool
    {
        return OrderType::query()
            ->where('branch_id', $this->shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->where(function ($query) {
                $query->where('type', 'room_service')
                    ->orWhere('slug', 'room_service');
            })
            ->availableForRestaurant()
            ->exists();
    }

    private function isCustomerOrderTypePopupDisabled(): bool
    {
        return (bool) ($this->restaurant->disable_order_type_popup ?? false);
    }

    private function applyRestaurantDefaultOrderTypeIfPopupDisabled(): bool
    {
        if (! $this->isCustomerOrderTypePopupDisabled()) {
            return false;
        }

        $defaultId = (int) ($this->restaurant->default_order_type_id ?? 0);

        $defaultOrderType = null;
        if ($defaultId > 0) {
            $defaultOrderType = OrderType::query()
                ->where('id', $defaultId)
                ->where('branch_id', $this->shopBranch->id)
                ->where('enable_from_customer_site', true)
                ->where('is_active', true)
                ->availableForRestaurant()
                ->first();
        }

        if (! $defaultOrderType) {
            $defaultOrderType = OrderType::query()
                ->where('branch_id', $this->shopBranch->id)
                ->where('enable_from_customer_site', true)
                ->where('is_active', true)
                ->availableForRestaurant()
                ->orderByDesc('is_default')
                ->first();
        }

        if (! $defaultOrderType) {
            return false;
        }

        $this->applyOrderTypeId((int) $defaultOrderType->id);
        $this->showOrderTypeModal = false;
        $this->pendingClientOrderTypeSelection = false;
        $this->orderTypeConfirmedByUser = true;

        return true;
    }

    private function applyCustomerSiteOrderTypeDefaults(): void
    {
        if ($this->applyRestaurantDefaultOrderTypeIfPopupDisabled()) {
            return;
        }

        $this->orderType = $this->restaurant->allow_dine_in_orders ? 'dine_in' : ($this->restaurant->allow_customer_delivery_orders ? 'delivery' : 'pickup');

        $customerOrderTypes = OrderType::query()
            ->where('branch_id', $this->shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->availableForRestaurant()
            ->orderByDesc('is_default')
            ->get(['id', 'slug', 'type']);

        $orderTypeCount = $customerOrderTypes?->count() ?? 0;

        if ($orderTypeCount === 1) {
            $this->showOrderTypeModal = false;
            $orderTypeModel = $customerOrderTypes->first();
            $this->orderTypeId = $orderTypeModel->id;
            $this->orderTypeSlug = $orderTypeModel->slug;
            $this->orderType = $orderTypeModel->type;
            $this->orderTypeConfirmedByUser = true;
        } elseif ($orderTypeCount > 1) {
            $orderTypeModel = $customerOrderTypes->first();
            if ($orderTypeModel) {
                $this->applyOrderTypeId((int) $orderTypeModel->id);
            }
            $this->pendingClientOrderTypeSelection = false;
            $this->showOrderTypeModal = false;
        } else {
            $this->showOrderTypeModal = false;
            $this->orderType = 'dine_in';
            $this->setDefaultOrderType();
            $this->orderTypeConfirmedByUser = true;
        }
    }

    private function orderTypeCustomerDescription(OrderType $orderType): string
    {
        return match ($orderType->type) {
            'dine_in' => __('messages.dineInDescription'),
            'delivery' => __('messages.deliveryDescription'),
            'pickup' => __('messages.pickupDescription'),
            default => '',
        };
    }

    public function render()
    {
        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch);

        return view('livewire.shop.cart', [
            'orderTypes' => $this->orderTypes,
            'phonecodes' => $this->filteredPhoneCodes,
            'isRestaurantOpenForOrders' => (bool) ($availability['is_open'] ?? true),
            'restaurantClosedMessage' => RestaurantAvailabilityService::getMessage($availability, $this->restaurant),
            'clientShopCatalog' => $this->getClientShopCatalogPayload(),
            'clientShopBrowseConfig' => $this->getClientShopBrowseConfig(),
        ]);
    }

}
