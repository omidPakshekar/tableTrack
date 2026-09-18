<?php

namespace App\Livewire\Shop;

use App\Concerns\PrintsShopKot;
use App\Services\ShopCartKotPrintUrls;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantCharge;
use Razorpay\Api\Api;
use App\Models\Branch;
use App\Models\Payment;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use App\Models\PaypalPayment;
use App\Models\StripePayment;
use App\Models\RazorpayPayment;
use App\Models\FlutterwavePayment;
use App\Models\AdminPayfastPayment;
use App\Models\AdminPaystackPayment;
use App\Events\SendOrderBillEvent;
use App\Models\XenditPayment;
use App\Models\EpayPayment;
use App\Notifications\SendOrderBill;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentGatewayCredential;
use App\Models\OfflinePaymentMethod;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Mollie\Api\MollieApiClient;
use App\Models\AdminMolliePayment;
use App\Models\TapPayment;
use App\Models\TlyncPayment;
use App\Services\TlyncPaymentService;
use App\Services\RestaurantAvailabilityService;
use App\Traits\PrinterSetting;

class OrderDetail extends Component
{
    use LivewireAlert;
    use PrinterSetting;
    use PrintsShopKot;

    // Note: HasLoyaltyIntegration trait is conditionally loaded
    // If the Loyalty module doesn't exist, stub methods below handle it gracefully

    public $restaurant;
    public $shopBranch;
    public $order;
    public $id;

    #[Locked]
    public $embedLoyaltyOnly = false;
    public $customer;
    public $orderType;
    public $paymentGateway;
    public $razorpayStatus;
    public $stripeStatus;
    public bool $showStripeOrderPaymentModal = false;
    public $flutterwaveStatus;
    public $showPaymentModal = false;
    public $paymentOrder;
    public $showQrCode = false;
    public $showPaymentDetail = false;
    public $selectedOfflinePaymentMethod = null;
    public $qrCodeImage;
    public $total;
    public $canAddTip;
    public $tipAmount;
    public $tipNote;
    public $showTipModal = false;
    public $taxMode;
    public $totalTaxAmount = 0;
    // Properties needed for loyalty trait
    public $subTotal = 0;
    public $customerId;
    // Loyalty properties - defined here so they exist even if trait doesn't
    public $loyaltyPointsRedeemed = 0;
    public $loyaltyDiscountAmount = 0;
    public $availableLoyaltyPoints = 0;
    public $pointsToRedeem = 0;
    public $maxRedeemablePoints = 0;
    public $minRedeemPoints = 0;
    public $showLoyaltyRedemptionModal = false;
    public $loyaltyPointsValue = 0; // Total value of all available points
    public $maxLoyaltyDiscount = 0; // Maximum discount allowed (percentage of subtotal)
    public $dateFormat;
    public $timeFormat;
    public $offlinePaymentMethods = [];
    // Tier information
    public $currentTier = null;
    public $nextTier = null;
    public $pointsToNextTier = null;
    public $tierProgress = 0;
    // Loyalty enabled flag for view
    public $isLoyaltyEnabled = false;
    // Points enabled flag for customer site (separate from stamps)
    public $isPointsEnabledForCustomerSite = false;
    // Stamps enabled flag for customer site (separate from points)
    public $isStampsEnabledForCustomerSite = false;

    // Stamp redemption properties
    public $customerStamps = [];
    public $selectedStampRuleIds = []; // Array to support multiple selections
    public $showStampRedemptionModal = false;
    public $stampDiscountAmount = 0;

    use LivewireAlert;

    public function mount()
    {

        $customer = customer();

        $this->order = Order::withoutGlobalScopes()
            ->with([
                'taxes.tax',
                'items',
                'items.menuItem',
                'items.menuItemVariation',
                'items.modifierOptions',
                'charges.charge',
                'orderType',
                'orderCashCollection',
                ...(isHotelModuleEnabled($this->restaurant) ? [
                    'hotelStay.room',
                    'hotelStay.stayGuests.guest',
                    'hotelRoom',
                ] : []),
            ])
            ->where('id', $this->id)
            ->when(optional($customer)->id, fn($query) => $query->where('customer_id', $customer->id))
            ->firstOrFail();

        if (isHotelModuleEnabled($this->restaurant)) {
            $this->order->append(['context_room_number', 'context_stay_number']);
        }

        // Refresh order to ensure we have latest values from database (sub_total, total, etc.)
        $this->order->refresh();

        if ($this->order->customer_id && !$customer) {
            abort(404);
        }

        if (!$customer && $this->restaurant->customer_login_required) {
            return redirect()->route('home');
        }

        $this->restaurant->loadMissing('euAllergenSetting');

        $this->shopBranch = request()->filled('branch')
            ? Branch::find(request()->branch)
            : $this->restaurant->branches->first();

        $this->customer = $customer;
        $this->orderType = $this->order->order_type;
        $this->paymentOrder = $this->order;

        $this->paymentGateway = PaymentGatewayCredential::withoutGlobalScopes()->where('restaurant_id', $this->restaurant->id)->first();
        $this->razorpayStatus = (bool)$this->paymentGateway->razorpay_status;
        $this->stripeStatus = (bool)$this->paymentGateway->stripe_status;
        $this->flutterwaveStatus = (bool)$this->paymentGateway->flutterwave_status;

        // Load enabled offline payment methods
        $this->offlinePaymentMethods = OfflinePaymentMethod::where('restaurant_id', $this->restaurant->id)->where('status', 'active')->orderBy('created_at', 'desc')->get();

        $this->qrCodeImage = $this->restaurant->qr_code_image;
        $this->canAddTip = $this->restaurant->enable_tip_shop && !$this->order->isFullyPaid();
        $this->tipAmount = $this->order->tip_amount;
        $this->tipNote = $this->order->tip_note;

        // Set tax mode and calculate total tax amount
        $this->taxMode = $this->order?->tax_mode ?? ($this->restaurant->tax_mode ?? 'order');

        if ($this->taxMode === 'item') {
            $this->totalTaxAmount = $this->order?->items->sum('tax_amount') ?? 0;
        }

        // Set loyalty enabled flag for view (checks if EITHER points OR stamps are enabled)
        $this->isLoyaltyEnabled = $this->isLoyaltyEnabled();

        // Set points enabled flag specifically for customer site (only for points UI)
        $this->isPointsEnabledForCustomerSite = $this->isPointsEnabledForCustomerSite();

        // Set stamps enabled flag specifically for customer site (only for stamps UI)
        $this->isStampsEnabledForCustomerSite = $this->isStampsEnabledForCustomerSite();

        // Set properties needed for trait methods
        if ($this->customer) {
            $this->customerId = $this->customer->id;
            $this->subTotal = $this->order->sub_total ?? 0;
        }

        // Only load loyalty data if POINTS are enabled for customer site
        // This prevents loading points data when points platform is disabled
        if ($this->isPointsEnabledForCustomerSite && $this->customer) {
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);

            // Load tier information from module
            $this->loadTierInformationFromModule();
        } else {
            // Explicitly clear loyalty data if points are not enabled
            $this->availableLoyaltyPoints = 0;
            $this->currentTier = null;
            $this->nextTier = null;
            $this->pointsToNextTier = null;
            $this->tierProgress = 0;
            $this->loyaltyPointsValue = 0;
            $this->maxLoyaltyDiscount = 0;
        }

        // Load customer stamps if stamps are enabled for customer site
        if ($this->isStampsEnabledForCustomerSite && $this->customer) {
            $this->loadCustomerStamps();

            // Load existing stamp redemption from order if any
            if ($this->order->stamp_discount_amount > 0) {
                $this->stampDiscountAmount = $this->order->stamp_discount_amount;
                // Try to find which stamp rules were used (check order items for stamp_rule_id)
                $stampItems = $this->order->items()->whereNotNull('stamp_rule_id')->get();
                if ($stampItems->isNotEmpty()) {
                    $this->selectedStampRuleIds = $stampItems->pluck('stamp_rule_id')->unique()->values()->toArray();
                }
            }
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
        // Only check if restaurant_modules() returns a non-empty array (admin logged in)
        // If empty array (admin not logged in), skip this check and rely on settings check below
        if (function_exists('restaurant_modules')) {
            $restaurantModules = restaurant_modules();
            // Only fail if array is not empty and doesn't contain 'Loyalty'
            // If array is empty (admin not logged in), skip this check
            if (!empty($restaurantModules) && !in_array('Loyalty', $restaurantModules)) {
                return false;
            }
        }

        // Check platform-specific setting for Customer Site
        try {
            if (module_enabled('Loyalty')) {
                $restaurantId = $this->restaurant->id ?? null;
                if ($restaurantId) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    if (!$settings->enabled) {
                        return false;
                    }

                    // Check if points or stamps are enabled for customer site
                    // Check if new platform fields exist by checking if they're not null
                    // If null, fallback to old field (before migration)
                    $hasNewFields = !is_null($settings->enable_points_for_customer_site) && !is_null($settings->enable_stamps_for_customer_site);

                    if ($hasNewFields) {
                        // New fields exist - cast to bool to handle database boolean values (1/0) correctly
                        $pointsEnabled = (bool)$settings->enable_points && (bool)$settings->enable_points_for_customer_site;
                        $stampsEnabled = (bool)$settings->enable_stamps && (bool)$settings->enable_stamps_for_customer_site;
                    } else {
                        // Fallback to old field if new fields don't exist yet (before migration)
                        $pointsEnabled = (bool)$settings->enable_points && (bool)($settings->enable_for_customer_site ?? true);
                        $stampsEnabled = (bool)$settings->enable_stamps && (bool)($settings->enable_for_customer_site ?? true);
                    }

                    // Return true if either points or stamps are enabled for customer site
                    return $pointsEnabled || $stampsEnabled;
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    /**
     * Check if points are specifically enabled for customer site
     * This is separate from stamps - used for points-specific UI elements
     */
    public function isPointsEnabledForCustomerSite()
    {
        // Check if module is enabled
        if (!module_enabled('Loyalty')) {
            return false;
        }

        // Check if module is in restaurant's package
        // Only check if restaurant_modules() returns a non-empty array (admin logged in)
        // If empty array (admin not logged in), skip this check and rely on settings check below
        if (function_exists('restaurant_modules')) {
            $restaurantModules = restaurant_modules();
            // Only fail if array is not empty and doesn't contain 'Loyalty'
            // If array is empty (admin not logged in), skip this check
            if (!empty($restaurantModules) && !in_array('Loyalty', $restaurantModules)) {
                return false;
            }
        }

        // Check platform-specific setting for Points on Customer Site
        try {

            if (module_enabled('Loyalty')) {
                $restaurantId = $this->restaurant->id ?? null;
                if ($restaurantId) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    if (!$settings->enabled) {
                        return false;
                    }

                    // Check if new platform fields exist
                    $hasNewFields = !is_null($settings->enable_points_for_customer_site);

                    if ($hasNewFields) {
                        // New field exists - check if points are enabled AND enabled for customer site
                        // Cast to bool to handle database boolean values (1/0) correctly
                        return (bool)$settings->enable_points && (bool)$settings->enable_points_for_customer_site;
                    } else {
                        // Fallback to old field if new field doesn't exist yet (before migration)
                        return (bool)$settings->enable_points && (bool)($settings->enable_for_customer_site ?? true);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    /**
     * Check if stamps are specifically enabled for customer site
     * This is separate from points - used for stamps-specific UI elements
     */
    public function isStampsEnabledForCustomerSite()
    {
        // Check if module is enabled
        if (!module_enabled('Loyalty')) {
            return false;
        }

        // Check if module is in restaurant's package
        // Only check if restaurant_modules() returns a non-empty array (admin logged in)
        // If empty array (admin not logged in), skip this check and rely on settings check below
        if (function_exists('restaurant_modules')) {
            $restaurantModules = restaurant_modules();
            // Only fail if array is not empty and doesn't contain 'Loyalty'
            // If array is empty (admin not logged in), skip this check
            if (!empty($restaurantModules) && !in_array('Loyalty', $restaurantModules)) {
                return false;
            }
        }

        // Check platform-specific setting for Stamps on Customer Site
        try {
            if (module_enabled('Loyalty')) {
                $restaurantId = $this->restaurant->id ?? null;
                if ($restaurantId) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    if (!$settings->enabled) {
                        return false;
                    }

                    // Check loyalty type - stamps must be enabled
                    $loyaltyType = $settings->loyalty_type ?? 'points';
                    if (!in_array($loyaltyType, ['stamps', 'both'])) {
                        return false;
                    }

                    // Check if new platform fields exist
                    $hasNewFields = !is_null($settings->enable_stamps_for_customer_site);

                    if ($hasNewFields) {
                        // New field exists - check if stamps are enabled AND enabled for customer site
                        // Cast to bool to handle database boolean values (1/0) correctly
                        return (bool)$settings->enable_stamps && (bool)$settings->enable_stamps_for_customer_site;
                    } else {
                        // Fallback to old field if new field doesn't exist yet (before migration)
                        return (bool)$settings->enable_stamps && (bool)($settings->enable_for_customer_site ?? true);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    /**
     * Load loyalty data for order (stub method if trait doesn't exist)
     */
    public function loadLoyaltyDataForOrder($order, $restaurantId, $customerId, $subTotal)
    {
        if (module_enabled('Loyalty')) {
            $traits = class_uses_recursive(static::class);
            if (in_array(\Modules\Loyalty\Traits\HasLoyaltyIntegration::class, $traits)) {
                // Trait exists and is used, it will handle this
                return;
            }
        }

        // If module doesn't exist, do nothing
        if (!module_enabled('Loyalty')) {
            $this->availableLoyaltyPoints = 0;
            $this->maxRedeemablePoints = 0;
            $this->minRedeemPoints = 0;
            $this->loyaltyPointsValue = 0;
            $this->maxLoyaltyDiscount = 0;
            return;
        }

        // Load loyalty data directly (similar to POS component)
        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $this->availableLoyaltyPoints = $loyaltyService->getAvailablePoints($restaurantId, $customerId);

            // Load existing redemption from order if any
            if ($order) {
                $this->loyaltyPointsRedeemed = $order->loyalty_points_redeemed ?? 0;
                $this->loyaltyDiscountAmount = $order->loyalty_discount_amount ?? 0;
            }

            // Load loyalty settings and calculate max redeemable points
            if (module_enabled('Loyalty')) {
                $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);

                if ($settings && $settings->isEnabled()) {
                    $valuePerPoint = $settings->value_per_point ?? 1;
                    $this->minRedeemPoints = $settings->min_redeem_points ?? 0;

                    // Calculate loyalty points value (total value of all available points)
                    $this->loyaltyPointsValue = $this->availableLoyaltyPoints * $valuePerPoint;

                    // Calculate max discount TODAY (percentage of subtotal)
                    // This is the maximum discount allowed today based on max_discount_percent setting
                    $maxDiscountToday = 0;
                    if ($subTotal > 0) {
                        $maxDiscountToday = $subTotal * ($settings->max_discount_percent / 100);
                    }
                    $this->maxLoyaltyDiscount = $maxDiscountToday; // Store for display

                    // Calculate maximum points based on available points (multiple of min_redeem_points)
                    $maxPointsFromAvailable = 0;
                    if ($this->minRedeemPoints > 0 && $this->availableLoyaltyPoints >= $this->minRedeemPoints) {
                        $maxPointsFromAvailable = floor($this->availableLoyaltyPoints / $this->minRedeemPoints) * $this->minRedeemPoints;
                    }

                    // Calculate maximum points based on max discount TODAY (multiple of min_redeem_points)
                    // This ensures "Use Max" button respects the Maximum Discount (%) setting
                    $maxPointsFromDiscount = 0;
                    if ($maxDiscountToday > 0 && $this->minRedeemPoints > 0) {
                        $maxPointsFromDiscountValue = floor($maxDiscountToday / $valuePerPoint);
                        if ($maxPointsFromDiscountValue >= $this->minRedeemPoints) {
                            $maxPointsFromDiscount = floor($maxPointsFromDiscountValue / $this->minRedeemPoints) * $this->minRedeemPoints;
                        }
                    }

                    // Maximum redeemable points is the minimum of both constraints
                    if ($maxPointsFromDiscount > 0 && $maxPointsFromAvailable > 0) {
                        $this->maxRedeemablePoints = min($maxPointsFromAvailable, $maxPointsFromDiscount);
                    } elseif ($maxPointsFromAvailable > 0) {
                        $this->maxRedeemablePoints = $maxPointsFromAvailable;
                    } elseif ($maxPointsFromDiscount > 0) {
                        $this->maxRedeemablePoints = $maxPointsFromDiscount;
                    } else {
                        $this->maxRedeemablePoints = 0;
                    }
                } else {
                    // Settings not enabled or not found
                    $this->maxRedeemablePoints = 0;
                    $this->minRedeemPoints = 0;
                    $this->loyaltyPointsValue = 0;
                    $this->maxLoyaltyDiscount = 0;
                }
            } else {
                // Settings class doesn't exist
                $this->maxRedeemablePoints = 0;
                $this->minRedeemPoints = 0;
                $this->loyaltyPointsValue = 0;
                $this->maxLoyaltyDiscount = 0;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading loyalty data for order: ' . $e->getMessage(), [
                'restaurant_id' => $restaurantId,
                'customer_id' => $customerId,
                'subtotal' => $subTotal,
                'trace' => $e->getTraceAsString()
            ]);
            // Set defaults on error
            $this->availableLoyaltyPoints = 0;
            $this->maxRedeemablePoints = 0;
            $this->minRedeemPoints = 0;
            $this->loyaltyPointsValue = 0;
            $this->maxLoyaltyDiscount = 0;
        }
    }

    /**
     * Load tier information from Loyalty module
     * Uses LoyaltyService to get tier data
     */
    protected function loadTierInformationFromModule()
    {
        if (!module_enabled('Loyalty')) {
            return;
        }

        // Ensure restaurant and customer are available
        if (!property_exists($this, 'restaurant') || !$this->restaurant) {
            return;
        }

        if (!property_exists($this, 'customer') || !$this->customer) {
            return;
        }

        $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
        $tierInfo = $loyaltyService->getTierInformation($this->restaurant->id, $this->customer->id);

        // dd($tierInfo);


        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $tierInfo = $loyaltyService->getTierInformation($this->restaurant->id, $this->customer->id);

            $this->currentTier = $tierInfo['currentTier'];
            $this->nextTier = $tierInfo['nextTier'];
            $this->pointsToNextTier = $tierInfo['pointsToNextTier'];
            $this->tierProgress = $tierInfo['tierProgress'];

            // Fallback: if tier info is missing but account has a tier, load it directly.
            if (!$this->currentTier) {
                $account = $loyaltyService->getOrCreateAccount($this->restaurant->id, $this->customer->id);
                if ($account && $account->tier_id) {
                    $this->currentTier = \Modules\Loyalty\Entities\LoyaltyTier::find($account->tier_id);
                }
            }
        } catch (\Exception $e) {
            //
        }
    }


    /**
     * Open loyalty redemption modal and load loyalty values
     */
    public function openLoyaltyRedemptionModal()
    {
        // Check basic requirements
        if (!$this->customer) {
            $this->alert('error', __('Customer is required to redeem loyalty points.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if ($this->order->isFullyPaid()) {
            $this->alert('info', __('Cannot redeem points for a paid order.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Check if module is available (less strict check)
        if (!function_exists('module_enabled') || !module_enabled('Loyalty')) {
            $this->alert('error', __('Loyalty module is not available.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if (!module_enabled('Loyalty')) {
            $this->alert('error', __('Loyalty service is not available.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Prevent opening modal if points are already redeemed (no editing allowed)
        $existingRedeemed = $this->order->loyalty_points_redeemed ?? 0;
        if ($existingRedeemed > 0) {
            $this->alert('info', __('Points have already been redeemed for this order and cannot be edited.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        try {
            // Reload order with all relationships including taxes to ensure fresh data
            $this->order->refresh();
            $this->order->load(['taxes.tax', 'items', 'charges.charge']);

            // Set customerId for trait methods
            $this->customerId = $this->customer->id;

            // Set subTotal for trait methods (updateLoyaltyValues uses $this->subTotal)
            $this->subTotal = $this->order->sub_total ?? 0;

            // Reload loyalty data to ensure we have latest values
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);

            if ($this->availableLoyaltyPoints > 0) {
                // Auto-set points to redeem to maximum applicable points (customer cannot choose)
                $this->pointsToRedeem = $this->maxRedeemablePoints > 0 ? $this->maxRedeemablePoints : 0;

                // Calculate initial discount preview
                $this->calculateLoyaltyDiscountPreview();

                // Open modal
                $this->showLoyaltyRedemptionModal = true;
            } else {
                $this->alert('info', __('loyalty::app.noPointsAvailable'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error opening loyalty redemption modal: ' . $e->getMessage(), [
                'order_id' => $this->order->id ?? null,
                'customer_id' => $this->customer->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            $this->alert('error', __('An error occurred while opening the redemption modal. Please try again.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Close loyalty redemption modal and reset values if not redeemed
     */
    public function closeLoyaltyRedemptionModal()
    {
        // Check if points were actually redeemed in the database (not just preview)
        $orderRedeemed = $this->order->loyalty_points_redeemed ?? 0;

        // Only reset preview values if points were not actually redeemed (i.e., just previewing)
        if ($orderRedeemed == 0) {
            // Clear preview discount amount (this was just a preview, not actual redemption)
            $this->loyaltyDiscountAmount = 0;
            $this->pointsToRedeem = 0;
            // Also reset the component property to match database state
            $this->loyaltyPointsRedeemed = 0;
        } else {
            // Points were actually redeemed, reload from order to ensure consistency
            $this->loyaltyPointsRedeemed = $orderRedeemed;
            $this->loyaltyDiscountAmount = $this->order->loyalty_discount_amount ?? 0;
        }
        $this->showLoyaltyRedemptionModal = false;
        // Set date and time formats
        $this->dateFormat = $this->restaurant->date_format ?? dateFormat();
        $this->timeFormat = $this->restaurant->time_format ?? timeFormat();
    }

    /**
     * Calculate loyalty discount preview based on pointsToRedeem (for real-time calculation)
     * This is called automatically when pointsToRedeem changes via wire:model.live
     */
    public function updatedPointsToRedeem()
    {
        $this->calculateLoyaltyDiscountPreview();
    }

    /**
     * Set points to redeem to maximum and calculate discount
     */
    public function useMaxPoints()
    {
        try {
            // Ensure loyalty values are up to date
            if (!$this->isLoyaltyEnabled() || !$this->customer || $this->order->isFullyPaid()) {
                return;
            }

            // Set customerId and subTotal for trait methods
            $this->customerId = $this->customer->id;
            $this->subTotal = $this->order->sub_total ?? 0;

            // Reload loyalty data to ensure maxRedeemablePoints is current
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);

            // Use maxRedeemablePoints if available, otherwise use available points
            $pointsToSet = $this->maxRedeemablePoints > 0 ? $this->maxRedeemablePoints : $this->availableLoyaltyPoints;

            if ($pointsToSet > 0) {
                $this->pointsToRedeem = $pointsToSet;
                // Calculate discount preview
                $this->calculateLoyaltyDiscountPreview();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in useMaxPoints: ' . $e->getMessage());
            $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                ? __('loyalty::app.errorOccurredWhileRedeeming')
                : __('app.errorOccurred');
            $this->alert('error', $errorMsg, [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Redeem loyalty points for order (stub method if trait doesn't exist)
     */
    public function redeemLoyaltyPointsForOrder($order, $pointsToRedeem)
    {
        // First check if module is enabled (safest check)
        if (!module_enabled('Loyalty')) {
            $errorMsg = '__(loyalty::app.loyaltyModuleNotAvailable)';
            return ['success' => false, 'message' => $errorMsg];
        }

        // Check if trait class exists before using it (use string to avoid autoload issues)
        $traitClass = 'Modules\Loyalty\Traits\HasLoyaltyIntegration';

        if (class_exists($traitClass)) {
            $traits = class_uses_recursive(static::class);
            if (in_array($traitClass, $traits)) {
                // Trait exists and is used, try to call trait method if it exists
                if (method_exists($this, 'traitRedeemLoyaltyPointsForOrder')) {
                    return $this->traitRedeemLoyaltyPointsForOrder($order, $pointsToRedeem);
                }
            }
        }

        // If module service doesn't exist, return failure
        $serviceClass = 'Modules\Loyalty\Services\LoyaltyService';
        if (!class_exists($serviceClass)) {
            $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                ? __('loyalty::app.loyaltyModuleNotAvailable')
                : 'Loyalty module is not available';
            return ['success' => false, 'message' => $errorMsg];
        }

        // Implement redemption logic directly (similar to POS and Cart components)
        try {
            // Validate points
            if ($pointsToRedeem <= 0) {
                $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                    ? __('loyalty::app.invalidPointsAmount')
                    : __('loyalty::app.invalidPointsAmount');
                return ['success' => false, 'message' => $errorMsg];
            }

            // Check if customer has enough points
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $availablePoints = $loyaltyService->getAvailablePoints($this->restaurant->id, $order->customer_id);

            if ($pointsToRedeem > $availablePoints) {
                $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                    ? __('loyalty::app.insufficientLoyaltyPointsAvailable')
                    : __('loyalty::app.insufficientLoyaltyPointsAvailable');
                return ['success' => false, 'message' => $errorMsg];
            }

            // Check loyalty settings
            if (!module_enabled('Loyalty')) {
                $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                    ? __('loyalty::app.loyaltySettingsNotFound')
                    : __('loyalty::app.loyaltySettingsNotFound');
                return ['success' => false, 'message' => $errorMsg];
            }

            $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($this->restaurant->id);
            if (!$settings || !$settings->isEnabled()) {
                $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                    ? __('loyalty::app.loyaltyProgramNotEnabled')
                    : __('loyalty::app.loyaltyProgramNotEnabled');
                return ['success' => false, 'message' => $errorMsg];
            }

            // Check minimum redeem points
            if ($settings->min_redeem_points > 0 && $pointsToRedeem < $settings->min_redeem_points) {
                $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                    ? __('loyalty::app.minPointsRequired', ['min_points' => $settings->min_redeem_points])
                    : __('loyalty::app.minPointsRequired', ['min_points' => $settings->min_redeem_points]);
                return ['success' => false, 'message' => $errorMsg];
            }

            // Let LoyaltyService calculate discount, enforce max % cap, and update the order.
            // Do NOT pre-set loyalty_discount_amount here — the service subtracts any existing
            // discount from the cap, so pre-writing the amount makes remainingDiscountCap = 0.
            $result = $loyaltyService->redeemPoints($order, $pointsToRedeem);

            if ($result['success']) {
                $order->refresh();
                // Update component properties from order (service may redeem fewer points than requested)
                $this->loyaltyPointsRedeemed = $order->loyalty_points_redeemed ?? $pointsToRedeem;
                $this->loyaltyDiscountAmount = $order->loyalty_discount_amount ?? 0;

                $successMsg = function_exists('__') && module_enabled('Loyalty')
                    ? __('loyalty::app.loyaltyPointsRedeemedSuccessfully')
                    : __('loyalty::app.loyaltyPointsRedeemedSuccessfully');

                return [
                    'success' => true,
                    'message' => $successMsg,
                    'points' => $pointsToRedeem,
                    'discount' => $this->loyaltyDiscountAmount
                ];
            } else {
                $errorMsg = function_exists('__') && module_enabled('Loyalty')
                    ? ($result['message'] ?? __('loyalty::app.failedToRedeemPoints'))
                    : ($result['message'] ?? __('loyalty::app.failedToRedeemPoints'));

                return [
                    'success' => false,
                    'message' => $errorMsg
                ];
            }
        } catch (\Exception $e) {
            $errorMsg = function_exists('__') && function_exists('module_enabled') && module_enabled('Loyalty')
                ? __('loyalty::app.errorOccurredWhileRedeeming')
                : __('loyalty::app.errorOccurredWhileRedeeming');

            return [
                'success' => false,
                'message' => $errorMsg
            ];
        }
    }

    /**
     * Remove loyalty redemption from order (stub method if trait doesn't exist)
     */
    public function removeLoyaltyRedemptionFromOrder($order)
    {
        // Check basic requirements
        if (!$order->customer_id) {
            return false;
        }

        if ($order->isFullyPaid()) {
            return false;
        }

        // Check if points were actually redeemed
        $pointsRedeemed = $order->loyalty_points_redeemed ?? 0;
        if ($pointsRedeemed <= 0) {
            // No points redeemed, just clear the fields
            $order->update([
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_amount' => 0,
            ]);
            return true;
        }

        // If module doesn't exist, just clear the order fields
        if (!module_enabled('Loyalty')) {
            $order->update([
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_amount' => 0,
            ]);
            return true;
        }

        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);

            // Ensure order has branch relationship loaded for restaurant_id
            if (!$order->relationLoaded('branch')) {
                $order->load('branch');
            }

            // Remove redemption using service (this will refund points)
            $success = $loyaltyService->removeRedemption($order);

            if ($success) {
                // Reload order to get updated data
                $order->refresh();

                // Reset local properties
                $this->loyaltyPointsRedeemed = 0;
                $this->loyaltyDiscountAmount = 0;
            }

            return $success;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Calculate loyalty discount preview based on pointsToRedeem (for real-time calculation)
     * This method already exists in the file, so we don't need a stub
     */
    public function calculateLoyaltyDiscountPreview()
    {
        if (!$this->isLoyaltyEnabled() || !$this->customer || $this->order->isFullyPaid()) {
            return;
        }

        try {
            // Ensure order has fresh data with taxes loaded
            if (!$this->order->relationLoaded('taxes')) {
                $this->order->load('taxes.tax');
            }
            if (!$this->order->relationLoaded('items')) {
                $this->order->load('items');
            }

            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($this->restaurant->id);

            if ($settings && $settings->isEnabled() && $this->pointsToRedeem > 0) {
                // Calculate base discount based on points to redeem and value per point
                $basePointsDiscount = $this->pointsToRedeem * $settings->value_per_point;

                // Apply tier redemption multiplier if customer has a tier
                $tierMultiplier = 1.00;
                if (module_enabled('Loyalty')) {
                    try {
                        $account = $loyaltyService->getOrCreateAccount($this->restaurant->id, $this->order->customer_id);
                        if ($account && $account->tier_id) {
                            $tier = \Modules\Loyalty\Entities\LoyaltyTier::find($account->tier_id);
                            if ($tier && $tier->redemption_multiplier > 0) {
                                $tierMultiplier = $tier->redemption_multiplier;
                            }
                        }
                    } catch (\Exception $e) {
                        // If tier check fails, use default multiplier of 1.00
                        \Illuminate\Support\Facades\Log::warning('Error checking tier for points redemption preview: ' . $e->getMessage());
                    }
                }

                // Apply tier multiplier to discount
                $pointsDiscount = $basePointsDiscount * $tierMultiplier;

                // Calculate max discount TODAY (percentage of subtotal ONLY, not total)
                // Start with subtotal from items (before any discounts)
                $subtotal = $this->order->items->sum('amount') ?? $this->order->sub_total ?? 0;
                $maxDiscountToday = $subtotal > 0 ? ($subtotal * ($settings->max_discount_percent / 100)) : 0;

                // Use the smaller of points discount or max discount TODAY
                if ($maxDiscountToday > 0) {
                    $this->loyaltyDiscountAmount = min($pointsDiscount, $maxDiscountToday);
                } else {
                    $this->loyaltyDiscountAmount = $pointsDiscount;
                }
            } else {
                $this->loyaltyDiscountAmount = 0;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to calculate loyalty discount preview: ' . $e->getMessage());
            $this->loyaltyDiscountAmount = 0;
        }
    }

    /**
     * Redeem loyalty points for existing order (shop version)
     * Uses trait method for core logic
     */
    public function redeemLoyaltyPoints($points = null)
    {
        // If points parameter is provided (e.g., from Use Max button), use it and update pointsToRedeem
        if ($points !== null && $points > 0) {
            $this->pointsToRedeem = (int) $points;
        }

        // Use specified points, or pointsToRedeem from input, or available points
        $pointsToRedeem = $points ?? $this->pointsToRedeem ?? $this->availableLoyaltyPoints ?? 0;

        $result = $this->redeemLoyaltyPointsForOrder($this->order, $pointsToRedeem);

        if ($result['success']) {
            // Use unified calculation method to ensure correct order of operations:
            // 1. Calculate discounted base (subtotal - regular discount - loyalty discount)
            // 2. Calculate service charges on discounted base
            // 3. Calculate tax base (discounted base + service charges if enabled)
            // 4. Calculate taxes on tax base
            // 5. Build final total: discounted base + service charges + taxes
            $this->recalculateOrderTotalAfterStampRedemption();

            // Update component properties from refreshed order
            $this->loyaltyPointsRedeemed = $this->order->loyalty_points_redeemed ?? 0;
            $this->loyaltyDiscountAmount = $this->order->loyalty_discount_amount ?? 0;

            // Reload loyalty data (this will update available points)
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);

            // Close modal
            $this->showLoyaltyRedemptionModal = false;

            $this->alert('success', $result['message'], [
                'toast' => true,
                'position' => 'top-end',
            ]);

            $this->notifyOrderLoyaltyUpdated();
        } else {
            $this->alert('error', $result['message'], [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Load customer stamps for stamp redemption
     * Only show stamps for items that are in the current order
     */
    public function loadCustomerStamps()
    {
        if (!$this->customer || !$this->isStampsEnabledForCustomerSite) {
            $this->customerStamps = [];
            return;
        }

        try {
            // Ensure restaurant is available
            if (!$this->restaurant) {
                // Try to get restaurant from order branch or helper
                if ($this->order && $this->order->branch && $this->order->branch->restaurant) {
                    $this->restaurant = $this->order->branch->restaurant;
                } elseif (function_exists('restaurant') && restaurant()) {
                    $this->restaurant = restaurant();
                }
            }

            if (!$this->restaurant || !$this->restaurant->id) {
                $this->customerStamps = [];
                return;
            }

            if (module_enabled('Loyalty')) {
                $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                $allStamps = $loyaltyService->getCustomerStamps($this->restaurant->id, $this->customer->id);

                // Get menu item IDs from the current order
                $orderMenuItemIds = [];
                if ($this->order && $this->order->items) {
                    foreach ($this->order->items as $orderItem) {
                        if ($orderItem->menu_item_id) {
                            $orderMenuItemIds[] = $orderItem->menu_item_id;
                        }
                    }
                }

                // Filter stamps: only show if the stamp rule's menu_item_id matches an item in the order
                $this->customerStamps = collect($allStamps)->filter(function ($stampData) use ($orderMenuItemIds) {
                    $rule = $stampData['rule'] ?? null;
                    if (!$rule) {
                        return false;
                    }

                    // Check if this stamp rule's menu item is in the order
                    $ruleMenuItemId = $rule->menu_item_id ?? null;
                    return $ruleMenuItemId && in_array($ruleMenuItemId, $orderMenuItemIds);
                })->values()->toArray();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading customer stamps: ' . $e->getMessage());
            $this->customerStamps = [];
        }
    }

    /**
     * Open stamp redemption modal
     */
    public function openStampRedemptionModal()
    {
        if (!$this->isStampsEnabledForCustomerSite || !$this->customer) {
            $this->alert('error', __('loyalty::app.loyaltyProgramNotEnabled'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if ($this->order->isFullyPaid()) {
            $this->alert('info', __('Cannot redeem stamps for a paid order.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Load customer stamps if not loaded
        if (empty($this->customerStamps)) {
            $this->loadCustomerStamps();
        }

        // Filter stamps that can be redeemed
        $redeemableStamps = collect($this->customerStamps)->filter(function ($stampData) {
            return $stampData['can_redeem'] ?? false;
        });

        if ($redeemableStamps->isEmpty()) {
            $this->alert('info', __('loyalty::app.noStampsAvailable'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        // Open modal
        $this->showStampRedemptionModal = true;
    }

    /**
     * Close stamp redemption modal
     */
    public function closeStampRedemptionModal()
    {
        $this->showStampRedemptionModal = false;
        $this->selectedStampRuleIds = [];
    }

    /**
     * Toggle stamp rule selection
     */
    public function toggleStampRuleSelection($stampRuleId)
    {
        if (!is_array($this->selectedStampRuleIds)) {
            $this->selectedStampRuleIds = [];
        }

        $index = array_search($stampRuleId, $this->selectedStampRuleIds);
        if ($index !== false) {
            // Remove if already selected
            unset($this->selectedStampRuleIds[$index]);
            $this->selectedStampRuleIds = array_values($this->selectedStampRuleIds); // Re-index array
        } else {
            // Add if not selected
            $this->selectedStampRuleIds[] = $stampRuleId;
        }
    }

    /**
     * Redeem stamps for order (supports multiple stamp rules)
     */
    public function redeemStamps()
    {
        if (!$this->isStampsEnabledForCustomerSite || !$this->customer) {
            $this->alert('error', __('loyalty::app.loyaltyProgramNotEnabled'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if (empty($this->selectedStampRuleIds) || !is_array($this->selectedStampRuleIds)) {
            $this->alert('error', __('loyalty::app.pleaseSelectStampRule'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if ($this->order->isFullyPaid()) {
            $this->alert('info', __('Cannot redeem stamps for a paid order.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        try {
            if (!module_enabled('Loyalty')) {
                $this->alert('error', __('loyalty::app.loyaltyModuleNotAvailable'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }

            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $successCount = 0;
            $errorMessages = [];

            // Redeem each selected stamp rule
            foreach ($this->selectedStampRuleIds as $stampRuleId) {
                $result = $loyaltyService->redeemStamps($this->order, $stampRuleId);

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorMessages[] = $result['message'] ?? __('loyalty::app.failedToRedeemStamps', ['error' => 'Unknown error']);
                }
            }

            // Reload order to get updated items/discounts
            $this->order->refresh();
            $this->order->load(['items', 'taxes.tax', 'charges.charge', 'kot.items']);

            // CRITICAL: Loyalty module creates free order items but may not set branch_id (customer site has no branch() context).
            // Backfill branch_id from the order so free items have branch_id for reporting and scopes.
            if ($this->order->branch_id) {
                OrderItem::withoutGlobalScopes()
                    ->where('order_id', $this->order->id)
                    ->where('is_free_item_from_stamp', true)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $this->order->branch_id]);
            }

            // CRITICAL: If order is in KOT status, also create kot_items entries for stamp items
            // This ensures consistency - customer site shows order_items, admin shows kot_items
            if ($this->order->status === 'kot' && $this->order->kot && $this->order->kot->count() > 0) {
                $this->syncStampItemsToKotItems();
            }

            // Update stamp discount amount
            $this->stampDiscountAmount = $this->order->stamp_discount_amount ?? 0;

            // Recalculate order total
            $this->recalculateOrderTotalAfterStampRedemption();

            // Reload customer stamps
            $this->loadCustomerStamps();

            // Force Livewire to re-render the view to update correctedTotal
            $this->dispatch('$refresh');

            // Close modal
            $this->showStampRedemptionModal = false;
            $this->selectedStampRuleIds = [];

            // Show success/error messages
            if ($successCount > 0) {
                if ($successCount === count($this->selectedStampRuleIds)) {
                    // All successful
                    $this->alert('success', __('loyalty::app.stampsRedeemedSuccessfully'), [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                } else {
                    // Partial success
                    $this->alert('success', __('loyalty::app.partialStampRedemption', [
                        'success' => $successCount,
                    ]) ?: "Successfully redeemed {$successCount} out of " . count($this->selectedStampRuleIds) . " stamp rules", [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                }

                $this->notifyOrderLoyaltyUpdated();
            }

            if (!empty($errorMessages)) {
                $this->alert('error', implode('; ', array_unique($errorMessages)), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error redeeming stamps: ' . $e->getMessage());
            $this->alert('error', __('An error occurred while redeeming stamps. Please try again.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Recalculate order total after stamp redemption
     * Similar to points redemption: subtract discount BEFORE calculating taxes
     */
    protected function recalculateOrderTotalAfterStampRedemption()
    {
        // Reload order with all relationships
        $this->order->refresh();
        $this->order->load(['taxes.tax', 'items', 'charges.charge', 'branch.restaurant']);

        // Ensure restaurant is loaded
        if (!$this->restaurant) {
            if ($this->order->branch && $this->order->branch->restaurant) {
                $this->restaurant = $this->order->branch->restaurant;
            } elseif (function_exists('restaurant') && restaurant()) {
                $this->restaurant = restaurant();
            }
        }

        // Start fresh from item amounts to ensure correct calculation
        // Ensure float precision for all calculations
        // NOTE: Stamp discount is already applied to item amounts, so don't subtract it again
        $correctSubTotal = (float)($this->order->items->sum('amount') ?? 0);
        $correctTotal = (float)$correctSubTotal;

        // Apply regular discount (ensure float)
        $correctTotal -= (float)($this->order->discount_amount ?? 0);

        // Apply loyalty discount BEFORE tax calculation (always remove from subtotal)
        // This ensures loyalty points are always removed from subtotal before calculating tax
        $correctTotal -= (float)($this->order->loyalty_discount_amount ?? 0);

        // NOTE: Stamp discount is NOT subtracted here because it's already applied to item amounts
        // The item amounts already reflect the stamp discount

        // Step 1: Calculate service charges on discounted subtotal (after all discounts)
        $chargeBase = (float)$correctSubTotal - (float)($this->order->discount_amount ?? 0) - (float)($this->order->loyalty_discount_amount ?? 0);
        $chargeBase = max(0, (float)$chargeBase);
        $charges = collect($this->order->charges ?? [])
            ->map(fn ($chargeRelation) => $chargeRelation->charge)
            ->filter();
        $chargeTotals = RestaurantCharge::calculateTotals($charges, $chargeBase);
        $serviceTotal = $chargeTotals['total'];

        // Step 2: Calculate tax_base based on Tax Calculation Base setting
        $includeChargesInTaxBase = (bool) ($this->restaurant->include_charges_in_tax_base ?? false);
        $taxBase = RestaurantCharge::resolveTaxBase($chargeBase, $charges, $includeChargesInTaxBase);
        $taxBase = max(0, (float)$taxBase);

        // Step 3: Calculate taxes on tax_base (AFTER all discounts and considering service charges)
        $correctTaxAmount = 0.0;
        if ($this->order->tax_mode === 'order' && $this->order->taxes && $this->order->taxes->count() > 0) {
            // Order-level taxes - calculate on tax_base
            // IMPORTANT: Don't round individual tax amounts, only round the final sum
            // Ensure we process ALL taxes (no deduplication)
            foreach ($this->order->taxes as $orderTax) {
                $tax = $orderTax->tax ?? null;
                if ($tax && isset($tax->tax_percent)) {
                    $taxPercent = (float)$tax->tax_percent;
                    // Calculate tax amount on tax_base (AFTER all discounts and considering service charges)
                    $taxAmount = ($taxPercent / 100.0) * (float)$taxBase;
                    // Add to running total with full precision
                    $correctTaxAmount += $taxAmount;
                }
            }

            // Round ONLY the final sum to 2 decimal places
            $correctTaxAmount = round($correctTaxAmount, 2);

            // Add order-level taxes to total (always exclusive)
            $correctTotal += (float)$correctTaxAmount;
        } else {
            // Item-level taxes - sum from order items (already calculated with precision)
            $correctTaxAmount = (float)($this->order->items->sum('tax_amount') ?? 0);
            // Check if taxes are inclusive or exclusive based on Tax Calculation Base setting
            $isInclusive = false;
            if ($this->restaurant && isset($this->restaurant->tax_inclusive)) {
                $isInclusive = (bool)$this->restaurant->tax_inclusive;
            }
            if (!$isInclusive && $correctTaxAmount > 0) {
                // For exclusive taxes, add to total
                $correctTotal += (float)$correctTaxAmount;
            }
            // For inclusive taxes, tax is already included in item prices (amount field)
            // So we don't add it to total, but we still track it for total_tax_amount
        }

        // Step 4: Add service charges to total
        $correctTotal += $serviceTotal;

        // Add tip and delivery (ensure float)
        $correctTotal += (float)($this->order->tip_amount ?? 0);
        $correctTotal += (float)($this->order->delivery_fee ?? 0);

        // Round final values to 2 decimal places
        $correctSubTotal = round($correctSubTotal, 2);
        $correctTotal = round($correctTotal, 2);
        $correctTaxAmount = round($correctTaxAmount, 2);

        // FORCE UPDATE total, subtotal, and tax_amount - this is critical!
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $this->order->id)->update([
            'sub_total' => $correctSubTotal,
            'total' => $correctTotal,
            'total_tax_amount' => $correctTaxAmount,
        ]);

        // Refresh order and reload all relationships to ensure view gets updated values
        $this->order->refresh();
        $this->order->load(['taxes.tax', 'items', 'charges.charge']);

        // Update component total
        $this->total = floatval($this->order->total) - floatval($this->order->amount_paid ?: 0);

        // Force Livewire to re-render the view to update correctedTotal
        $this->dispatch('$refresh');
    }

    /**
     * Remove stamp redemption from order
     */
    public function removeStampRedemption()
    {
        if ($this->order->isFullyPaid()) {
            $this->alert('info', __('Cannot remove stamp redemption for a paid order.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        try {
            if (!module_enabled('Loyalty')) {
                $this->alert('error', __('loyalty::app.loyaltyModuleNotAvailable'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }

            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);

            // Ensure order has branch loaded with restaurant
            if (!$this->order->relationLoaded('branch')) {
                $this->order->load('branch');
            }

            // Load restaurant relationship on branch if needed
            if ($this->order->branch && !$this->order->branch->relationLoaded('restaurant')) {
                $this->order->branch->load('restaurant');
            }

            // Get restaurant ID - try from branch, then from restaurant property, then from helper
            $restaurantId = null;
            if ($this->order->branch && $this->order->branch->restaurant_id) {
                $restaurantId = $this->order->branch->restaurant_id;
            } elseif ($this->restaurant && $this->restaurant->id) {
                $restaurantId = $this->restaurant->id;
            } elseif (function_exists('restaurant') && restaurant()) {
                $restaurantId = restaurant()->id;
            }

            if (!$restaurantId) {
                throw new \Exception('Unable to determine restaurant ID');
            }

            // Ensure restaurant property is set
            if (!$this->restaurant) {
                if ($this->order->branch && $this->order->branch->restaurant) {
                    $this->restaurant = $this->order->branch->restaurant;
                } elseif (function_exists('restaurant') && restaurant()) {
                    $this->restaurant = restaurant();
                }
            }

            if (!$this->restaurant) {
                throw new \Exception('Restaurant not found');
            }
            $hasFreeItems = $this->order->items()->where('is_free_item_from_stamp', true)->exists();
            $hasStampDiscount = ($this->order->stamp_discount_amount ?? 0) > 0;
            $stampTransactions = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('restaurant_id', $restaurantId)
                ->where('customer_id', $this->order->customer_id)
                ->where('order_id', $this->order->id)
                ->where('type', 'REDEEM')
                ->get();

            // If nothing to remove, return early (prevents duplicate processing)
            if (!$hasFreeItems && !$hasStampDiscount && $stampTransactions->isEmpty()) {
                return;
            }

            // Remove free items from stamp redemption
            $this->order->items()->where('is_free_item_from_stamp', true)->delete();

            // Refresh order items after deletion to get updated collection
            $this->order->refresh();
            $this->order->load('items');

            // Restore discounted amounts to order_items and kot_items
            // Find all order_items that have stamp discounts applied (have stamp_rule_id set)
            $discountedOrderItems = $this->order->items()
                ->whereNotNull('stamp_rule_id')
                ->get();

            foreach ($discountedOrderItems as $orderItem) {
                // Find linked kot_items first to get discount_amount
                $kotItems = \App\Models\KotItem::where('order_item_id', $orderItem->id)->get();

                // If no linked kot_items, try to find them directly from order's KOTs
                if ($kotItems->isEmpty() && $this->order->kot && $this->order->kot->count() > 0) {
                    if (!$this->order->relationLoaded('kot')) {
                        $this->order->load('kot.items');
                    }

                    foreach ($this->order->kot as $kot) {
                        if (!$kot->relationLoaded('items')) {
                            $kot->load('items');
                        }

                        $matchingKotItems = $kot->items->filter(function($kotItem) use ($orderItem) {
                            return $kotItem->menu_item_id == $orderItem->menu_item_id
                                && $kotItem->menu_item_variation_id == $orderItem->menu_item_variation_id
                                && ($kotItem->status ?? null) !== 'cancelled';
                        });

                        if ($matchingKotItems->isNotEmpty()) {
                            $kotItems = $kotItems->merge($matchingKotItems);
                        }
                    }
                }

                // Calculate discount to restore for order_item
                // Priority: Use discount_amount from linked kot_item, otherwise calculate from price
                $discountToRestore = 0;

                if ($kotItems->isNotEmpty()) {
                    // Sum up discount_amount from all linked kot_items
                    $discountToRestore = (float)$kotItems->sum('discount_amount');
                } else {
                    // Fallback: Calculate original amount from price and modifiers
                    // Original amount = (price + modifier_prices) * quantity
                    $basePrice = (float)($orderItem->price ?? 0);
                    $modifierPrice = 0;

                    // Load modifier options if not loaded
                    if (!$orderItem->relationLoaded('modifierOptions')) {
                        $orderItem->load('modifierOptions');
                    }

                    if ($orderItem->modifierOptions && $orderItem->modifierOptions->count() > 0) {
                        $modifierPrice = $orderItem->modifierOptions->sum(function($mod) {
                            return $mod->pivot->modifier_option_price ?? $mod->price ?? 0;
                        });
                    }

                    $originalAmount = ($basePrice + $modifierPrice) * ($orderItem->quantity ?? 1);
                    $currentAmount = (float)($orderItem->amount ?? 0);

                    // Discount is the difference between original and current amount
                    if ($originalAmount > $currentAmount) {
                        $discountToRestore = $originalAmount - $currentAmount;
                    }
                }

                // Update order_item: restore amount if discount exists, clear stamp fields
                $updateData = [
                    'stamp_rule_id' => null,
                ];

                // If there's a discount amount to restore, add it back to the item amount
                if ($discountToRestore > 0) {
                    $newAmount = (float)($orderItem->amount ?? 0) + $discountToRestore;
                    $updateData['amount'] = round($newAmount, 2);
                }

                // Always update to clear stamp_rule_id, even if no discount to restore
                $orderItem->update($updateData);

                // Restore discounted amounts in kot_items using the same logic as order_items
                foreach ($kotItems as $kotItem) {
                    // Calculate discount to restore for kot_item (same process as order_item)
                    $kotDiscountToRestore = (float)($kotItem->discount_amount ?? 0);

                    // If kot_item doesn't have discount_amount, calculate from price (same as order_item fallback)
                    if ($kotDiscountToRestore == 0) {
                        // Calculate original amount from price and modifiers
                        $kotBasePrice = (float)($kotItem->price ?? $orderItem->price ?? 0);
                        $kotModifierPrice = 0;

                        // Load modifier options if not loaded
                        if (!$kotItem->relationLoaded('modifierOptions')) {
                            $kotItem->load('modifierOptions');
                        }

                        if ($kotItem->modifierOptions && $kotItem->modifierOptions->count() > 0) {
                            $kotModifierPrice = $kotItem->modifierOptions->sum(function($mod) {
                                return $mod->pivot->modifier_option_price ?? $mod->price ?? 0;
                            });
                        }

                        $kotOriginalAmount = ($kotBasePrice + $kotModifierPrice) * ($kotItem->quantity ?? $orderItem->quantity ?? 1);
                        $kotCurrentAmount = (float)($kotItem->amount ?? 0);

                        // Discount is the difference between original and current amount
                        if ($kotOriginalAmount > $kotCurrentAmount) {
                            $kotDiscountToRestore = $kotOriginalAmount - $kotCurrentAmount;
                        }
                    }

                    // Update kot_item: restore amount if discount exists, clear stamp fields
                    $kotUpdateData = [
                        'discount_amount' => 0,
                        'is_discounted' => false,
                        'stamp_rule_id' => null,
                    ];

                    // If there's a discount amount to restore, add it back to the kot_item amount
                    if ($kotDiscountToRestore > 0) {
                        $kotNewAmount = (float)($kotItem->amount ?? 0) + $kotDiscountToRestore;
                        $kotUpdateData['amount'] = round($kotNewAmount, 2);
                    }

                    // Always update to clear stamp-related fields, even if no discount to restore
                    $kotItem->update($kotUpdateData);
                }
            }

            // Remove stamp discount
            $this->order->update([
                'stamp_discount_amount' => 0,
            ]);

            // Process each stamp transaction to restore customer stamp counts
            // Group transactions by stamp_rule_id to sum up stamps per rule
            $stampsByRule = [];
            foreach ($stampTransactions as $transaction) {
                $stampRuleId = $transaction->stamp_rule_id;
                if (!isset($stampsByRule[$stampRuleId])) {
                    $stampsByRule[$stampRuleId] = 0;
                }
                // Transaction stores negative value, so we need to get absolute value
                $stampsByRule[$stampRuleId] += abs($transaction->stamps);
            }

            // Update customer stamp counts for each rule
            foreach ($stampsByRule as $stampRuleId => $totalStampsRedeemed) {
                // Use firstOrCreate to avoid depending on custom static helpers
                $customerStamp = \Modules\Loyalty\Entities\CustomerStamp::firstOrCreate(
                    [
                        'restaurant_id' => $restaurantId,
                        'customer_id'   => $this->order->customer_id,
                        'stamp_rule_id' => $stampRuleId,
                    ],
                    [
                        'stamps_earned'   => 0,
                        'stamps_redeemed' => 0,
                    ]
                );

                // Decrement stamps_redeemed to restore available stamps (undo the redemption)
                // We do NOT increment stamps_earned because those stamps were already earned
                // We're just undoing the redemption, not earning new stamps
                $customerStamp->decrement('stamps_redeemed', $totalStampsRedeemed);

                // Update last_redeemed_at timestamp (set to null since redemption is removed)
                $customerStamp->update(['last_redeemed_at' => null]);
            }

            // Delete all REDEEM transactions for this order (instead of creating EARN entries)
            // This is cleaner and prevents duplicate transaction entries
            \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('restaurant_id', $restaurantId)
                ->where('customer_id', $this->order->customer_id)
                ->where('order_id', $this->order->id)
                ->where('type', 'REDEEM')
                ->delete();

            // Reload order
            $this->order->refresh();
            $this->order->load(['items', 'taxes.tax', 'charges.charge']);

            // Clear stamp discount amount
            $this->stampDiscountAmount = 0;
            $this->selectedStampRuleIds = [];

            // Recalculate order total without stamp discount
            // Start fresh from item amounts (after free items are removed)
            $correctSubTotal = (float)($this->order->items->sum('amount') ?? 0);
            $discountedSubTotal = (float)$correctSubTotal;

            // Apply regular discount
            $discountedSubTotal -= (float)($this->order->discount_amount ?? 0);

            // Apply loyalty discount BEFORE tax calculation
            $discountedSubTotal -= (float)($this->order->loyalty_discount_amount ?? 0);

            // Step 1: Calculate service charges on discounted subtotal (after all discounts)
            $chargeBase = max(0, (float)$discountedSubTotal);
            $charges = collect($this->order->charges ?? [])
                ->map(fn ($chargeRelation) => $chargeRelation->charge)
                ->filter();
            $chargeTotals = RestaurantCharge::calculateTotals($charges, $chargeBase);
            $serviceTotal = $chargeTotals['total'];

            // Step 2: Calculate tax_base based on setting
            $includeChargesInTaxBase = (bool) ($this->restaurant->include_charges_in_tax_base ?? true);
            $taxBase = RestaurantCharge::resolveTaxBase($discountedSubTotal, $charges, $includeChargesInTaxBase);
            $taxBase = max(0, (float)$taxBase);

            // Step 3: Calculate taxes on tax_base (AFTER all discounts and considering service charges)
            $correctTaxAmount = 0.0;
            if ($this->order->tax_mode === 'order' && $this->order->taxes && $this->order->taxes->count() > 0) {
                // Order-level taxes - calculate on tax_base
                foreach ($this->order->taxes as $orderTax) {
                    $tax = $orderTax->tax ?? null;
                    if ($tax && isset($tax->tax_percent)) {
                        $taxPercent = (float)$tax->tax_percent;
                        $taxAmount = ($taxPercent / 100.0) * (float)$taxBase;
                        $correctTaxAmount += $taxAmount;
                    }
                }
                $correctTaxAmount = round($correctTaxAmount, 2);
            } else {
                // Item-level taxes - sum from order items (recalculate if needed)
                $correctTaxAmount = (float)($this->order->items->sum('tax_amount') ?? 0);
            }

            // Step 4: Start total calculation from discounted subtotal
            $correctTotal = max(0, (float)$discountedSubTotal);

            // Step 5: Add service charges to total
            $correctTotal += $serviceTotal;

            // Step 6: Add taxes to total
            if ($this->order->tax_mode === 'order') {
                // Order-level taxes are always exclusive, so add them
                $correctTotal += (float)$correctTaxAmount;
            } else {
                // Item-level taxes
                $isInclusive = ($this->restaurant && isset($this->restaurant->tax_inclusive))
                    ? $this->restaurant->tax_inclusive
                    : false;
                if (!$isInclusive && $correctTaxAmount > 0) {
                    $correctTotal += (float)$correctTaxAmount;
                }
            }

            // Add tip and delivery
            $correctTotal += (float)($this->order->tip_amount ?? 0);
            $correctTotal += (float)($this->order->delivery_fee ?? 0);

            // Round final values
            $correctSubTotal = round($correctSubTotal, 2);
            $correctTotal = round($correctTotal, 2);
            $correctTaxAmount = round($correctTaxAmount, 2);

            // Update order with all calculated values
            \Illuminate\Support\Facades\DB::table('orders')->where('id', $this->order->id)->update([
                'sub_total' => $correctSubTotal,
                'total' => $correctTotal,
                'total_tax_amount' => $correctTaxAmount,
            ]);

            // Refresh order
            $this->order->refresh();
            $this->order->load(['taxes.tax', 'items', 'charges.charge']);

            // Update component properties
            $this->stampDiscountAmount = 0;
            $this->selectedStampRuleIds = [];
            $this->total = floatval($this->order->total) - floatval($this->order->amount_paid ?: 0);

            // Reload customer stamps
            $this->loadCustomerStamps();

            // Force Livewire to re-render the view to update correctedTotal
            $this->dispatch('$refresh');

            $this->alert('success', __('loyalty::app.stampRedemptionRemoved'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            $this->notifyOrderLoyaltyUpdated();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error removing stamp redemption: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'order_id' => $this->order->id ?? null,
            ]);
            $this->alert('error', __('An error occurred while removing stamp redemption. Please try again.'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Sync stamp items from order_items to kot_items for KOT orders
     * This ensures consistency - when stamps are applied from customer site,
     * items are created in order_items, but we also need them in kot_items for admin view
     */
    private function syncStampItemsToKotItems()
    {
        if (!$this->order || $this->order->status !== 'kot' || !$this->order->kot || $this->order->kot->isEmpty()) {
            return;
        }

        try {
            // Reload order items to get latest stamp items
            $this->order->refresh();
            $this->order->load('items.modifierOptions');

            // Get the first KOT (or we could create a new one, but let's use existing)
            $kot = $this->order->kot->first();
            if (!$kot) {
                return;
            }

            // Find order_items that have stamp data but no corresponding kot_items
            $stampOrderItems = $this->order->items()->where(function($q) {
                $q->where('is_free_item_from_stamp', true)
                  ->orWhereNotNull('stamp_rule_id');
            })->get();

            foreach ($stampOrderItems as $orderItem) {
                // Step 1: Check if this order_item already has a linked kot_item
                $existingKotItem = \App\Models\KotItem::where('order_item_id', $orderItem->id)->first();

                // Step 2: If not linked, check if there's an existing kot_item in the same KOT with matching menu item
                // This handles the case where item already exists in kot_items but stamp was applied later
                if (!$existingKotItem) {
                    // Load kot items if not already loaded
                    if (!$kot->relationLoaded('items')) {
                        $kot->load('items');
                    }

                    // Find existing kot_item by matching menu_item_id, variation_id, and quantity
                    // Exclude cancelled items and items that are already linked to different order_items
                    $existingKotItem = $kot->items->first(function($ki) use ($orderItem) {
                        // Match by menu item and variation
                        $menuMatch = $ki->menu_item_id == $orderItem->menu_item_id
                            && $ki->menu_item_variation_id == $orderItem->menu_item_variation_id;

                        // Match quantity (or allow updating existing)
                        $quantityMatch = $ki->quantity == $orderItem->quantity;

                        // Not cancelled
                        $notCancelled = ($ki->status ?? null) !== 'cancelled';

                        // Not already linked to a different order_item (or not linked at all)
                        $notLinked = !$ki->order_item_id || $ki->order_item_id == $orderItem->id;

                        return $menuMatch && $quantityMatch && $notCancelled && $notLinked;
                    });
                }

                // Calculate discount amount for display
                $originalAmount = (float)($orderItem->amount ?? 0);
                $discountAmount = 0;

                // If item has stamp discount (not free), try to calculate discount amount
                if (!($orderItem->is_free_item_from_stamp ?? false) && !is_null($orderItem->stamp_rule_id)) {
                    // Estimate original amount: price * quantity + modifiers
                    $basePrice = (float)($orderItem->price ?? 0);
                    $modifierPrice = 0;
                    if ($orderItem->modifierOptions && $orderItem->modifierOptions->count() > 0) {
                        $modifierPrice = $orderItem->modifierOptions->sum(function($mod) {
                            return $mod->pivot->modifier_option_price ?? $mod->price ?? 0;
                        });
                    }
                    $estimatedOriginalAmount = ($basePrice + $modifierPrice) * ($orderItem->quantity ?? 1);

                    // If current amount is less than estimated, there's a discount
                    if ($estimatedOriginalAmount > $originalAmount) {
                        $discountAmount = $estimatedOriginalAmount - $originalAmount;
                    }
                }

                if ($existingKotItem) {
                    // UPDATE existing kot_item instead of creating duplicate
                    $existingKotItem->update([
                        'price' => $orderItem->price ?? 0,
                        'amount' => $originalAmount, // Use discounted amount from order_item
                        'is_free_item_from_stamp' => $orderItem->is_free_item_from_stamp ?? false,
                        'stamp_rule_id' => $orderItem->stamp_rule_id,
                        'discount_amount' => round($discountAmount, 2), // Stamp discount amount for display
                        'is_discounted' => $discountAmount > 0 || (!is_null($orderItem->stamp_rule_id) && !($orderItem->is_free_item_from_stamp ?? false)),
                        'order_item_id' => $orderItem->id, // Link to order_item
                        'note' => $orderItem->note,
                    ]);

                    // Sync modifier options if any
                    if ($orderItem->modifierOptions && $orderItem->modifierOptions->count() > 0) {
                        $modifierOptionIds = $orderItem->modifierOptions->pluck('id')->toArray();
                        $existingKotItem->modifierOptions()->sync($modifierOptionIds);
                    }
                } else {
                    // CREATE new kot_item only if no existing one found
                    $kotItemData = [
                        'kot_id' => $kot->id,
                        'menu_item_id' => $orderItem->menu_item_id,
                        'menu_item_variation_id' => $orderItem->menu_item_variation_id,
                        'quantity' => $orderItem->quantity,
                        'price' => $orderItem->price ?? 0,
                        'amount' => $originalAmount, // Use discounted amount from order_item
                        'is_free_item_from_stamp' => $orderItem->is_free_item_from_stamp ?? false,
                        'stamp_rule_id' => $orderItem->stamp_rule_id,
                        'discount_amount' => round($discountAmount, 2), // Stamp discount amount for display
                        'is_discounted' => $discountAmount > 0 || (!is_null($orderItem->stamp_rule_id) && !($orderItem->is_free_item_from_stamp ?? false)),
                        'order_item_id' => $orderItem->id, // Link back to order_item
                        'note' => $orderItem->note,
                        'order_type_id' => $this->order->order_type_id ?? null,
                        'order_type' => $this->order->order_type ?? null,
                    ];

                    $kotItem = \App\Models\KotItem::create($kotItemData);

                    // Sync modifier options if any
                    if ($orderItem->modifierOptions && $orderItem->modifierOptions->count() > 0) {
                        $modifierOptionIds = $orderItem->modifierOptions->pluck('id')->toArray();
                        $kotItem->modifierOptions()->sync($modifierOptionIds);
                    }
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error syncing stamp items to kot_items: ' . $e->getMessage(), [
                'exception' => $e,
                'order_id' => $this->order->id ?? null,
            ]);
            // Don't throw - allow stamp redemption to complete even if sync fails
        }
    }

    /**
     * Remove loyalty redemption from existing order (shop version)
     * Public wrapper method for Livewire - delegates to trait method if trait is used
     * Otherwise implements the logic directly using the module's service
     */
    public function removeLoyaltyRedemption()
    {
        $success = $this->removeLoyaltyRedemptionFromOrder($this->order);

        if ($success) {
            // Ensure order is refreshed to get updated loyalty_discount_amount (should be 0)
            $this->order->refresh();
            $this->order->load(['taxes.tax', 'items', 'charges.charge']);

            // Use unified calculation method to ensure correct order of operations:
            // 1. Calculate discounted base (subtotal - regular discount - loyalty discount)
            // 2. Calculate service charges on discounted base
            // 3. Calculate tax base (discounted base + service charges if enabled)
            // 4. Calculate taxes on tax base
            // 5. Build final total: discounted base + service charges + taxes
            $this->recalculateOrderTotalAfterStampRedemption();

            // Update component properties from refreshed order
            $this->loyaltyPointsRedeemed = $this->order->loyalty_points_redeemed ?? 0;
            $this->loyaltyDiscountAmount = $this->order->loyalty_discount_amount ?? 0;

            // Reload loyalty data (this will update available points)
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);

            $this->alert('success', __('loyalty::app.redemptionRemoved'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            $this->notifyOrderLoyaltyUpdated();
        } else {
            $this->alert('error', __('loyalty::app.redemptionRemoveFailed'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
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


    public function InitializePayment()
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        $this->total = floatval($this->paymentOrder->total) - floatval($this->paymentOrder->amount_paid ?: 0);

        $options = $this->getActivePaymentOptions();

        if (count($options) === 1) {
            $result = $this->autoStartSinglePaymentOption($options[0]);
            if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
                return $result;
            }
            return;
        }

        $this->showPaymentModal = true;
    }

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

    private function autoStartSinglePaymentOption(string $option): mixed
    {
        if ($option === 'qr') {
            $this->showPaymentModal = true;
            $this->showQrCode = true;
            $this->showPaymentDetail = false;
            $this->selectedOfflinePaymentMethod = null;
            return true;
        }

        if (str_starts_with($option, 'offline:')) {
            $methodName = str_replace('offline:', '', $option);
            $this->showPaymentModal = true;
            $this->showQrCode = false;
            $this->showPaymentDetail = true;
            $this->selectedOfflinePaymentMethod = $methodName;
            return true;
        }

        $this->showPaymentModal = false;

        $result = match ($option) {
            'stripe' => $this->initiateStripePayment($this->paymentOrder->id),
            'razorpay' => $this->initiatePayment($this->paymentOrder->id),
            'flutterwave' => $this->initiateFlutterwavePayment($this->paymentOrder->id),
            'paypal' => $this->initiatePaypalPayment($this->paymentOrder->id),
            'payfast' => $this->initiatePayfastPayment($this->paymentOrder->id),
            'paystack' => $this->initiatePaystackPayment($this->paymentOrder->id),
            'xendit' => $this->initiateXenditPayment($this->paymentOrder->id),
            'epay' => $this->initiateEpayPayment($this->paymentOrder->id),
            'mollie' => $this->initiateMolliePayment($this->paymentOrder->id),
            'tap' => $this->initiateTapPayment($this->paymentOrder->id),
            'tlync' => $this->initiateTlyncPayment($this->paymentOrder->id),
            default => null,
        };

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        return true;
    }

    public function hidePaymentModal()
    {
        $this->showPaymentModal = false;
        $this->showQrCode = false;
        $this->showPaymentDetail = false;
        $this->selectedOfflinePaymentMethod = null;
    }

    public function toggleQrCode()
    {
        $this->showQrCode = !$this->showQrCode;
    }

    public function togglePaymentDetail()
    {
        $this->showPaymentDetail = !$this->showPaymentDetail;
    }

    /**
     * Select an offline payment method inside the payment modal.
     * The actual payment/order placement happens when the user clicks
     * the modal footer "paymentDone" button.
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

    public function initiatePayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        $payment = RazorpayPayment::create([
            'order_id' => $id,
            'amount' => $this->total
        ]);

        $orderData = [
            'amount' => (int) round($this->total * 100),
            'currency' => $this->restaurant->currency->currency_code
        ];

        $paymentGateway = $this->restaurant->paymentGateways;
        $apiKey = $paymentGateway->razorpay_key;
        $secretKey = $paymentGateway->razorpay_secret;

        $api  = new Api($apiKey, $secretKey);
        $razorpayOrder = $api->order->create($orderData);
        $payment->razorpay_order_id = $razorpayOrder->id;
        $payment->save();

        $this->dispatch('paymentInitiated', payment: $payment);
    }

    public function initiateStripePayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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
            $wasPaid = $order->status === 'paid';
            $order->amount_paid = floatval($order->amount_paid) + $this->total;
            $order->status = 'paid';
            $order->save();

            // Fire event when order becomes paid (for loyalty points)
            if ($order->status === 'paid' && !$wasPaid) {
                \App\Events\SendNewOrderReceived::dispatch($order);
            }

            Payment::updateOrCreate(
                [
                    'order_id' => $payment->order_id,
                    'payment_method' => 'due',
                    'amount' => $payment->amount
                ],
                [
                    'transaction_id' => $razorpayPaymentID,
                    'payment_method' => 'razorpay',
                    'branch_id' => $this->shopBranch->id
                ]
            );

            if ($order->placed_via === 'shop' && ShopCartKotPrintUrls::shouldPrintKotAfterShopOnlinePayment($order, $this->restaurant)) {
                $this->printKot($order->fresh([
                    'kot.items.menuItem',
                    'kot.items.menuItemVariation',
                    'kot.items.modifierOptions',
                ]));
            }

            $this->sendNotifications($order);

            $this->alert('success', __('messages.paymentDoneSuccessfully'), [
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
        }
    }
    public function initiatePaypalPayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        $amount = $this->total;
        $currency = strtoupper($this->restaurant->currency->currency_code);

        $order = Order::find($id);
        $unsupportedCurrencies = ['INR'];
        if (in_array($currency, $unsupportedCurrencies)) {
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
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "amount" => [
                    "currency_code" => $currency,
                    "value" => number_format($amount, 2, '.', '')
                ],
                "reference_id" => (string)$paypalPayment->id
            ]],
            "application_context" => [
                "return_url" => $returnUrl,
                "cancel_url" => $cancelUrl
            ]
        ];
        info("Paypal Data: " . json_encode($paypalData));

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
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        try {
            $paymentGateway = $this->restaurant->paymentGateways;

            $secretKey = $paymentGateway->paystack_secret_data;
            $user = auth()->user();
            $amount = $this->total;
            $reference = "psk_" . time();
            $data = [
                "reference" => $reference,
                "amount" => (int)($amount * 100), // Paystack expects amount in kobo
                "email" => $user->email ?? 'guest@example.com',
                "currency" =>  $this->restaurant->currency->currency_code,
                "callback_url" => route('paystack.success'),
                "metadata" => [
                    "cancel_action" => route('paystack.failed', ['reference' => $reference])
                ]

            ];

            $response = Http::withHeaders([
                "Authorization" => "Bearer $secretKey",
                "Content-Type" => "application/json"
            ])->post("https://api.paystack.co/transaction/initialize", $data);

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
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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
                'currency' => $this->restaurant->currency->currency_code,
                'success_redirect_url' => route('xendit.success', ['external' => $externalId]),
                'failure_redirect_url' => route('xendit.failed',  ['external' => $externalId]),
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



    public function makePayment($id, $method)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        if (!$id || !$method) {
            return;
        }

        $allowedMethods = ['cash', 'card', 'upi', 'due', 'others', 'bank_transfer'];

        // Allow dynamic offline payment method names
        if (!empty($this->offlinePaymentMethods) && is_iterable($this->offlinePaymentMethods)) {
            $offlineMethodNames = collect($this->offlinePaymentMethods)->pluck('name')->toArray();
            $allowedMethods = array_merge($allowedMethods, $offlineMethodNames);
        }

        if (!in_array($method, $allowedMethods)) {
            $this->alert('error', __('messages.invalidPaymentMethod'), [
                'toast' => false,
                'position' => 'center'
            ]);
            return;
        }

        $order = Order::findOrFail($id);
        $order->update([
            'status' => 'pending_verification',
        ]);


        Payment::updateOrCreate(
            [
                'order_id' => $order->id,
                'payment_method' => 'due',
                'amount' => $this->total
            ],
            [
                'payment_method' => $method,
                'branch_id' => $this->shopBranch->id
            ]
        );

        $this->sendNotifications($order);

        $this->alert('success', __('messages.paymentDoneSuccessfully'), [
            'toast' => false,
            'position' => 'center',
            'showCancelButton' => true,
            'cancelButtonText' => __('app.close')
        ]);

        $this->redirect(route('order_success', $order->uuid));
    }


    public function sendNotifications($order)
    {
        if ($order->customer_id) {
            try {
                SendOrderBillEvent::dispatch($order);
            } catch (\Exception $e) {
                \Log::error('Error sending order bill email: ' . $e->getMessage());
            }
        }
    }

    public function addTipModal()
    {
        if ($this->order->isFullyPaid()) {
            $this->alert('error', __('messages.notHavePermission'), ['toast' => true]);
            return;
        }

        $this->tipAmount = $this->order->tip_amount ?? 0;
        $this->tipNote = $this->order->tip_note ?? '';
        $this->showTipModal = true;
    }

    public function addTip()
    {
        if (!$this->canAddTip) {
            $this->alert('error', __('messages.notHavePermission'), ['toast' => true]);
            return;
        }

        if (!$this->tipAmount || $this->tipAmount <= 0) {
            $this->tipAmount = 0;
        }

        $order = Order::find($this->id);

        $previousTip = floatval($order->tip_amount ?? 0);
        $newTip = floatval($this->tipAmount ?? 0);

        $order->total = floatval($order->total) - $previousTip + $newTip;
        $order->tip_amount = $newTip;
        $order->tip_note = $newTip > 0 ? $this->tipNote : null;
        $order->save();

        $this->order = $order;
        $this->showTipModal = false;

        $message = $newTip > 0 ? __('messages.tipAddedSuccessfully') : __('messages.tipRemovedSuccessfully');
        $this->alert('success', $message, ['toast' => true]);
    }

    public function initiateFlutterwavePayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

        try {
            $paymentGateway = $this->restaurant->paymentGateways;
            $apiSecret = $paymentGateway->flutterwave_secret;
            $amount = $this->total;
            $tx_ref = "txn_" . time();

            $user = $this->customer ?? $this->restaurant;


            $data = [
                "tx_ref" => $tx_ref,
                "amount" => $amount,
                "currency" => $this->restaurant->currency->currency_code,
                "redirect_url" => route('flutterwave.success'),
                "payment_options" => "card",
                "customer" => [
                    "email" => $user->email ?? 'no-email@example.com',
                    "name" => $user->name ?? 'Guest',
                    "phone_number" => $user->phone ?? '0000000000',
                ],
            ];
            $response = Http::withHeaders([
                "Authorization" => "Bearer $apiSecret",
                "Content-Type" => "application/json"
            ])->post("https://api.flutterwave.com/v3/payments", $data);

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

    public function initiateEpayPayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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

    public function initiateMolliePayment($id)
    {
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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
        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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

        if (!$this->ensureRestaurantOpenForPayments()) {
            return;
        }

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
            $phone = $this->normalizeTlyncPhone($customer?->phone);

            if (!$phone) {
                session()->flash('flash.banner', __('messages.tlyncPhoneRequired'));
                session()->flash('flash.bannerStyle', 'warning');
                return redirect()->route('order_success', $order->uuid);
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


    /**
     * Calculate the correct order total
     * Use the database total directly to match admin panel display
     * The database total already includes all charges, taxes, discounts, tip, and delivery fees
     */
    public function getCorrectedTotalProperty()
    {
        if (!$this->order) {
            return 0;
        }

        // Use the database total directly - it's already calculated correctly and matches admin panel
        // The database total includes:
        // - Subtotal
        // - All discounts (regular + loyalty + stamp)
        // - All service charges (already applied to the order)
        // - All taxes (calculated correctly)
        // - Tip and delivery fees
        return max(0, round((float)($this->order->total ?? 0), 2));
    }

    private function ensureRestaurantOpenForPayments(): bool
    {
        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch);

        if (!($availability['is_open'] ?? true)) {
            $this->alert('error', RestaurantAvailabilityService::getMessage($availability, $this->restaurant), [
                'toast' => false,
                'position' => 'center',
            ]);

            return false;
        }

        return true;
    }

    public function render()
    {
        if ($this->embedLoyaltyOnly) {
            return view('livewire.shop.order-detail-loyalty-embed');
        }

        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->shopBranch);

        return view('livewire.shop.order-detail', [
            'isRestaurantOpenForOrders' => (bool) ($availability['is_open'] ?? true),
            'restaurantClosedMessage' => RestaurantAvailabilityService::getMessage($availability, $this->restaurant),
        ]);
    }

    protected function notifyOrderLoyaltyUpdated(): void
    {
        $this->dispatch('orderLoyaltyUpdated');
    }

    // Pusher Broadcast
    #[On('refreshOrderSuccess')]
    public function refreshOrderSuccess()
    {
        $this->order->refresh();
        $this->order->load(['taxes.tax', 'items', 'charges.charge']);

        if ($this->customer && $this->isPointsEnabledForCustomerSite) {
            $this->loadLoyaltyDataForOrder($this->order, $this->restaurant->id, $this->customer->id, $this->order->sub_total);
            $this->loadTierInformationFromModule();
        }

        if ($this->customer && $this->isStampsEnabledForCustomerSite) {
            $this->loadCustomerStamps();
        }

        $this->dispatch('$refresh');
    }
}
