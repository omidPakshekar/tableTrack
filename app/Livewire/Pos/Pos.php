<?php

namespace App\Livewire\Pos;

use App\Models\Kot;
use App\Models\Tax;
use App\Models\Menu;
use App\Models\User;
use App\Models\Order;
use App\Models\Table;
use App\Models\KotItem;
use App\Models\Printer;
use Livewire\Component;
use App\Models\Customer;
use App\Models\KotPlace;
use App\Models\MenuItem;
use App\Models\OrderTax;
use App\Models\OrderItem;
use App\Models\OrderType;
use App\Models\Restaurant;
use App\Models\OrderCharge;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use App\Models\ItemCategory;
use App\Models\ModifierOption;
use App\Traits\PrinterSetting;
use Illuminate\Support\Carbon;
use App\Events\NewOrderCreated;
use App\Events\OrderTableAssigned;
use App\Events\OrderWaiterAssigned;
use App\Models\KotCancelReason;
use Illuminate\Validation\Rule;
use App\Models\DeliveryPlatform;
use App\Models\RestaurantCharge;
use App\Models\DeliveryExecutive;
use App\Models\MenuItemVariation;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use App\Services\OrderWaiterResponseService;
use App\Services\Pos\PosOrderTypeClientData;
use App\Services\Pos\PosWaitersCache;
use App\Services\RestaurantAvailabilityService;
use App\Services\Pos\IngredientStockGuard;
use App\Support\DietaryLabels;
use App\Support\EuAnnexIiAllergens;

class Pos extends Component
{
    use LivewireAlert, PrinterSetting;

    protected $listeners = ['refreshPos' => '$refresh', 'customerSelected' => 'setCustomer', 'setOrderTypeChoice', 'refreshPosOrder' => 'refreshOrderData'];

    protected $loyaltyHandler = null;

    public function isLoyaltyEnabled()
    {
        if (!module_enabled('Loyalty')) {
            return false;
        }

        if (!class_exists(\Modules\Loyalty\Services\PosLoyaltyHandler::class)) {
            return false;
        }

        $restaurantId = restaurant()->id ?? null;

        return \Modules\Loyalty\Services\PosLoyaltyHandler::isLoyaltyProgramEnabled($restaurantId);
    }

    protected function loyaltyHandler()
    {
        if (!$this->isLoyaltyEnabled()) {
            return null;
        }

        if (!$this->loyaltyHandler) {
            $this->loyaltyHandler = new \Modules\Loyalty\Services\PosLoyaltyHandler($this);
        }

        return $this->loyaltyHandler;
    }

    public function redeemLoyaltyPoints($points = null)
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->redeemLoyaltyPoints($points);
        }
    }

    public function skipLoyaltyRedemption()
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->skipLoyaltyRedemption();
        }
    }

    public function openLoyaltyRedemptionModal()
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->openLoyaltyRedemptionModal();
        }
    }

    public function checkLoyaltyPointsOnCustomerSelect()
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->checkLoyaltyPointsOnCustomerSelect();
        }
    }

    public function editLoyaltyRedemption()
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->editLoyaltyRedemption();
        }
    }

    public function updateLoyaltyValues()
    {
        $handler = $this->loyaltyHandler();
        if ($handler) {
            return $handler->updateLoyaltyValues();
        }
    }

    public function __call($method, $parameters)
    {
        $handler = $this->loyaltyHandler();
        if ($handler && method_exists($handler, $method)) {
            return $handler->$method(...$parameters);
        }

        if (is_callable([parent::class, '__call'])) {
            return parent::__call($method, $parameters);
        }

        throw new \BadMethodCallException('Method ' . static::class . '::' . $method . ' does not exist.');
    }

    public $search;
    public $filterCategories;
    public $menuItem;
    public $subTotal;
    public $total;
    public $orderNumber;
    public $kotNumber;
    public $tableNo;
    public $tableId;
    public $users;
    public $noOfPax = 1;
    public $selectWaiter;
    public $taxes;
    public $orderNote;
    public $tableOrder;
    public $tableOrderID;
    public $orderType;
    public $orderTypeSlug;
    public $kotList = [];
    public $showVariationModal = false;
    public $showKotNote = false;
    public $showTableModal = false;
    public $showTableChangeConfirmationModal = false;
    public $showMergeTableModal = false;
    public $pendingTable = null;
    public $tablesWithUnpaidOrders = [];
    public $selectedTablesForMerge = [];
    public $ordersToDeleteAfterMerge = [];
    public $mergedOrderItemIds = []; // Track OrderItem IDs from merged orders to transfer
    public $mergedCartKeys = []; // Track cart keys that correspond to merged OrderItems (to avoid duplicate creation)
    public $showErrorModal = true;
    public $showRestaurantClosedBanner = false;
    public $restaurantClosedMessage = '';
    public $showNewKotButton = false;
    public $orderDetail = null;
    public $showReservationModal = false;
    public $reservationId = null;
    public $reservationCustomer = null;
    public $reservation = null;
    public $isSameCustomer = false;
    public $intendedOrderAction = null;
    public $orderItemList = [];
    public $orderItemVariation = [];
    public $orderItemQty = [];
    public $orderItemAmount = [];
    public $deliveryExecutives;
    public $selectDeliveryExecutive;
    public $orderID;
    public $discountType;
    public $discountValue;
    public $discountAmount;
    public $restaurantSetting;
    public $showDiscountModal = false;
    public $selectedModifierItem;
    public $modifiers;
    public $showModifiersModal = false;
    public $itemModifiersSelected = [];
    public $orderItemModifiersPrice = [];
    public $extraCharges;
    public $discountedTotal;
    public $tipAmount = 0;
    public $orderStatus;
    public $deliveryFee = 0;
    public $itemNotes = [];
    public $stampFreeItemKeys = [];
    public $stampRuleIdByKey = [];
    public $orderPlaces;
    public $cancelReasons;
    public $confirmDeleteModal = false;
    public $deleteOrderModal = false;
    public $cancelReason;
    public $cancelReasonText;
    public $orderTypeId;
    public $selectedDeliveryApp = null;
    public $allowOrderTypeSelection = false; // Flag to allow popup when user clicks "Change"
    public $deliveryDateTime;
    public $customerDisplayStatus = 'idle';
    public $totalTaxAmount = 0;
    public $orderItemTaxDetails = [];
    public $taxMode;
    public $taxBase = 0;
    public $pickupRange;
    public $now;
    public $minDate;
    public $maxDate;
    public $defaultDate;
    public $formattedOrderNumber;
    public $customerId;
    public $customer;
    public $menuList;
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
    // Stamp redemption properties
    public $customerStamps = [];
    public $selectedStampRuleId = null;
    public $showStampRedemptionModal = false;

    public $stampDiscountAmount = 0;
    public $menuId;
    public $pickupDate;
    public $pickupTime;
    public $isPastTime = false;
    protected $saveTotalsSnapshot = null;

    public $menuItemsPerPage = 75;
    public $menuItemsLoaded = 75;

    // Room Service properties (only used when Hotel module is enabled)
    public $selectedStayId = null;
    public $hotelRoomId = null;
    public $billTo = 'POST_TO_ROOM'; // POST_TO_ROOM or PAY_NOW
    public $showRoomModal = false;
    public $roomSearch = '';

    // MultiPOS properties
    public $hasPosMachine = false;
    public $machineStatus = null;
    public $posMachine = null;
    public $limitReached = false;
    public $limitMessage = '';
    public $shouldBlockPos = false;
    public $restaurant;
    public $showPrintOptionsModal = false;
    public $printMode = null;
    public $selectedSplitId = null;

      /**
     * Check if room service order type is active
     */
    protected function isRoomServiceOrder(): bool
    {
        if (! module_enabled('Hotel') || ! in_array('Hotel', restaurant_modules())) {
            return false;
        }

        if ($this->orderType === 'room_service' || $this->orderTypeSlug === 'room_service') {
            return true;
        }

        if ($this->orderTypeId) {
            $orderType = OrderType::select('type', 'slug')->find($this->orderTypeId);

            return $orderType
                && (($orderType->type === 'room_service') || ($orderType->slug === 'room_service'));
        }

        return false;
    }

    protected function hasRoomServiceRoomContext(): bool
    {
        return (bool) ($this->selectedStayId || $this->hotelRoomId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRoomServiceOrderContext(?Order $order = null): array
    {
        if (! $this->isRoomServiceOrder()) {
            return [];
        }

        $context = [];
        $stayId = $this->selectedStayId ?: $order?->context_id;
        $roomId = $this->hotelRoomId ?: $order?->hotel_room_id;

        if ($stayId) {
            $context['context_type'] = 'HOTEL_ROOM';
            $context['context_id'] = $stayId;
            $context['bill_to'] = $this->billTo ?? $order?->bill_to ?? 'POST_TO_ROOM';
        }

        if ($roomId) {
            $context['hotel_room_id'] = $roomId;
        }

        return $context;
    }

    public function setCustomer($customerId = null)
    {
        $this->customerId = $customerId;
        $this->customer = Customer::find($customerId);

        if ($this->customerId && module_enabled('Loyalty')) {
            $handler = $this->loyaltyHandler();

            if ($handler) {
                $handler->handleCustomerSelected();
            }
        }
    }

    public function mount()
    {
        $this->restaurant = Restaurant::with(['paymentGateways', 'package'])->findOrFail(restaurant()->id) ?? restaurant()->load(['paymentGateways', 'package']);

        $this->total = 0;
        $this->subTotal = 0;
        $this->pickupRange = $this->restaurant->pickup_days_range ?? 1;

        // Set minimum date to next minute to avoid past times
        $this->minDate = now()->addMinute()->displayDate(global_setting()->date_format ?? 'd-m-Y');
        $this->maxDate = now()->addDays($this->pickupRange - 1)->endOfDay()->displayDate(global_setting()->date_format ?? 'd-m-Y');
        $this->defaultDate = old('deliveryDateTime', $this->deliveryDateTime ?? $this->minDate);

        // Initialize pickup date and time
        if ($this->deliveryDateTime) {
            $this->initializePickupDateTime();
        } else {
            $this->pickupDate = now($this->restaurant->timezone)->displayDate(global_setting()->date_format ?? 'd-m-Y');
            $this->pickupTime = now($this->restaurant->timezone)->format('H:i');
        }

        $this->users = PosWaitersCache::remember((int) $this->restaurant->id, (int) branch()->id);
        $this->users = PosWaitersCache::forPosActor($this->users, user(), (int) $this->restaurant->id);

        $this->taxMode = $this->restaurant->tax_mode;

        $this->taxes = cache()->remember('taxes_branch_' . branch()->id, 60 * 60 * 24, function () {
            return Tax::all();
        });

        $this->selectWaiter = user()->id;

        $this->deliveryExecutives = cache()->remember('delivery_executives_' . $this->restaurant->id, 60 * 60 * 24, function () {
            return DeliveryExecutive::assignable()->get();
        });

        if ($this->tableOrderID) {
            $this->tableId = $this->tableOrderID;
            $this->tableOrder = Table::with('activeOrder')->find($this->tableOrderID);

            if (!$this->tableOrder) {
                $this->alert('error', __('messages.tableNotFound'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return $this->redirect(route('pos.index'), navigate: true);
            }

            $this->tableNo = $this->tableOrder->table_code;
            $this->orderID = $this->tableOrder->activeOrder ? $this->tableOrder->activeOrder->id : null;

            if ($this->tableOrder->activeOrder) {

                $this->orderNumber = $this->tableOrder->activeOrder->order_number;
                $this->formattedOrderNumber = $this->tableOrder->activeOrder->formatted_order_number;
                $this->tipAmount = $this->tableOrder->activeOrder->tip_amount;
                $this->deliveryFee = $this->tableOrder->activeOrder->delivery_fee;
                $this->showTableOrder();

                if ($this->orderDetail) {
                    $this->showOrderDetail();
                }
            } elseif ($this->orderDetail) {
                return $this->redirect(route('pos.index'), navigate: true);
            } else {
                $this->setTable($this->tableOrder);
            }
        }

        if ($this->orderID) {

            $order = Order::with(['table', 'extraCharges', 'customer', 'taxes.tax'])->find($this->orderID);

            if (!$order || $order->status === 'canceled') {
                return $this->redirect(route('pos.index'), navigate: true);
            }

            // For draft orders, automatically set orderDetail to true to load items
            if ($order->status === 'draft') {
                $this->orderDetail = true;

                // Generate order number for draft orders if it doesn't exist
                if (!$order->order_number) {
                    $orderNumberData = Order::generateOrderNumber(branch());
                    $order->update([
                        'order_number' => $orderNumberData['order_number'],
                        'formatted_order_number' => $orderNumberData['formatted_order_number']
                    ]);
                    // Refresh the order to get updated values
                    $order->refresh();
                }
            }

            $this->orderNumber = $order->order_number;
            $this->formattedOrderNumber = $order->formatted_order_number;
            $this->noOfPax = $order->number_of_pax;
            $this->selectWaiter = $order->waiter_id ?? null;
            $this->tableNo = $order->table->table_code ?? null;
            $this->tableId = $order->table->id ?? null;
            $this->discountAmount = $order->discount_amount;
            $this->discountValue = $order->discount_type === 'percent' ? rtrim(rtrim($order->discount_value, '0'), '.') : $order->discount_value;
            $this->discountType = $order->discount_type;
            $this->tipAmount = $order->tip_amount;
            $this->deliveryFee = $order->delivery_fee;
            $this->orderStatus = $order->order_status;
            $this->orderTypeId = $order->order_type_id;
            $this->orderType = $order->order_type;
            $this->deliveryDateTime = $order->pickup_date;
            $this->initializePickupDateTime();
            $this->taxMode = $order->tax_mode ?? $this->taxMode;
            $this->selectedDeliveryApp = $order->delivery_app_id;

            // Set orderTypeSlug before setupOrderItems to ensure charges are calculated correctly
            if ($order->order_type_id) {
                $orderType = OrderType::select('slug')->find($order->order_type_id);
                $this->orderTypeSlug = $orderType ? $orderType->slug : $order->order_type;
            } else {
                $this->orderTypeSlug = $order->order_type;
            }

            // Load room service context if applicable
            if ($this->isRoomServiceOrder()) {
                if ($order->context_id) {
                    $this->selectedStayId = $order->context_id;
                    $this->billTo = $order->bill_to ?? 'POST_TO_ROOM';
                }

                if ($order->hotel_room_id) {
                    $this->hotelRoomId = $order->hotel_room_id;
                }
            }

            // Set extraCharges BEFORE setupOrderItems and updatedOrderTypeId to prevent double calculation
            if ($this->orderID) {
                if ($order->status === 'kot' && !$this->orderDetail) {
                    $this->extraCharges = [];
                } else {
                    // Deduplicate charges by charge_id to prevent showing duplicates
                    // Safety check: ensure extraCharges is a collection before calling unique()
                    $this->extraCharges = $order->extraCharges && $order->extraCharges->isNotEmpty()
                        ? $order->extraCharges->unique('id')->values()
                        : collect([]);
                }
            }

            $this->selectDeliveryExecutive = $order->delivery_executive_id;

            // Load customer and loyalty data from existing order (consolidated block)
            if ($order->customer_id) {
                $this->customerId = $order->customer_id;
                $this->customer = $order->customer;

                // Load loyalty data if module is enabled and orderDetail is set
                if ($this->orderDetail && $this->isLoyaltyEnabled()) {
                    // Store existing loyalty redemption values before loading
                    $existingPointsRedeemed = $order->loyalty_points_redeemed ?? 0;
                    $existingDiscountAmount = $order->loyalty_discount_amount ?? 0;

                    // Load full loyalty data (available points, etc.)
                    $this->loadLoyaltyDataForOrder($order, restaurant()->id, $order->customer_id, $order->sub_total ?? 0);

                    // Load loyalty data if module is enabled
                    if (module_enabled('Loyalty')) {
                        // Store existing loyalty redemption values before loading
                        $existingPointsRedeemed = $order->loyalty_points_redeemed ?? 0;
                        $existingDiscountAmount = $order->loyalty_discount_amount ?? 0;

                        // Load full loyalty data (available points, etc.)
                        $this->loadLoyaltyDataForOrder($order, restaurant()->id, $order->customer_id, $order->sub_total ?? 0);

                        // Restore loyalty redemption values from order if they exist (don't let loadLoyaltyDataForOrder reset them)
                        if ($existingPointsRedeemed > 0) {
                            $this->loyaltyPointsRedeemed = $existingPointsRedeemed;
                            $this->loyaltyDiscountAmount = $existingDiscountAmount;
                        }
                    }
                }
            }

            if ($this->orderDetail) {
                $this->orderDetail = $order;
                $this->setupOrderItems();
            }
        }

        $this->updatedOrderTypeId($this->orderTypeId);

        if ($this->orderID) {
            $this->extraCharges = ($order->status === 'kot' && !$this->orderDetail)
                ? $order->extraCharges->where('charge_type', 'percent')->values()
                : $order->extraCharges;
        }


        $this->cancelReasons = Cache::remember(
            'cancel_reasons_' . branch()->id,
            now()->addHours(2),
            function () {
                return KotCancelReason::where('cancel_order', true)->get();
            }
        );

        // Eager load menu with categories to avoid N+1 queries
        $this->menuList = Menu::withoutGlobalScopes()
            ->where('branch_id', branch()->id)
            ->orderBy('sort_order')
            ->get();

        // Auto-set default order type if popup is disabled and no order is loaded
        if (!$this->orderID && !$this->tableOrderID) {
            $disablePopup = $this->restaurant->disable_order_type_popup ?? false;
            if ($disablePopup && $this->restaurant->default_order_type_id) {
                $defaultOrderType = OrderType::find($this->restaurant->default_order_type_id);
                if ($defaultOrderType && $defaultOrderType->is_active) {
                    $this->orderTypeId = $defaultOrderType->id;
                    $this->orderType = $defaultOrderType->type;
                    $this->orderTypeSlug = $defaultOrderType->slug;
                    $this->updatedOrderTypeId($this->orderTypeId);
                }
            }
        }

        $this->selectWaiter = PosWaitersCache::normalizeWaiterSelection(
            $this->selectWaiter,
            user(),
            (int) $this->restaurant->id,
            $this->users
        );
    }

    public function setOrderTypeChoice($value)
    {
        try {
            // Reset the flag when order type is selected
            $this->allowOrderTypeSelection = false;

            // Handle if $value is an array containing orderType and orderTypeId
            if (is_array($value) && isset($value['orderTypeId'])) {
                $this->orderTypeId = $value['orderTypeId'];

                // Store delivery platform if provided
                $this->selectedDeliveryApp = $value['deliveryPlatform'] ?? null;

                // Get the order type object
                $orderType = OrderType::find($this->orderTypeId);

                if ($orderType) {
                    $this->orderType = $orderType->type;
                    $this->orderTypeSlug = $orderType->slug;

                    // If this is a delivery order, handle delivery-specific settings
                    if ($this->orderTypeSlug === 'delivery') {
                        // You can set default delivery fee here if needed
                        // $this->deliveryFee = $this->getDefaultDeliveryFee();
                    } else {
                        $this->deliveryFee = 0;
                    }

                    // Get relevant extra charges for this order type
                    $this->extraCharges = RestaurantCharge::whereJsonContains('order_types', $this->orderTypeSlug)
                        ->where('is_enabled', true)
                        ->get();

                    // Update prices for existing cart items when delivery platform changes
                    $this->updateCartItemsPricing();

                    // Calculate total with new order type settings
                    $this->calculateTotal();

                    // Display success notification for better UX
                    $platformName = $this->selectedDeliveryApp && $this->selectedDeliveryApp !== 'default'
                        ? DeliveryPlatform::find($this->selectedDeliveryApp)?->name ?? ''
                        : '';

                    $message = $platformName
                        ? __('modules.order.orderTypeSetTo', ['type' => $orderType->order_type_name]) . ' - ' . $platformName
                        : __('modules.order.orderTypeSetTo', ['type' => $orderType->order_type_name]);

                    $this->alert('success', $message, [
                        'toast' => true,
                        'position' => 'top-end',
                        'timer' => 2000,
                        'showCancelButton' => false,
                    ]);
                }
            } else {
                // Legacy handling for direct ID passing
                $this->orderTypeId = $value;

                $this->selectedDeliveryApp = null;

                $orderType = OrderType::find($this->orderTypeId);

                if ($orderType) {
                    $this->orderType = $orderType->type;
                    $this->orderTypeSlug = $orderType->slug;

                    // Update prices for existing cart items
                    $this->updateCartItemsPricing();
                }
            }
        } catch (\Exception $e) {

            $this->alert('error', __('messages.errorSettingOrderType', ['message' => $e->getMessage()]), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    /**
     * Normalize delivery app ID to ensure it's either an integer or null
     * Converts 'default' string to null
     */
    private function tableIdForOrderSave(?Order $existingOrder = null): ?int
    {
        if ($this->orderType !== 'dine_in') {
            return null;
        }

        return $this->tableId ?? $existingOrder?->table_id;
    }

    private function normalizeDeliveryAppId()
    {
        if ($this->selectedDeliveryApp === 'default' || $this->selectedDeliveryApp === null) {
            return null;
        }
        return is_numeric($this->selectedDeliveryApp) ? (int)$this->selectedDeliveryApp : null;
    }

    /**
     * Get the normalized delivery app ID for use in views
     */
    public function getNormalizedDeliveryAppIdProperty()
    {
        return $this->normalizeDeliveryAppId();
    }

    /**
     * Update pricing for all items in cart when order type or delivery platform changes
     */
    public function updateCartItemsPricing()
    {
        if (!$this->orderTypeId) {
            return;
        }

        $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();

        // Update prices for all items in cart when order type or delivery platform changes
        foreach ($this->orderItemList as $key => $item) {
            // Set price context on menu item and variation
            $item->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            if (isset($this->orderItemVariation[$key])) {
                $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }

            // Update modifier prices
            if (!empty($this->itemModifiersSelected[$key])) {
                $modifierOptions = $this->getModifierOptionsProperty();
                $modifierTotal = 0;
                foreach ($this->itemModifiersSelected[$key] as $modifierId) {
                    if (isset($modifierOptions[$modifierId])) {
                        $modifierOptions[$modifierId]->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                        $modifierTotal += $modifierOptions[$modifierId]->price;
                    }
                }
                $this->orderItemModifiersPrice[$key] = $modifierTotal;
            }

            // Recalculate item amount with updated prices
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $item->price;
            $this->orderItemAmount[$key] = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
        }
    }

    public function updatedOrderTypeId($value)
    {
        // Get the order type information efficiently
        $orderType = OrderType::select('slug', 'type')->find($value);

        // Update the local variables to keep them in sync
        $this->orderTypeSlug = $orderType ? $orderType->slug : $this->orderType;
        $this->orderType = $orderType ? $orderType->type : $this->orderType;

        $mainExtraCharges = RestaurantCharge::whereJsonContains('order_types', $this->orderTypeSlug)
            ->where('is_enabled', true)
            ->get();

        // Handle new orders or table orders without active orders
        if ((!$this->orderID && !$this->tableOrderID) || ($this->tableOrderID && !$this->tableOrder->activeOrder)) {
            $this->extraCharges = $mainExtraCharges;
            $this->orderStatus = 'confirmed';

            // Set default delivery fee for delivery orders
            if ($this->orderTypeSlug === 'delivery') {
                $this->deliveryFee = $this->getDefaultDeliveryFee();
            } else {
                $this->deliveryFee = 0;
            }

            // Recalculate prices for all items in cart when order type changes
            $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
            foreach ($this->orderItemList as $key => $item) {
                if ($this->orderTypeId) {
                    $item->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                    if (isset($this->orderItemVariation[$key])) {
                        $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                    }
                }

                // Recalculate modifier prices
                if (!empty($this->itemModifiersSelected[$key])) {
                    $modifierOptions = $this->getModifierOptionsProperty();
                    $modifierTotal = collect($this->itemModifiersSelected[$key])
                        ->sum(fn($modifierId) => isset($modifierOptions[$modifierId]) ? $modifierOptions[$modifierId]->price : 0);
                    $this->orderItemModifiersPrice[$key] = $modifierTotal;
                }

                // Recalculate item amount
                $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $item->price;
                $this->orderItemAmount[$key] = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
            }

            $this->calculateTotal();
            return;
        }

        $order = $this->tableOrderID ? $this->tableOrder->activeOrder : Order::find($this->orderID);

        // Early return if no valid order or order is paid
        if (!$order || $order->status === 'paid') {
            return;
        }

        // Efficiently get the slug from the order's order type ID
        $orderTypeSlugFromOrder = $order->order_type_id
            ? OrderType::where('id', $order->order_type_id)->value('slug') ?? $order->order_type
            : $order->order_type;

        // Check if order type is actually changing (for draft orders, skip recalculation if not changing)
        $orderTypeChanged = $order->order_type_id != $this->orderTypeId;

        // For draft orders being loaded (not changed), preserve charges and skip recalculation
        if ($order->status === 'draft' && !$orderTypeChanged) {
            // Order type hasn't changed, just loading - preserve charges and don't recalculate
            // Deduplicate charges if they exist
            if (!empty($this->extraCharges)) {
                $this->extraCharges = collect($this->extraCharges)->unique('id')->values();
            }
            return;
        }

        // Keep existing charges if order type is unchanged, otherwise set new ones
        // Deduplicate charges to prevent duplicates
        $chargesToSet = $orderTypeSlugFromOrder === $this->orderTypeSlug ? $order->extraCharges : $mainExtraCharges;
        // Safety check: ensure chargesToSet is a collection before calling unique()
        $this->extraCharges = $chargesToSet ? collect($chargesToSet)->unique('id')->values() : collect([]);

        $this->orderStatus = $order->order_status;

        // Recalculate prices for all items in cart when order type changes
        $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
        foreach ($this->orderItemList as $key => $item) {
            if ($this->orderTypeId) {
                $item->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                }
            }

            // Recalculate modifier prices
            if (!empty($this->itemModifiersSelected[$key])) {
                $modifierOptions = $this->getModifierOptionsProperty();
                $modifierTotal = collect($this->itemModifiersSelected[$key])
                    ->sum(fn($modifierId) => isset($modifierOptions[$modifierId]) ? $modifierOptions[$modifierId]->price : 0);
                $this->orderItemModifiersPrice[$key] = $modifierTotal;
            }

            // Recalculate item amount
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $item->price;
            $this->orderItemAmount[$key] = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
        }

        $this->calculateTotal();
    }

    /**
     * Get the default delivery fee from branch settings
     */
    private function getDefaultDeliveryFee(): float
    {
        $branch = branch();
        if (!$branch) {
            return 0;
        }

        $deliverySettings = $branch->deliverySetting;
        if (!$deliverySettings || !$deliverySettings->is_enabled) {
            return 0;
        }

        // Return fixed fee if fee type is fixed
        if ($deliverySettings->fee_type->value === 'fixed') {
            return $deliverySettings->fixed_fee ?? 0;
        }

        // For other fee types, return 0 as they need distance calculation
        return 0;
    }

    /**
     * Update delivery fee and recalculate total
     */
    public function updatedDeliveryFee()
    {
        $this->calculateTotal();
    }

    public function updatedDeliveryDateTime($value)
    {
        if ($value) {
            $selectedDateTime = \Carbon\Carbon::parse($value, restaurant()->timezone ?? config('app.timezone'));
            $minDateTime = now(restaurant()->timezone ?? config('app.timezone'))->addMinute();

            // If selected time is in the past, reset to minimum allowed time
            if ($selectedDateTime->lt($minDateTime)) {
                $this->isPastTime = true;
                $this->deliveryDateTime = $minDateTime->format('Y-m-d H:i:s');
                $this->pickupDate = $minDateTime->displayDate(global_setting()->date_format ?? 'd-m-Y');
                $this->pickupTime = $minDateTime->format('H:i');
                // $this->addError('pickupDateTime', 'Please select a future time');
            } else {
                $this->isPastTime = false;
            }
        } else {
            $this->isPastTime = false;
        }
    }

    /**
     * Initialize pickup date and time from deliveryDateTime
     */
    private function initializePickupDateTime()
    {
        if ($this->deliveryDateTime) {
            try {
                $timezone = restaurant()->timezone ?? config('app.timezone');
                $dateTime = \Carbon\Carbon::parse($this->deliveryDateTime, $timezone);
                $this->pickupDate = $dateTime->displayDate(global_setting()->date_format ?? 'd-m-Y');
                $this->pickupTime = $dateTime->format('H:i');

                // Check if the time is in the past
                $minDateTime = now($timezone)->addMinute();
                $this->isPastTime = $dateTime->lt($minDateTime);
            } catch (\Exception $e) {
                // Fallback to current date/time if parsing fails
                $this->pickupDate = now(restaurant()->timezone)->displayDate(global_setting()->date_format ?? 'd-m-Y');
                $this->pickupTime = now(restaurant()->timezone)->format('H:i');
                $this->isPastTime = false;
            }
        } else {
            $this->pickupDate = now(restaurant()->timezone)->displayDate(global_setting()->date_format ?? 'd-m-Y');
            $this->pickupTime = now(restaurant()->timezone)->format('H:i');
            $this->isPastTime = false;
        }
    }

    /**
     * Update deliveryDateTime when pickup date changes
     */
     public function updatedPickupDate($value)
    {
        $this->updateDeliveryDateTime();
    }

    /**
     * Update deliveryDateTime when pickup time changes
     */
    public function updatedPickupTime($value)
    {
        $this->updateDeliveryDateTime();
    }

    /**
     * Combine pickup date and time into deliveryDateTime
     */
    private function updateDeliveryDateTime()
    {
        if ($this->pickupDate && $this->pickupTime) {
            // prepare timezone/date format up front so catch block can reuse them
            $dateFormat = global_setting()->date_format ?? 'd-m-Y';
            $timezone = restaurant()->timezone ?? config('app.timezone');

            try {
                // build a Carbon object in the restaurant timezone
                $parsedDate = parseRestaurantDate($this->pickupDate, $dateFormat, $timezone);
                // Parse time (already in H:i format)
                [$hours, $minutes] = explode(':', $this->pickupTime);
                $parsedDate->setTime((int)$hours, (int)$minutes, 0);

                // same‑day validation must happen before we convert to UTC
                $today = now($timezone)->startOfDay();
                $selectedDate = $parsedDate->copy()->startOfDay();

                if ($selectedDate->equalTo($today)) {
                    // require strictly future (<= now + 1m is considered past)
                    $minDateTime = now($timezone)->addMinute();
                    if ($parsedDate->lte($minDateTime)) {
                        // bump the picker values and mark past
                        $parsedDate = $minDateTime->copy();
                        $this->pickupDate = $parsedDate->displayDate($dateFormat);
                        $this->pickupTime = $parsedDate->format('H:i');
                        $this->isPastTime = true;
                    } else {
                        $this->isPastTime = false;
                    }
                } else {
                    // future dates are always valid
                    $this->isPastTime = false;
                }

                // store local datetime string (database holds local value)
                $this->deliveryDateTime = $parsedDate->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // If parsing fails, reset to now + 1 minute
                $minDateTime = now($timezone)->addMinute();
                $this->deliveryDateTime = $minDateTime->format('Y-m-d H:i:s');
                $this->pickupDate = $minDateTime->displayDate($dateFormat);
                $this->pickupTime = $minDateTime->format('H:i');
                $this->isPastTime = false;
            }
        }
    }


    public function updatedOrderStatus($value)
    {
        if ((!$this->orderID && !$this->tableOrderID) || !$this->orderDetail instanceof Order || is_null($value)) {
            return;
        }

        $this->orderDetail->update(['order_status' => $value]);
    }

    public function changeOrderType()
    {
        // Check if we have ongoing order items that would be affected
        if (!empty($this->orderItemList)) {
            // Show confirmation dialog before changing
            $this->alert('question', __('modules.order.changeOrderType'), [
                'text' => __('modules.order.changeOrderTypeConfirmation'),
                'showCancelButton' => true,
                'showConfirmButton' => true,
                'withConfirmButton' => __('app.yes') . ', ' . __('app.change'),
                'cancelButtonText' => __('app.cancel'),
                'timer' => 5000,
                'position' => 'center',
                'toast' => false,
                'onConfirmed' => 'confirmChangeOrderType',
            ]);
        } else {
            // No items in cart, safe to change directly
            $this->resetOrderTypeSelection();
        }
    }

    #[On('confirmChangeOrderType')]
    public function resetOrderTypeSelection()
    {
        // Reset order type related properties
        $this->orderTypeId = null;
        $this->orderTypeSlug = null;
        $this->orderType = null;
        $this->selectedDeliveryApp = null;
        // Allow order type selection popup to show even if disabled
        $this->allowOrderTypeSelection = true;

        // Clear delivery fee if it was set
        $this->deliveryFee = 0;

        // Recalculate with new settings
        $this->calculateTotal();

        $this->js(
            'if (window.posState) { window.posState.orderTypeId = null; window.posState.orderTypeSlug = null; window.posState.selectedDeliveryApp = null; }'
            . ' if (typeof window.showPosOrderTypeModal === "function") { window.showPosOrderTypeModal(); }'
        );
    }

    public function showTableOrder()
    {
        $this->selectWaiter = $this->tableOrder->activeOrder->waiter_id;
        $this->noOfPax = $this->tableOrder->activeOrder->number_of_pax;
    }

    public function showOrderDetail()
    {
        $this->orderDetail = $this->tableOrder->activeOrder;
        $this->orderType = $this->orderDetail->order_type;
        $this->orderTypeId = $this->orderDetail->order_type_id;

        // Update orderTypeSlug based on order_type_id if available
        if ($this->orderDetail->order_type_id) {
            $orderType = OrderType::select('slug')->find($this->orderDetail->order_type_id);
            $this->orderTypeSlug = $orderType ? $orderType->slug : $this->orderDetail->order_type;
        } else {
            $this->orderTypeSlug = $this->orderDetail->order_type;
        }

        // Load room service context if applicable
        if ($this->isRoomServiceOrder()) {
            if ($this->orderDetail->context_id) {
                $this->selectedStayId = $this->orderDetail->context_id;
                $this->billTo = $this->orderDetail->bill_to ?? 'POST_TO_ROOM';
            }

            if ($this->orderDetail->hotel_room_id) {
                $this->hotelRoomId = $this->orderDetail->hotel_room_id;
            }
        }

        $this->setupOrderItems();
    }

    public function showPayment($id)
    {
        $order = Order::find($id);
        $order->refresh();

        $this->dispatch('showPaymentModal', id: $order->id);
    }

    public function setupOrderItems()
    {
        if ($this->orderDetail) {
            $this->stampFreeItemKeys = [];
            $this->stampRuleIdByKey = [];
            $this->stampDiscountAmount = (float)($this->orderDetail->stamp_discount_amount ?? 0);
            $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
            $sanitizeNote = function ($note) {
                if (!$note) {
                    return $note;
                }
                $segments = array_map('trim', explode('|', $note));
                $clean = [];
                foreach ($segments as $segment) {
                    if ($segment === '') {
                        continue;
                    }
                    // Remove stamp discount notes; the UI badge shows the discount amount
                    if (stripos($segment, 'stamp discount') !== false) {
                        continue;
                    }
                    if (in_array($segment, $clean, true)) {
                        continue;
                    }
                    $clean[] = $segment;
                }
                return implode(' | ', $clean);
            };

            // Handle draft orders - they have OrderItems instead of KOT items
            if ($this->orderDetail->status === 'draft' && $this->orderDetail->items->count() > 0) {
                foreach ($this->orderDetail->items as $orderItem) {
                    // Check if this is a free item from stamp redemption
                    $isFreeItem = $orderItem->is_free_item_from_stamp ?? false;

                    // If it's a free item, use a special key (NO quotes!)
                    $key = $isFreeItem
                        ? 'free_stamp_' . $orderItem->stamp_rule_id . '_' . $orderItem->id
                        : '"order_item_' . $orderItem->id . '"';

                    $this->orderItemList[$key] = $orderItem->menuItem;
                    $this->orderItemQty[$key] = $orderItem->quantity;
                    $this->itemModifiersSelected[$key] = $orderItem->modifierOptions->pluck('id')->toArray();

                    // For free items, set amount to 0 directly
                    if ($isFreeItem) {
                        $this->orderItemAmount[$key] = 0;
                        $this->orderItemModifiersPrice[$key] = 0;
                    } else {
                        // CRITICAL: Use the amount from database (already includes discounts, stamp discounts, etc.)
                        // Don't recalculate from price - the database has the correct discounted amount
                        $this->orderItemAmount[$key] = (float)($orderItem->amount ?? 0);

                        // Calculate modifier price for display purposes (but don't use it for amount calculation)
                        $this->orderItemModifiersPrice[$key] = $orderItem->modifierOptions->sum(function ($modifier) {
                            return $modifier->pivot->modifier_option_price ?? $modifier->price;
                        });
                    }

                    if ($orderItem->menuItemVariation) {
                        $this->orderItemVariation[$key] = $orderItem->menuItemVariation;
                    }

                    if ($orderItem->note) {
                        $this->itemNotes[$key] = $sanitizeNote($orderItem->note);
                    }
                    if (($orderItem->is_free_item_from_stamp ?? false) && !empty($orderItem->stamp_rule_id)) {
                        $this->stampRuleIdByKey[$key] = $orderItem->stamp_rule_id;
                    }
                }
            } else {
                // Handle regular orders with KOT items
                // Ensure KOT relationships are loaded
                if (!$this->orderDetail->relationLoaded('kot')) {
                    $this->orderDetail->load('kot.items.menuItem', 'kot.items.menuItemVariation', 'kot.items.modifierOptions');
                }

                foreach ($this->orderDetail->kot as $kot) {
                    $this->kotList['kot_' . $kot->id] = $kot;

                    // Ensure items relationship is loaded for this KOT
                    if (!$kot->relationLoaded('items')) {
                        $kot->load('items.menuItem', 'items.menuItemVariation', 'items.modifierOptions');
                    }

                    foreach ($kot->items->where('status', '!=', 'cancelled') as $item) {
                        $key = '"kot_' . $kot->id . '_' . $item->id . '"';

                        $this->orderItemList[$key] = $item->menuItem;
                        $this->orderItemQty[$key] = $item->quantity;
                        $this->itemModifiersSelected[$key] = $item->modifierOptions->pluck('id')->toArray();

                        // CRITICAL: Use the amount from database (already includes discounts, stamp discounts, etc.)
                        // Don't recalculate from price - the database has the correct discounted amount
                        // For free items, amount is already 0 in database
                        $this->orderItemAmount[$key] = (float)($item->amount ?? 0);

                        // Calculate modifier price for display purposes (but don't use it for amount calculation)
                        $this->orderItemModifiersPrice[$key] = $item->modifierOptions->sum('price');

                        if ($item->menuItemVariation) {
                            $this->orderItemVariation[$key] = $item->menuItemVariation;
                        }

                        if ($item->note) {
                            $this->itemNotes[$key] = $sanitizeNote($item->note);
                        }

                        if (($item->is_free_item_from_stamp ?? false) && !empty($item->stamp_rule_id)) {
                            $this->stampFreeItemKeys[$item->stamp_rule_id][] = $key;
                            $this->stampRuleIdByKey[$key] = $item->stamp_rule_id;
                        }
                    }
                }

                // IMPORTANT: Also load free items and discounted items from order_items (they may not be in KOT)
                // Free items from stamp redemption
                $freeItems = $this->orderDetail->items()->where('is_free_item_from_stamp', true)->get();
                foreach ($freeItems as $freeItem) {
                    $key = 'free_stamp_' . $freeItem->stamp_rule_id . '_' . $freeItem->id;

                    $this->orderItemList[$key] = $freeItem->menuItem;
                    $this->orderItemQty[$key] = $freeItem->quantity;
                    $this->orderItemAmount[$key] = 0; // Free item
                    $this->orderItemModifiersPrice[$key] = 0;
                    $this->itemModifiersSelected[$key] = $freeItem->modifierOptions->pluck('id')->toArray();

                    if ($freeItem->menuItemVariation) {
                        $this->orderItemVariation[$key] = $freeItem->menuItemVariation;
                    }

                    if ($freeItem->note) {
                        $this->itemNotes[$key] = $sanitizeNote($freeItem->note);
                    }

                    if (!empty($freeItem->stamp_rule_id)) {
                        $this->stampFreeItemKeys[$freeItem->stamp_rule_id][] = $key;
                        $this->stampRuleIdByKey[$key] = $freeItem->stamp_rule_id;
                    }
                }

                // Items with stamp discounts (applied from customer site)
                // These items exist in order_items but may not have corresponding kot_items yet
                // Note: order_items table only has 'stamp_rule_id' and 'is_free_item_from_stamp' columns
                // The discount amount is stored at order level (orders.stamp_discount_amount) and already deducted from order_items.amount
                $discountedOrderItems = $this->orderDetail->items()
                    ->whereNotNull('stamp_rule_id')
                    ->where('is_free_item_from_stamp', false) // Exclude free items (already loaded above)
                    ->get();

                foreach ($discountedOrderItems as $orderItem) {
                    // Check if this item is already in orderItemList (from kot_items)
                    $alreadyLoaded = false;
                    foreach ($this->orderItemList as $existingKey => $existingItem) {
                        if (strpos($existingKey, 'kot_') !== false) {
                            // Extract kot_item_id from key
                            $keyParts = explode('_', trim($existingKey, '"'));
                            if (count($keyParts) >= 3 && $keyParts[0] === 'kot') {
                                // Check if this kot_item matches the order_item
                                try {
                                    $kotItemId = $keyParts[2] ?? null;
                                    if ($kotItemId) {
                                        $kotItem = \App\Models\KotItem::find($kotItemId);
                                        if (
                                            $kotItem && $kotItem->menu_item_id == $orderItem->menu_item_id
                                            && $kotItem->menu_item_variation_id == $orderItem->menu_item_variation_id
                                        ) {
                                            $alreadyLoaded = true;
                                            break;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    // Continue checking
                                }
                            }
                        }
                    }

                    // Only add if not already loaded from kot_items
                    if (!$alreadyLoaded) {
                        $key = '"order_item_' . $orderItem->id . '"';

                        $this->orderItemList[$key] = $orderItem->menuItem;
                        $this->orderItemQty[$key] = $orderItem->quantity;
                        // CRITICAL: Use the amount from database (already includes discounts, stamp discounts, etc.)
                        // Don't recalculate from price - the database has the correct discounted amount
                        $this->orderItemAmount[$key] = (float)($orderItem->amount ?? 0);
                        $this->orderItemModifiersPrice[$key] = $orderItem->modifierOptions->sum(function ($modifier) {
                            return $modifier->pivot->modifier_option_price ?? $modifier->price;
                        });
                        $this->itemModifiersSelected[$key] = $orderItem->modifierOptions->pluck('id')->toArray();

                        if ($orderItem->menuItemVariation) {
                            $this->orderItemVariation[$key] = $orderItem->menuItemVariation;
                        }

                        if ($orderItem->note) {
                            $this->itemNotes[$key] = $sanitizeNote($orderItem->note);
                        }
                    }
                }
            }

            // Calculate tax details for existing items after setting up all items
            if ($this->taxMode === 'item') {
                $this->updateOrderItemTaxDetails();
            }

            $this->calculateTotal();
        }
    }

    public function addCartItems($id, $variationCount, $modifierCount)
    {
        $this->refreshMultiPosRegistrationState();
        if ($this->shouldBlockPos) {
            return;
        }

        if (($this->orderID && !user_can('Update Order')) || (!$this->orderID && !user_can('Create Order'))) {
            return;
        }

        // Check order limit
        $orderStats = getRestaurantOrderStats(branch()->id);
        if (!$orderStats['unlimited'] && $orderStats['current_count'] >= $orderStats['order_limit']) {
            return;
        }

        if ($this->orderID && $this->orderDetail && $this->orderDetail->status === 'kot') {
            $this->addError('error', __('messages.errorWantToCreateNewKot'));
            $this->showNewKotButton = true;
            $this->showErrorModal = true;
            return;
        }

        $this->dispatch('play_beep');

        // Get normalized delivery app ID and order type for contextual loading
        $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
        $orderTypeId = $this->orderTypeId;

        $cacheKey = sprintf(
            'pos.menu_item.%s.%s.%s.%s',
            branch()->id,
            $id,
            $orderTypeId ?: 'none',
            $normalizedDeliveryAppId ?: 'none'
        );

        // Contextually eager load item with price relationships to avoid N+1 queries; cache for reuse
        $this->menuItem = Cache::remember(
            $cacheKey,
            now()->addHours(2),
            function () use ($id, $orderTypeId, $normalizedDeliveryAppId) {
                return MenuItem::with([
                    'prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                        $q->where('status', true)
                            ->whereNull('menu_item_variation_id');

                        if ($orderTypeId) {
                            $q->where(function ($query) use ($orderTypeId) {
                                $query->where('order_type_id', $orderTypeId);
                            });
                        }

                        if ($normalizedDeliveryAppId) {
                            $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                    ->orWhereNull('delivery_app_id');
                            });
                        } else {
                            $q->whereNull('delivery_app_id');
                        }
                    },
                    'variations.prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                        $q->where('status', true);

                        if ($orderTypeId) {
                            $q->where(function ($query) use ($orderTypeId) {
                                $query->where('order_type_id', $orderTypeId);
                            });
                        }

                        if ($normalizedDeliveryAppId) {
                            $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                    ->orWhereNull('delivery_app_id');
                            });
                        } else {
                            $q->whereNull('delivery_app_id');
                        }
                    },
                    'modifierGroups.options.prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                        $q->where('status', true);

                        if ($orderTypeId) {
                            $q->where(function ($query) use ($orderTypeId) {
                                $query->where('order_type_id', $orderTypeId);
                            });
                        }

                        if ($normalizedDeliveryAppId) {
                            $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                    ->orWhereNull('delivery_app_id');
                            });
                        } else {
                            $q->whereNull('delivery_app_id');
                        }
                    }
                ])->find($id);
            }
        );

        // Set price context on the loaded item and its relationships
        if ($this->orderTypeId && $this->menuItem) {
            $this->menuItem->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);

            // Set context on variations
            foreach ($this->menuItem->variations as $variation) {
                $variation->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }

            // Set context on modifier options
            foreach ($this->menuItem->modifierGroups as $group) {
                foreach ($group->options as $option) {
                    $option->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                }
            }
        }

        // Initialize item note if it doesn't exist
        if (!isset($this->itemNotes[$id])) {
            $this->itemNotes[$id] = '';
        }

        if ($variationCount > 0) {
            $this->showVariationModal = true;
        } elseif ($modifierCount > 0) {
            $this->selectedModifierItem = $id;
            $this->showModifiersModal = true;
        } else {
            if (! ($this->menuItem->in_stock ?? true)) {
                $this->alert('error', IngredientStockGuard::insufficientStockMessage(), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }

            if ($this->isIngredientStockBlockedForLine((int) $id, null, [], (int) (($this->orderItemQty[$id] ?? 0) + 1))) {
                return;
            }

            $this->syncCart($id);
        }
    }

    #[On('setTable')]
    public function setTable(Table $table)
    {
        // Check table lock status first
        $tableModel = Table::find($table->id);
        if (!$tableModel->canBeAccessedByUser(user()->id)) {
            $session = $tableModel->tableSession;
            $lockedByUser = $session?->lockedByUser;

            $lockedUserName = $lockedByUser?->name ?? 'Admin';
            $this->alert('error', __('messages.tableLockedByUser', ['user' => $lockedUserName]), [
                'toast' => true,
                'position' => 'top-end',
                'timer' => 5000,
                'showCancelButton' => false,
            ]);

            $this->showTableModal = false;
            return;
        }

        $excludeOrderId = $this->orderID ? (int) $this->orderID : null;
        if ($tableModel->isSeatFull($excludeOrderId)) {
            $this->alert('error', __('messages.tableSeatsFull', ['table' => $tableModel->table_code]), [
                'toast' => true,
                'position' => 'top-end',
                'timer' => 5000,
                'showCancelButton' => false,
            ]);

            $this->showTableModal = false;
            return;
        }

        if ($excludeOrderId && $this->noOfPax) {
            $paxToFit = (int) $this->noOfPax;
            if (!$tableModel->canAcceptPax($paxToFit, $excludeOrderId)) {
                $remaining = (int) ($tableModel->remainingSeats($excludeOrderId) ?? 0);
                $this->alert('error', __('messages.paxExceedsRemainingSeats', [
                    'pax' => $paxToFit,
                    'remaining' => $remaining,
                    'table' => $tableModel->table_code,
                ]), [
                    'toast' => true,
                    'position' => 'top-end',
                    'timer' => 5000,
                    'showCancelButton' => false,
                ]);

                $this->showTableModal = false;
                return;
            }
        }

        // Lock the table for current user
        $lockResult = $tableModel->lockForUser(user()->id);

        if (!$lockResult['success']) {
            $this->alert('error', __('messages.tableLockFailed'), [
                'toast' => true,
                'position' => 'top-end',
                'timer' => 5000,
                'showCancelButton' => false,
            ]);

            $this->showTableModal = false;
            return;
        }

        // If the same table is already set, just ensure it's locked and return
        if (!is_null($this->tableId) && $this->tableId === $table->id) {
            // Table is already set, just close modal if open
            $this->showTableModal = false;
            return;
        }


        $isChangingTable = !is_null($this->tableNo) &&
            !is_null($this->tableId) &&
            ($this->tableId !== $table->id) &&
            $this->showTableModal;

        if ($isChangingTable) {
            // Store the selected table temporarily and show confirmation modal
            $this->pendingTable = $table;
            $this->showTableModal = false;
            $this->showTableChangeConfirmationModal = true;
        } else {
            // No existing table or setting table programmatically (not from modal), apply immediately
            $previousTable = $this->tableId ? Table::find($this->tableId) : null;
            $this->tableNo = $table->table_code;
            $this->tableId = $table->id;

            if ($this->orderID) {
                Order::where('id', $this->orderID)->update(['table_id' => $table->id]);

                // Refresh orderDetail to ensure it's the latest object
                $this->orderDetail = Order::find($this->orderID);

                if (
                    $this->orderDetail && is_object($this->orderDetail) && $this->orderDetail->date_time &&
                    $this->orderDetail->date_time instanceof \Carbon\Carbon &&
                    $this->orderDetail->date_time->format('d-m-Y') == now()->format('d-m-Y')
                ) {
                    Table::where('id', $this->tableId)->update([
                        'available_status' => 'running'
                    ]);
                }

                $this->orderDetail->fresh();
            }

            $this->dispatchOrderTableAssignedEvent($table, $previousTable);

            $this->showTableModal = false;

            // Sync waiter from table assignment if available
            $this->syncWaiterFromTableAssignment();

        }
    }

    public function openTableChangeConfirmation()
    {
        // Always open table modal first
        $this->showTableModal = true;
        $this->dispatch('refreshSetTableComponent');
    }

    public function confirmTableChange()
    {
        if (!$this->pendingTable) {
            $this->showTableChangeConfirmationModal = false;
            return;
        }

        // Apply the table change
        $table = $this->pendingTable;
        $previousTable = $this->tableId ? Table::find($this->tableId) : null;

        $excludeOrderId = $this->orderID ? (int) $this->orderID : null;
        if ($table->isSeatFull($excludeOrderId)) {
            $this->alert('error', __('messages.tableSeatsFull', ['table' => $table->table_code]), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            $this->showTableChangeConfirmationModal = false;
            $this->pendingTable = null;
            return;
        }

        $paxToFit = $this->noOfPax ? (int) $this->noOfPax : 1;
        if ($excludeOrderId && !$table->canAcceptPax($paxToFit, $excludeOrderId)) {
            $remaining = (int) ($table->remainingSeats($excludeOrderId) ?? 0);
            $this->alert('error', __('messages.paxExceedsRemainingSeats', [
                'pax' => $paxToFit,
                'remaining' => $remaining,
                'table' => $table->table_code,
            ]), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            $this->showTableChangeConfirmationModal = false;
            $this->pendingTable = null;
            return;
        }

        // Release previous table lock if exists
        if ($this->tableId && $this->tableId !== $table->id) {
            if ($previousTable) {
                $previousTable->unlock(null, true);
                Table::where('id', $this->tableId)->update([
                    'available_status' => 'available'
                ]);
            }
        }

        $this->tableNo = $table->table_code;
        $this->tableId = $table->id;

        if ($this->orderID) {
            Order::where('id', $this->orderID)->update(['table_id' => $table->id]);

            // Refresh orderDetail to ensure it's the latest object
            $this->orderDetail = Order::find($this->orderID);

            if (
                $this->orderDetail && is_object($this->orderDetail) && $this->orderDetail->date_time &&
                $this->orderDetail->date_time instanceof \Carbon\Carbon &&
                $this->orderDetail->date_time->format('d-m-Y') == now()->format('d-m-Y')
            ) {
                Table::where('id', $this->tableId)->update([
                    'available_status' => 'running'
                ]);
            }

            $this->orderDetail->fresh();
        }

        $this->dispatchOrderTableAssignedEvent($table, $previousTable);

        // Clear pending table and close modals
        $this->pendingTable = null;
        $this->showTableChangeConfirmationModal = false;

        // Sync waiter from table assignment if available
        $this->syncWaiterFromTableAssignment();

    }

    public function cancelTableChange()
    {
        // Unlock the pending table if it was locked
        if ($this->pendingTable) {
            $tableModel = Table::find($this->pendingTable->id);
            if ($tableModel) {
                $tableModel->unlock(null, true);
            }
        }

        // Clear pending table and close modal
        $this->pendingTable = null;
        $this->showTableChangeConfirmationModal = false;
    }

    public function openMergeTableModal()
    {
        // Fetch tables that have orders which are not paid
        // Query from Order side since Table doesn't have orders() relationship
        $unpaidOrders = Order::where('branch_id', branch()->id)
            ->whereNotNull('table_id')
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'canceled')
            ->with([
                'table',
                'items.menuItem',
                'items.menuItemVariation',
                'items.modifierOptions',
                'kot.items.menuItem',
                'kot.items.menuItemVariation',
                'kot.items.modifierOptions'
            ])
            ->get();

        // Group by table_id and get unique tables, exclude current table if set
        $tableIds = $unpaidOrders->pluck('table_id')->unique()->filter();

        $query = Table::whereIn('id', $tableIds)
            ->where('branch_id', branch()->id);

        // Exclude current table if it's set
        if ($this->tableId) {
            $query->where('id', '!=', $this->tableId);
        }

        $this->tablesWithUnpaidOrders = $query
            ->with(['activeOrder.items.menuItem', 'activeOrder.items.menuItemVariation', 'activeOrder.items.modifierOptions', 'activeOrder.kot.items.menuItem', 'activeOrder.kot.items.menuItemVariation', 'activeOrder.kot.items.modifierOptions'])
            ->orderBy('table_code')
            ->get()
            ->map(function ($table) use ($unpaidOrders) {
                // Attach unpaid orders to each table
                $table->unpaidOrders = $unpaidOrders->where('table_id', $table->id)->values();
                return $table;
            });

        $this->showMergeTableModal = true;
    }

    public function closeMergeTableModal()
    {
        $this->showMergeTableModal = false;
        $this->tablesWithUnpaidOrders = [];
        $this->selectedTablesForMerge = [];
    }

    public function toggleTableSelection($tableId)
    {
        if (in_array($tableId, $this->selectedTablesForMerge)) {
            $this->selectedTablesForMerge = array_values(array_diff($this->selectedTablesForMerge, [$tableId]));
        } else {
            $this->selectedTablesForMerge[] = $tableId;
        }
    }

    /**
     * Delete orders from merged tables after successful order save
     */
    private function deleteMergedTableOrders()
    {
        if (empty($this->ordersToDeleteAfterMerge)) {
            return;
        }

        try {
            // Get all orders to delete with their relationships
            $ordersToDelete = Order::whereIn('id', $this->ordersToDeleteAfterMerge)
                ->with(['kot.items', 'items', 'taxes', 'charges'])
                ->get();

            if ($ordersToDelete->isEmpty()) {
                $this->ordersToDeleteAfterMerge = [];
                return;
            }

            $orderIds = $ordersToDelete->pluck('id')->toArray();

            // Collect KOT IDs from loaded relationships
            $kotIds = $ordersToDelete->flatMap(function ($order) {
                return $order->kot->pluck('id');
            })->filter()->unique()->toArray();

            // Bulk delete KOT items
            if (!empty($kotIds)) {
                KotItem::whereIn('kot_id', $kotIds)->delete();
                Kot::whereIn('id', $kotIds)->delete();
            }

            // Bulk delete order items, taxes, and charges
            OrderItem::whereIn('order_id', $orderIds)->delete();
            OrderTax::whereIn('order_id', $orderIds)->delete();
            OrderCharge::whereIn('order_id', $orderIds)->delete();

            // Get table IDs from orders before deleting
            $tableIds = $ordersToDelete->pluck('table_id')->filter()->unique()->toArray();

            // Bulk delete orders
            Order::whereIn('id', $orderIds)->delete();

            // Update table statuses and unlock tables
            if (!empty($tableIds)) {
                Table::whereIn('id', $tableIds)->update(['available_status' => 'available']);

                // Unlock tables
                foreach ($tableIds as $tableId) {
                    $table = Table::find($tableId);
                    if ($table) {
                        $table->unlock(null, true);
                    }
                }
            }

            $deletedCount = count($ordersToDelete);
            $this->ordersToDeleteAfterMerge = [];

            if ($deletedCount > 0) {
                Log::info("Deleted {$deletedCount} order(s) from merged tables");
                $this->dispatch('refreshOrders');
                $this->dispatch('refreshPos');
            }
        } catch (\Exception $e) {
            Log::error('Error deleting merged table orders: ' . $e->getMessage());
            // Clear selection even on error to prevent retry issues
            $this->ordersToDeleteAfterMerge = [];
        }
    }

    public function mergeSelectedTables()
    {
        if (empty($this->selectedTablesForMerge)) {
            $this->alert('error', __('messages.pleaseSelectAtLeastOneTableToMerge'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
        $this->ordersToDeleteAfterMerge = [];
        $this->mergedOrderItemIds = [];
        $this->mergedCartKeys = [];

        $mergedOrders = collect();

        // Get all unpaid orders for selected tables
        foreach ($this->selectedTablesForMerge as $tableId) {
            // Get all unpaid orders for this table
            $unpaidOrders = Order::where('table_id', $tableId)
                ->where('branch_id', branch()->id)
                ->where('status', '!=', 'paid')
                ->where('status', '!=', 'canceled')
                ->with([
                    'items.menuItem',
                    'items.menuItemVariation',
                    'items.modifierOptions',
                    'kot.items.menuItem',
                    'kot.items.menuItemVariation',
                    'kot.items.modifierOptions'
                ])
                ->get();

            foreach ($unpaidOrders as $order) {
                // Track order to delete after merge
                $this->ordersToDeleteAfterMerge[] = $order->id;
                $mergedOrders->push($order);

                // Get OrderItems from this order - track their IDs to transfer later
                if ($order->items->count() > 0) {
                    foreach ($order->items as $orderItem) {
                        // Track OrderItem ID to transfer later
                        $this->mergedOrderItemIds[] = $orderItem->id;
                        // Also merge into cart for display and track the cart key
                        $cartKey = $this->mergeOrderItem($orderItem, $normalizedDeliveryAppId);
                        if ($cartKey) {
                            $this->mergedCartKeys[] = $cartKey;
                        }
                    }
                } else {
                    // Handle regular orders with KOT items (merge into cart for display only)
                    foreach ($order->kot as $kot) {
                        foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                            $this->mergeKotItem($kotItem, $normalizedDeliveryAppId);
                        }
                    }
                }
            }
        }

        // Keep each order's monetary discount; do not re-apply the first order's
        // percent/fixed policy against the combined subtotal.
        $combinedDiscountAmount = (float) ($this->discountAmount ?? 0);
        $loyaltyStampsActive = function_exists('module_enabled') && module_enabled('Loyalty');
        $combinedStampDiscountAmount = $loyaltyStampsActive
            ? (float) ($this->stampDiscountAmount ?? 0)
            : 0;

        foreach ($mergedOrders as $order) {
            $combinedDiscountAmount += (float) ($order->discount_amount ?? 0);
            if ($loyaltyStampsActive) {
                $combinedStampDiscountAmount += (float) ($order->stamp_discount_amount ?? 0);
            }
        }
        $combinedDiscountAmount = round($combinedDiscountAmount, 2);
        $combinedStampDiscountAmount = round($combinedStampDiscountAmount, 2);

        if ($combinedDiscountAmount > 0) {
            $this->discountType = 'fixed';
            $this->discountValue = $combinedDiscountAmount;
            $this->discountAmount = $combinedDiscountAmount;
        } else {
            $this->discountType = null;
            $this->discountValue = null;
            $this->discountAmount = 0;
        }

        // Stamp discounts are Loyalty-module state only.
        if ($loyaltyStampsActive) {
            $this->stampDiscountAmount = $combinedStampDiscountAmount;
        } else {
            $this->stampDiscountAmount = 0;
        }

        // Update tax details if item tax mode is enabled
        if ($this->taxMode === 'item') {
            $this->updateOrderItemTaxDetails();
        }

        // Recalculate totals after merging
        $this->calculateTotal();

        // Close modal and show success message
        $this->closeMergeTableModal();

        $this->alert('success', __('messages.tablesMergedSuccessfully'), [
            'toast' => true,
            'position' => 'top-end',
            'timer' => 3000,
        ]);
    }

    private function mergeOrderItem($orderItem, $normalizedDeliveryAppId)
    {
        if (!$orderItem->menuItem) {
            return null;
        }

        // Set price context
        if ($this->orderTypeId) {
            $orderItem->menuItem->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            if ($orderItem->menuItemVariation) {
                $orderItem->menuItemVariation->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }
            foreach ($orderItem->modifierOptions as $modifier) {
                $modifier->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }
        }

        // Create a unique key for this item
        $baseKey = '"merged_order_' . $orderItem->id . '"';
        $key = $baseKey;
        $counter = 1;

        // Ensure key is unique
        while (isset($this->orderItemList[$key])) {
            $key = $baseKey . '_' . $counter;
            $counter++;
        }

        $this->orderItemList[$key] = $orderItem->menuItem;
        $loyaltyStampsActive = function_exists('module_enabled') && module_enabled('Loyalty');
        $isFreeItemFromStamp = $loyaltyStampsActive && (bool) ($orderItem->is_free_item_from_stamp ?? false);
        $stampRuleId = $loyaltyStampsActive ? ($orderItem->stamp_rule_id ?? null) : null;
        if ($loyaltyStampsActive) {
            $this->orderItemList[$key]->is_free_item_from_stamp = $isFreeItemFromStamp;
            $this->orderItemList[$key]->stamp_rule_id = $stampRuleId;
        }

        $this->orderItemQty[$key] = $orderItem->quantity;
        $this->itemModifiersSelected[$key] = $orderItem->modifierOptions->pluck('id')->toArray();
        $this->orderItemModifiersPrice[$key] = $orderItem->modifierOptions->sum('price');

        // Keep persisted amount (includes stamp discounts / free-item adjustments when Loyalty is active).
        $basePrice = $orderItem->menuItemVariation ? $orderItem->menuItemVariation->price : $orderItem->menuItem->price;
        $fullAmount = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
        $persistedAmount = $orderItem->amount;
        $this->orderItemAmount[$key] = ($persistedAmount !== null && $persistedAmount !== '')
            ? (float) $persistedAmount
            : (float) $fullAmount;

        if ($loyaltyStampsActive && $stampRuleId) {
            $this->stampRuleIdByKey[$key] = $stampRuleId;
            if ($isFreeItemFromStamp) {
                if (!isset($this->stampFreeItemKeys[$stampRuleId])) {
                    $this->stampFreeItemKeys[$stampRuleId] = [];
                }
                $this->stampFreeItemKeys[$stampRuleId][] = $key;
            }
        }

        if ($orderItem->menuItemVariation) {
            $this->orderItemVariation[$key] = $orderItem->menuItemVariation;
        }

        if ($orderItem->note) {
            $this->itemNotes[$key] = $orderItem->note;
        }

        return $key; // Return the cart key for tracking
    }

    private function mergeKotItem($kotItem, $normalizedDeliveryAppId)
    {
        if (!$kotItem->menuItem) {
            return;
        }

        // Set price context
        if ($this->orderTypeId) {
            $kotItem->menuItem->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            if ($kotItem->menuItemVariation) {
                $kotItem->menuItemVariation->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }
            foreach ($kotItem->modifierOptions as $modifier) {
                $modifier->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }
        }

        // Create a unique key for this item
        $baseKey = '"merged_kot_' . $kotItem->kot_id . '_' . $kotItem->id . '"';
        $key = $baseKey;
        $counter = 1;

        // Ensure key is unique
        while (isset($this->orderItemList[$key])) {
            $key = $baseKey . '_' . $counter;
            $counter++;
        }

        $this->orderItemList[$key] = $kotItem->menuItem;
        $loyaltyStampsActive = function_exists('module_enabled') && module_enabled('Loyalty');
        $isFreeItemFromStamp = $loyaltyStampsActive && (bool) ($kotItem->is_free_item_from_stamp ?? false);
        $stampRuleId = $loyaltyStampsActive ? ($kotItem->stamp_rule_id ?? null) : null;
        if ($loyaltyStampsActive) {
            $this->orderItemList[$key]->is_free_item_from_stamp = $isFreeItemFromStamp;
            $this->orderItemList[$key]->stamp_rule_id = $stampRuleId;
        }

        $this->orderItemQty[$key] = $kotItem->quantity;
        $this->itemModifiersSelected[$key] = $kotItem->modifierOptions->pluck('id')->toArray();
        $this->orderItemModifiersPrice[$key] = $kotItem->modifierOptions->sum('price');

        // Keep persisted amount (includes stamp discounts / free-item adjustments when Loyalty is active).
        $basePrice = $kotItem->menuItemVariation ? $kotItem->menuItemVariation->price : $kotItem->menuItem->price;
        $fullAmount = $this->orderItemQty[$key] * ($basePrice + ($this->orderItemModifiersPrice[$key] ?? 0));
        $persistedAmount = $kotItem->amount;
        $this->orderItemAmount[$key] = ($persistedAmount !== null && $persistedAmount !== '')
            ? (float) $persistedAmount
            : (float) $fullAmount;

        if ($loyaltyStampsActive && $stampRuleId) {
            $this->stampRuleIdByKey[$key] = $stampRuleId;
            if ($isFreeItemFromStamp) {
                if (!isset($this->stampFreeItemKeys[$stampRuleId])) {
                    $this->stampFreeItemKeys[$stampRuleId] = [];
                }
                $this->stampFreeItemKeys[$stampRuleId][] = $key;
            }
        }

        if ($kotItem->menuItemVariation) {
            $this->orderItemVariation[$key] = $kotItem->menuItemVariation;
        }

        if ($kotItem->note) {
            $this->itemNotes[$key] = $kotItem->note;
        }
    }

    #[On('setPosVariation')]
    public function setPosVariation($variationId)
    {
        $this->showVariationModal = false;
        $menuItemVariation = MenuItemVariation::find($variationId);

        // Set price context on variation BEFORE using it to prevent price flickering
        if ($this->orderTypeId) {
            $menuItemVariation->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
        }

        $modifiersAvailable = $menuItemVariation->menuItem->modifiers->count();

        if ($modifiersAvailable) {
            $this->selectedModifierItem = $menuItemVariation->menu_item_id . '_' . $variationId;
            $this->showModifiersModal = true;
        } else {
            $this->orderItemVariation['"' . $menuItemVariation->menu_item_id . '_' . $variationId . '"'] = $menuItemVariation;
            $this->syncCart('"' . $menuItemVariation->menu_item_id . '_' . $variationId . '"');
        }
    }

    public function syncCart($id)
    {
        // Update table activity when adding items
        if ($this->tableId) {
            $table = Table::find($this->tableId);
            $table?->updateActivity(user()->id);
        }

        if (!isset($this->orderItemList[$id])) {
            if (! ($this->menuItem->in_stock ?? true)) {
                $this->alert('error', IngredientStockGuard::insufficientStockMessage(), [
                    'toast' => true,
                    'position' => 'top-end',
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

            // Get price from the menuItem which already has context set from menuItems() computed property
            if ($this->orderTypeId && !isset($this->menuItem->contextOrderTypeId)) {
                $this->menuItem->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                if (isset($this->orderItemVariation[$id])) {
                    $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
            }

            $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
            $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));

            // Check for automatic stamp redemption when item is added
            if (module_enabled('Loyalty')) {
                $this->checkAndAutoRedeemStampsForItem($id);
            }

            $this->calculateTotal();

            // Auto-open loyalty points redemption modal when first item is added (if customer has points)
            // Only if modal is not already open and points haven't been redeemed yet
            // Count only non-free items (exclude free items from stamp redemption)
            $nonFreeItemCount = 0;
            foreach ($this->orderItemList as $key => $item) {
                if (strpos($key, 'free_stamp_') !== 0 && !(isset($this->itemNotes[$key]) && str_contains($this->itemNotes[$key] ?? '', __('loyalty::app.freeItemFromStamp')))) {
                    $nonFreeItemCount++;
                }
            }

            if (
                module_enabled('Loyalty') &&
                $this->customerId &&
                !$this->showLoyaltyRedemptionModal &&
                $this->loyaltyPointsRedeemed == 0 &&
                $nonFreeItemCount == 1 &&
                $this->subTotal > 0
            ) {
                $this->openLoyaltyRedemptionModal();
            }
        } else {
            $this->addQty($id);
        }
    }

    protected function checkAndAutoRedeemStampsForItem($itemKey)
    {
        if (!module_enabled('Loyalty')) {
            return;
        }

        $handler = method_exists($this, 'loyaltyHandler') ? $this->loyaltyHandler() : null;
        if ($handler && method_exists($handler, 'checkAndAutoRedeemStampsForItem')) {
            $handler->checkAndAutoRedeemStampsForItem($itemKey);
        }
    }

    public function deleteCartItems($id)
    {
        $id = str_replace('"', '', (string) $id);
        $parts = explode('_', $id);

        if (count($parts) >= 3 && $parts[0] === 'kot') {
            if (!user_can('Delete Item After KOT')) {
                $this->alert('error', __('messages.permissionDenied'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }
        } elseif (count($parts) >= 3 && $parts[0] === 'order' && $parts[1] === 'item') {
            if (!user_can('Delete Order')) {
                $this->alert('error', __('messages.permissionDenied'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }
        } elseif ($this->orderDetail && $this->orderDetail->id) {
            if (!user_can('Update Order')) {
                $this->alert('error', __('messages.permissionDenied'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);

                return;
            }
        } elseif (!user_can('Create Order')) {
            $this->alert('error', __('messages.permissionDenied'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        // Update table activity when removing items
        if ($this->tableId) {
            $table = Table::find($this->tableId);
            $table?->updateActivity(user()->id);
        }

        // Check if this is a main item (not a free item) and if so, remove associated free items
        if (isset($this->orderItemList[$id]) && strpos($id, 'free_stamp_') !== 0) {
            // This is a main item - check if there are free items associated with it
            $menuItem = $this->orderItemList[$id];
            $menuItemId = isset($this->orderItemVariation[$id])
                ? $this->orderItemVariation[$id]->menu_item_id
                : $menuItem->id;

            // Find and remove free items that were added for this menu item
            foreach ($this->orderItemList as $key => $item) {
                if (strpos($key, 'free_stamp_') === 0) {
                    $this->unsetLoyaltyOrderRule($key, $menuItemId);
                }
            }
        }

        // Remove from session arrays
        unset($this->orderItemList[$id]);
        unset($this->orderItemQty[$id]);
        unset($this->orderItemAmount[$id]);
        unset($this->orderItemVariation[$id]);
        unset($this->itemModifiersSelected[$id]);
        unset($this->itemNotes[$id]);
        unset($this->orderItemModifiersPrice[$id]);
        unset($this->orderItemTaxDetails[$id]);

        // Early return if no order detail or not a valid object
        if (!$this->orderDetail || !is_object($this->orderDetail)) {
            $this->calculateTotal();
            return;
        }

        // Handle draft orders - they have order_item_ prefix
        if (count($parts) >= 3 && $parts[0] === 'order' && $parts[1] === 'item') {
            $orderItemId = $parts[2];

            // Check if the item being deleted is a free item from stamp before deleting
            $orderItem = OrderItem::find($orderItemId);
            $wasFreeItem = $orderItem && ($orderItem->is_free_item_from_stamp ?? false);
            $hadStampDiscount = $orderItem && !is_null($orderItem->stamp_rule_id);

            // CRITICAL: Also delete linked kot_items if they exist
            // This ensures consistency between kot_items and order_items tables
            $linkedKotItems = \App\Models\KotItem::where('order_item_id', $orderItemId)->get();
            foreach ($linkedKotItems as $linkedKotItem) {
                $linkedKotItem->delete();
            }

            OrderItem::where('id', $orderItemId)->delete();

            // Recalculate all totals (subtotal, taxes, charges, discounts)
            $this->calculateTotal();

            // For existing orders, reload items from database to ensure consistency
            if ($this->orderDetail && $this->orderDetail->id) {
                // Reload order to get latest items and KOTs
                $this->orderDetail->refresh();
                $this->orderDetail->load(['items', 'taxes.tax', 'charges.charge', 'kot.items']);

                // Rebuild cart arrays from database to ensure consistency
                $this->resetCartArrays();
                $this->setupOrderItems();
                // setupOrderItems calls calculateTotal(), so totals are fresh
            }

            // Persist updated totals to order (includes taxes, charges, etc.)
            $this->persistTotalsToOrder();
            return;
        }

        // Early return if not a KOT item
        if (count($parts) < 3 || $parts[0] !== 'kot') {
            $this->calculateTotal();
            // Persist updated totals to order
            $this->persistTotalsToOrder();
            return;
        }

        $kotId = $parts[1];
        $itemId = $parts[2];

        // Check if the item being deleted is a free item from stamp before deleting
        $kotItem = KotItem::where('kot_id', $kotId)->where('id', $itemId)->first();
        $wasFreeItem = $kotItem && ($kotItem->is_free_item_from_stamp ?? false);
        $hadStampDiscount = $kotItem && (($kotItem->discount_amount ?? 0) > 0 || !is_null($kotItem->stamp_rule_id));

        // CRITICAL: Get the linked order_item_id before deleting kot_item
        $linkedOrderItemId = $kotItem ? $kotItem->order_item_id : null;

        // Delete the kot_item
        KotItem::where('kot_id', $kotId)
            ->where('id', $itemId)
            ->delete();

        // CRITICAL: Also delete the linked order_item if it exists
        // This ensures consistency between kot_items and order_items tables
        if ($linkedOrderItemId) {
            OrderItem::where('id', $linkedOrderItemId)->delete();
        }

        // If deleting a free item or item with stamp discount, we may need to adjust stamp discounts
        // But since stamp discounts are applied at item level (amount is already reduced),
        // we just need to recalculate totals

        // Early return if there are still items in the cart
        if (!empty($this->orderItemList)) {
            // Recalculate all totals (subtotal, taxes, charges, discounts)
            $this->calculateTotal();

            // For existing orders, reload items from database to ensure consistency
            if ($this->orderDetail && $this->orderDetail->id) {
                // Reload order to get latest items
                $this->orderDetail->refresh();
                $this->orderDetail->load(['items', 'taxes.tax', 'charges.charge']);

                // Rebuild cart arrays from database to ensure consistency
                $this->resetCartArrays();
                $this->setupOrderItems();
                // setupOrderItems calls calculateTotal(), so totals are fresh
            }

            // Persist updated totals to order (includes taxes, charges, etc.)
            $this->persistTotalsToOrder();
            return;
        }

        $kot = Kot::find($kotId);
        if (!$kot) {
            $this->calculateTotal();
            return;
        }

        $order = $this->orderDetail;
        $kot->delete();

        // Early return if order is not valid
        if (!$order || !($order instanceof Order)) {
            $this->calculateTotal();
            return;
        }

        // Free up table and delete order
        if ($order->table_id) {
            Table::where('id', $order->table_id)->update(['available_status' => 'available']);
        }

        $order->delete();

        $this->orderDetail = null;
        $this->orderID = null;

        $this->alert('success', __('messages.orderDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->redirect(route('pos.index'), navigate: true);
    }

    public function deleteOrderItems($id)
    {
        $orderItem = OrderItem::find($id);

        if ($orderItem) {
            // If deleting a main item (not a free item), also delete associated free items from stamp redemption
            if (!$orderItem->is_free_item_from_stamp) {
                $this->freeLoyaltyAmountRedeem($orderItem);
            }

            // CRITICAL: Do NOT delete kot_items - preserve KOT/kitchen history and audit trail.
        }

        OrderItem::destroy($id);

        if ($this->orderDetail && $this->orderDetail instanceof Order) {
            $this->orderDetail->refresh();

            if ($this->orderDetail->items->count() === 0) {
                $this->deleteOrder($this->orderDetail->id);
                $this->orderDetail = null;
                $this->orderID = null;

                $this->alert('success', __('messages.orderDeleted'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close')
                ]);

                return $this->redirect(route('pos.index'), navigate: true);
            }

            // Rebuild cart arrays from order and recalculate to keep logic consistent
            $this->resetCartArrays();
            $this->setupOrderItems();
            // setupOrderItems calls calculateTotal(), so component totals are fresh

            // Persist updated totals to order
            $this->persistTotalsToOrder();
        }
    }

    private function resetCartArrays()
    {
        $this->orderItemList = [];
        $this->orderItemQty = [];
        $this->orderItemAmount = [];
        $this->orderItemVariation = [];
        $this->itemModifiersSelected = [];
        $this->orderItemModifiersPrice = [];
        $this->orderItemTaxDetails = [];
        $this->itemNotes = [];

        // Reset loyalty points redemption
        if (module_enabled('Loyalty')) {
            $this->resetLoyaltyRedemption();
        }
    }

    private function persistTotalsToOrder()
    {
        if (!$this->orderDetail || !($this->orderDetail instanceof Order)) {
            return;
        }

        // Ensure totals are current
        // calculateTotal should have been called before this method where needed
        $discountAmount = $this->discountAmount ?? 0;
        $totalTaxAmount = $this->totalTaxAmount ?? 0;

        // Calculate service charges total for saving
        $serviceTotal = 0;
        $applicableCharges = $this->getApplicableExtraCharges();
        if ($applicableCharges->isNotEmpty()) {
            foreach ($applicableCharges as $charge) {
                $chargeAmount = $charge->getAmount($this->discountedTotal ?? $this->total);
                $serviceTotal += $chargeAmount;
            }
        }

        // Update order with all calculated values
        Order::where('id', $this->orderDetail->id)->update([
            'sub_total' => round($this->subTotal ?? 0, 2),
            'total' => round($this->total ?? 0, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_tax_amount' => round($totalTaxAmount, 2),
            // Note: Service charges are stored in order_charges table, not directly in orders table
            // But we ensure totals include them
        ]);

        // Refresh local order model and notify UI
        $this->orderDetail = Order::find($this->orderDetail->id);
        $this->orderDetail->load(['items', 'taxes.tax', 'charges.charge']);

        $this->dispatch('refreshOrders');
        $this->dispatch('refreshOrders')->to(\App\Livewire\Order\Orders::class);
        $this->dispatch('refreshPos');
    }

    private function isFreeStampKey(string $key): bool
    {
        if (strpos($key, 'free_stamp_') === 0) {
            return true;
        }
        return isset($this->stampRuleIdByKey[$key]);
    }

    private function getFreeStampLimitsForKey(string $key): array
    {
        $stampRuleId = null;
        if (strpos($key, 'free_stamp_') === 0) {
            $parts = explode('_', $key);
            $stampRuleId = $parts[2] ?? null;
        }
        if (!$stampRuleId) {
            $stampRuleId = $this->stampRuleIdByKey[$key] ?? null;
        }
        if (!$stampRuleId || !module_enabled('Loyalty')) {
            $qty = (int) ($this->orderItemQty[$key] ?? 1);
            return [$qty, $qty, $qty];
        }

        $stampRule = \Modules\Loyalty\Entities\LoyaltyStampRule::find($stampRuleId);
        if (!$stampRule) {
            $qty = (int) ($this->orderItemQty[$key] ?? 1);
            return [$qty, $qty, $qty];
        }

        $eligibleQty = 0;
        foreach ($this->orderItemList as $itemKey => $item) {
            if ($this->isFreeStampKey($itemKey)) {
                continue;
            }
            $menuItemId = null;
            if (isset($this->orderItemVariation[$itemKey])) {
                $menuItemId = $this->orderItemVariation[$itemKey]->menu_item_id ?? null;
            } elseif (isset($this->orderItemList[$itemKey])) {
                $menuItemId = $this->orderItemList[$itemKey]->id ?? null;
            }
            if ((int) $menuItemId === (int) $stampRule->menu_item_id) {
                $eligibleQty += (int) ($this->orderItemQty[$itemKey] ?? 0);
            }
        }

        $restaurantId = restaurant()->id ?? $this->orderDetail?->branch?->restaurant_id;
        $availableStamps = 0;
        if ($restaurantId && $this->customerId) {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $availableStamps = (int) $loyaltyService->getAvailableStamps($restaurantId, $this->customerId, (int) $stampRuleId);
        }

        $stampsRequired = (int) ($stampRule->stamps_required ?? 1);
        $maxByStamps = ($stampsRequired > 0) ? intdiv($availableStamps, $stampsRequired) : 0;
        $maxAllowed = max(1, min($eligibleQty, $maxByStamps));

        return [$maxAllowed, $eligibleQty, $maxByStamps];
    }

    protected function captureDisplayedTotalsSnapshot(): void
    {
        $orderSource = $this->orderDetail instanceof \App\Models\Order ? $this->orderDetail : null;
        if ($orderSource && $orderSource->id && $orderSource->status !== 'draft') {
            $this->saveTotalsSnapshot = [
                'sub_total' => round((float)($orderSource->sub_total ?? 0), 2),
                'total' => round((float)($orderSource->total ?? 0), 2),
                'total_tax_amount' => round((float)($orderSource->total_tax_amount ?? 0), 2),
                'discount_amount' => round((float)($orderSource->discount_amount ?? 0), 2),
                'stamp_discount_amount' => round((float)($orderSource->stamp_discount_amount ?? 0), 2),
                'loyalty_discount_amount' => round((float)($orderSource->loyalty_discount_amount ?? 0), 2),
                'loyalty_points_redeemed' => (int)($orderSource->loyalty_points_redeemed ?? 0),
                'tax_base' => $orderSource->tax_base ?? $this->taxBase,
                'tax_mode' => $orderSource->tax_mode ?? $this->taxMode,
            ];
            return;
        }

        $this->saveTotalsSnapshot = [
            'sub_total' => round((float)($this->subTotal ?? 0), 2),
            'total' => round((float)($this->total ?? 0), 2),
            'total_tax_amount' => round((float)($this->totalTaxAmount ?? 0), 2),
            'discount_amount' => round((float)($this->discountAmount ?? 0), 2),
            'stamp_discount_amount' => round((float)($this->stampDiscountAmount ?? 0), 2),
            'loyalty_discount_amount' => round((float)($this->loyaltyDiscountAmount ?? 0), 2),
            'loyalty_points_redeemed' => (int)($this->loyaltyPointsRedeemed ?? 0),
            'tax_base' => $this->taxBase,
            'tax_mode' => $this->taxMode,
        ];
    }

    protected function forcePersistDisplayedTotals(Order $order): void
    {
        if (!is_array($this->saveTotalsSnapshot)) {
            return;
        }

        Order::where('id', $order->id)->update([
            'sub_total' => $this->saveTotalsSnapshot['sub_total'],
            'total' => $this->saveTotalsSnapshot['total'],
            'discount_amount' => $this->saveTotalsSnapshot['discount_amount'],
            'stamp_discount_amount' => $this->saveTotalsSnapshot['stamp_discount_amount'],
            'loyalty_discount_amount' => $this->saveTotalsSnapshot['loyalty_discount_amount'],
            'loyalty_points_redeemed' => $this->saveTotalsSnapshot['loyalty_points_redeemed'],
            'total_tax_amount' => $this->saveTotalsSnapshot['total_tax_amount'],
            'tax_base' => $this->saveTotalsSnapshot['tax_base'],
            'tax_mode' => $this->saveTotalsSnapshot['tax_mode'],
        ]);
    }

    /**
     * Create or merge a POS cart line into order_items.
     * POS adds each tap as a unique cart key, so the same menu/variation/modifiers
     * can appear multiple times in one save — accumulate qty/amount instead of dropping lines.
     *
     * @param  array<int, true>  $touchedOrderItemIds
     */
    protected function upsertCartLineOrderItem(
        Order $order,
        array $attributes,
        array $modifierIds,
        array &$touchedOrderItemIds
    ): OrderItem {
        $menuItemId = $attributes['menu_item_id'] ?? null;
        $variationId = $attributes['menu_item_variation_id'] ?? null;
        $isFreeItemFromStamp = (bool) ($attributes['is_free_item_from_stamp'] ?? false);
        $stampRuleId = $attributes['stamp_rule_id'] ?? null;
        $quantity = \App\Support\PosQuantity::normalize($attributes['quantity'] ?? 1);
        $amount = (float) ($attributes['amount'] ?? 0);
        $taxAmount = array_key_exists('tax_amount', $attributes) ? $attributes['tax_amount'] : null;

        $normalizedModifierIds = array_values(array_unique(array_filter(
            array_map('intval', $modifierIds),
            fn ($id) => $id > 0
        )));
        sort($normalizedModifierIds);

        $query = $order->items()->where('menu_item_id', $menuItemId);
        if ($variationId) {
            $query->where('menu_item_variation_id', $variationId);
        } else {
            $query->whereNull('menu_item_variation_id');
        }

        if ($isFreeItemFromStamp) {
            $query->where('is_free_item_from_stamp', true)
                ->where('stamp_rule_id', $stampRuleId);
        } else {
            $query->where(function ($q) {
                $q->where('is_free_item_from_stamp', false)
                    ->orWhereNull('is_free_item_from_stamp');
            });
        }

        $existingItem = null;
        foreach ($query->with('modifierOptions:id')->get() as $candidate) {
            $candidateModifierIds = $candidate->modifierOptions
                ? $candidate->modifierOptions->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->toArray()
                : [];
            if ($candidateModifierIds === $normalizedModifierIds) {
                $existingItem = $candidate;
                break;
            }
        }

        if ($existingItem) {
            if (isset($touchedOrderItemIds[$existingItem->id])) {
                $update = [
                    'quantity' => \App\Support\PosQuantity::normalize((float) $existingItem->quantity + $quantity),
                    'amount' => (float) $existingItem->amount + $amount,
                ];
                if ($taxAmount !== null) {
                    $update['tax_amount'] = (float) ($existingItem->tax_amount ?? 0) + (float) $taxAmount;
                }
                if (empty($existingItem->note) && !empty($attributes['note'])) {
                    $update['note'] = $attributes['note'];
                }
                $existingItem->update($update);
            } else {
                $existingItem->update($attributes);
                $touchedOrderItemIds[$existingItem->id] = true;
            }
            $existingItem->modifierOptions()->sync($normalizedModifierIds);

            return $existingItem;
        }

        $orderItem = OrderItem::create($attributes);
        $orderItem->modifierOptions()->sync($normalizedModifierIds);
        $touchedOrderItemIds[$orderItem->id] = true;

        return $orderItem;
    }

    protected function recalculateTotalsForKotOrderWithoutModule(Order $order): void
    {
        $order->refresh();
        $order->load(['kot.items.menuItem', 'kot.items.menuItemVariation', 'kot.items.modifierOptions', 'taxes.tax', 'charges.charge', 'items']);

        $subTotal = 0.0;
        foreach ($order->kot as $kot) {
            foreach ($kot->items->where('status', '!=', 'cancelled') as $item) {
                if ($item->amount !== null) {
                    $subTotal += (float)$item->amount;
                    continue;
                }
                $itemPrice = $item->menuItemVariation?->price ?? $item->menuItem->price ?? 0;
                $modifierPrice = $item->modifierOptions?->sum('price') ?? 0;
                $subTotal += ($itemPrice + $modifierPrice) * $item->quantity;
            }
        }

        $discountAmount = (float)($order->discount_amount ?? 0);
        $loyaltyDiscount = (float)($order->loyalty_discount_amount ?? 0);
        $discountedBase = $subTotal - $discountAmount - $loyaltyDiscount;

        $charges = collect($order->charges ?? [])
            ->map(fn ($chargeRelation) => $chargeRelation->charge ?? $chargeRelation)
            ->filter();

        $chargeTotals = RestaurantCharge::calculateTotals($charges, $discountedBase);
        $serviceTotal = $chargeTotals['total'];

        $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? true;
        $taxBase = RestaurantCharge::resolveTaxBase($discountedBase, $charges, $includeChargesInTaxBase);
        $taxBase = max(0, (float)$taxBase);

        $taxAmount = 0.0;
        if (($order->tax_mode ?? $this->taxMode) === 'order') {
            $taxesToUse = $order->taxes && $order->taxes->count() > 0
                ? $order->taxes->map(fn($orderTax) => $orderTax->tax)->filter()
                : Tax::all();
            foreach ($taxesToUse as $tax) {
                if ($tax) {
                    $taxAmount += ($tax->tax_percent / 100) * $taxBase;
                }
            }
        } else {
            $taxAmount = (float)($order->items->sum('tax_amount') ?? 0);
        }

        $total = $discountedBase + $serviceTotal;
        if (($order->tax_mode ?? $this->taxMode) === 'order') {
            $total += $taxAmount;
        } else {
            $isInclusive = $this->restaurant->tax_inclusive ?? false;
            if (!$isInclusive) {
                $total += $taxAmount;
            }
        }

        $total += (float)($order->tip_amount ?? 0);
        $total += (float)($order->delivery_fee ?? 0);

        Order::where('id', $order->id)->update([
            'sub_total' => round($subTotal, 2),
            'total' => round($total, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_tax_amount' => round($taxAmount, 2),
            'tax_base' => round($taxBase, 2),
            'tax_mode' => $order->tax_mode ?? $this->taxMode,
            'loyalty_points_redeemed' => (int)($order->loyalty_points_redeemed ?? 0),
            'loyalty_discount_amount' => (float)($order->loyalty_discount_amount ?? 0),
            'stamp_discount_amount' => (float)($order->stamp_discount_amount ?? 0),
        ]);

        $order->refresh();
        $this->subTotal = $order->sub_total;
        $this->total = $order->total;
        $this->totalTaxAmount = $order->total_tax_amount;
        $this->taxBase = $order->tax_base;
    }

    public function deleteOrder($id)
    {
        $order = Order::find($id);

        if (!$order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        if ($order->table_id) {
            Table::where('id', $order->table_id)->update(['available_status' => 'available']);
        }

        // Delete associated KOT records
        $order->kot()->delete();

        $order->delete();

        $this->alert('success', __('messages.orderDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        return $this->redirect(route('pos.index'), navigate: true);
    }

    public function addQty($id)
    {
        if (($this->orderID && !user_can('Update Order')) || (!$this->orderID && !user_can('Create Order'))) {
            return;
        }

        // Force consistent order id for stamp recalculation
        if (!$this->orderID && $this->orderDetail && $this->orderDetail->id) {
            $this->orderID = $this->orderDetail->id;
        }

        // If this is a free stamp item, cap qty by eligible items and available stamps
        if ($this->isFreeStampKey($id) && module_enabled('Loyalty')) {
            [$maxAllowed, $eligibleQty, $maxByStamps] = $this->getFreeStampLimitsForKey($id);
            $current = (int)($this->orderItemQty[$id] ?? 1);
            if ($current >= $maxAllowed) {
                $reason = $maxByStamps < $eligibleQty ? __('loyalty::app.insufficientStamps') : __('loyalty::app.maxLimitReached');
                $this->alert('info', $reason, ['toast' => true, 'position' => 'top-end']);
                $this->orderItemQty[$id] = $maxAllowed;
            } else {
                $this->orderItemQty[$id] = min($current + 1, max(1, $maxAllowed));
            }
            $this->orderItemAmount[$id] = 0;
            $this->calculateTotal();
            return;
        }

        // Update table activity when changing quantities
        if ($this->tableId) {
            $table = Table::find($this->tableId);
            $table?->updateActivity(user()->id);
        }

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

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$id])) {
                $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
            if (isset($this->orderItemList[$id])) {
                $this->orderItemList[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
        }

        $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
        $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));

        // Skip stamp/points redemption triggers on KOT detail page
        if (!$this->isOrderDetailKotView()) {
            // Check for automatic stamp redemption when item quantity is increased
            // This handles the case when an item is removed and then re-added
            $this->checkAndAutoRedeemStampsForItem($id);
        }

        $this->calculateTotal();
    }

    public function subQty($id)
    {
        if (($this->orderID && !user_can('Update Order')) || (!$this->orderID && !user_can('Create Order'))) {
            return;
        }

        // Force consistent order id for stamp recalculation
        if (!$this->orderID && $this->orderDetail && $this->orderDetail->id) {
            $this->orderID = $this->orderDetail->id;
        }

        // If this is a free stamp item, allow decrease but keep within 1..max
        if ($this->isFreeStampKey($id) && module_enabled('Loyalty')) {
            [$maxAllowed] = $this->getFreeStampLimitsForKey($id);
            $current = (int)($this->orderItemQty[$id] ?? 1);
            $this->orderItemQty[$id] = max(1, min($current - 1, max(1, $maxAllowed)));
            $this->orderItemAmount[$id] = 0;
            $this->calculateTotal();
            return;
        }

        // Update table activity when changing quantities
        if ($this->tableId) {
            $table = Table::find($this->tableId);
            $table?->updateActivity(user()->id);
        }

        $this->orderItemQty[$id] = (isset($this->orderItemQty[$id]) && $this->orderItemQty[$id] > 1) ? ($this->orderItemQty[$id] - 1) : 1;

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$id])) {
                $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
            if (isset($this->orderItemList[$id])) {
                $this->orderItemList[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
        }

        $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
        $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));

        // Skip stamp/points redemption triggers on KOT detail page
        if (!$this->isOrderDetailKotView()) {
            // Re-evaluate stamp redemption when quantity decreases
            $this->checkAndAutoRedeemStampsForItem($id);
        }
        $this->calculateTotal();
    }

    public function calculateTotal()
    {
        $this->total = 0;
        $this->subTotal = 0;
        $this->totalTaxAmount = 0;
        $this->taxBase = 0;

        // If cart is empty and status was billed, reset to idle for new order
        if (empty($this->orderItemList) && $this->customerDisplayStatus === 'billed') {
            $this->customerDisplayStatus = 'idle';
        }

        if (is_array($this->orderItemAmount)) {
            // Calculate item taxes first for proper subtotal calculation
            if ($this->taxMode === 'item') {
                $this->updateOrderItemTaxDetails();
            }

            foreach ($this->orderItemAmount as $key => $value) {
                // Skip free items from stamp redemption (they have amount = 0 anyway, but let's be explicit)
                $isFreeItem = strpos($key, 'free_stamp_') === 0;
                if (!$isFreeItem) {
                    $this->total += $value;

                    // For inclusive taxes, subtract tax from subtotal
                    if ($this->taxMode === 'item' && isset($this->orderItemTaxDetails[$key])) {
                        $taxDetail = $this->orderItemTaxDetails[$key];
                        $isInclusive = restaurant()->tax_inclusive ?? false;

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
        }

        $this->discountedTotal = $this->total;

        // Update loyalty values when subtotal changes (if customer is selected and no points redeemed)
        // Don't auto-adjust redeemed points - let user manually redeem via button
        if ($this->customerId && module_enabled('Loyalty') && $this->loyaltyPointsRedeemed == 0) {
            $this->updateLoyaltyValues();
        }

        // Apply discounts - FOLLOW THE SAME PROCESS AS REGULAR DISCOUNT
        // Clear regular discount if loyalty points are redeemed
        if ($this->loyaltyPointsRedeemed > 0) {
            $this->discountType = null;
            $this->discountValue = null;
            $this->discountAmount = 0;

            // Recalculate and apply loyalty discount
            if ($this->customerId && module_enabled('Loyalty') && $this->loyaltyPointsRedeemed > 0) {
                $this->recalculateLoyaltyDiscount();
            }
        } else {
            // Apply regular discounts (only if no loyalty discount)
            if ($this->discountValue > 0 && $this->discountType) {
                if ($this->discountType === 'percent') {
                    $this->discountAmount = round(($this->subTotal * $this->discountValue) / 100, 2);
                } elseif ($this->discountType === 'fixed') {
                    $this->discountAmount = min($this->discountValue, $this->subTotal);
                }

                $this->total -= $this->discountAmount;
            }
        }
        $this->discountedTotal = $this->total;

        // Set discountedTotal AFTER discount is applied (for tax/charge calculations)
        $this->discountedTotal = $this->total;

        // Step 2: Calculate service charges on discountedTotal
        $applicableCharges = $this->getApplicableExtraCharges();
        $isInclusive = restaurant()->tax_inclusive ?? false;

        $chargeBase = $this->discountedTotal;
        if ($isInclusive && $this->taxMode === 'item') {
            $chargeBase = max(
                0,
                $this->subTotal - ($this->discountAmount ?? 0) - ($this->loyaltyDiscountAmount ?? 0)
            );
        }

        // Apply extra charges
        $serviceTotal = 0;
        $taxableServiceTotal = 0;
        if (!empty($this->orderItemAmount) && $applicableCharges->isNotEmpty()) {
            $chargeTotals = RestaurantCharge::calculateTotals($applicableCharges, $chargeBase);
            $serviceTotal = $chargeTotals['total'];
            $taxableServiceTotal = $chargeTotals['taxable_total'];
            if (!$isInclusive) {
                $this->total += $serviceTotal;
            }
        }

        // Step 3: Calculate tax_base based on setting
        $includeChargesInTaxBase = restaurant()->include_charges_in_tax_base ?? true;
        $this->taxBase = RestaurantCharge::resolveTaxBase(
            $this->discountedTotal,
            $applicableCharges,
            $includeChargesInTaxBase
        );

        // Calculate item taxes AFTER discount for proper tax calculation on discounted amounts
        if ($this->taxMode === 'item') {
            $this->updateOrderItemTaxDetails();
        }

        // Step 4: Calculate taxes on tax_base
        $this->recalculateTaxTotals($this->taxBase);

        // Item-tax mode only — GST on taxable service charges (order-tax uses taxBase above).
        if ($taxableServiceTotal > 0 && $this->taxMode === 'item') {
            $effectiveTaxRate = 0.0;
            $rateBase = $this->subTotal;
            if ($isInclusive) {
                $rateBase = max(
                    0,
                    $this->subTotal - ($this->discountAmount ?? 0) - ($this->loyaltyDiscountAmount ?? 0)
                );
            }
            $effectiveTaxRate = $rateBase > 0 ? ($this->totalTaxAmount / $rateBase) : 0.0;

            $chargeTaxAmount = $taxableServiceTotal * $effectiveTaxRate;
            if ($chargeTaxAmount > 0) {
                $this->totalTaxAmount += $chargeTaxAmount;
            }
        }

        // Add tip and delivery fees (ensure numeric types)
        $tipAmount = (float) ($this->tipAmount ?: 0);
        $deliveryFee = (float) ($this->deliveryFee ?: 0);

        if ($tipAmount > 0) {
            $this->total += $tipAmount;
        }

        if ($deliveryFee > 0) {
            $this->total += $deliveryFee;
        }

        // Final total: inclusive reverse-solves x + service + GST = gross; exclusive adds on top.
        $inclusiveChargeAmounts = [];
        if ($isInclusive) {
            $grossItemTotal = array_sum($this->orderItemAmount ?? []);
            $inclusiveGross = $grossItemTotal;
            if ($this->subTotal > 0) {
                $netAfterDiscounts = max(
                    0,
                    $this->subTotal
                        - ($this->discountAmount ?? 0)
                        - ($this->loyaltyDiscountAmount ?? 0)
                );
                $inclusiveGross = $grossItemTotal * ($netAfterDiscounts / $this->subTotal);
            } else {
                $inclusiveGross -= ($this->discountAmount ?? 0);
            }

            $taxPercentTotal = $this->taxMode === 'order'
                ? (float) $this->taxes->sum('tax_percent')
                : (float) collect($this->orderItemTaxDetails ?? [])->pluck('tax_percent')->filter()->unique()->sum();

            $reversed = RestaurantCharge::reverseInclusiveBreakdown(
                $inclusiveGross,
                $applicableCharges,
                $taxPercentTotal,
                (bool) ($includeChargesInTaxBase ?? true)
            );

            $this->subTotal = round($reversed['sub_total'], 3);
            $this->taxBase = round($reversed['tax_base'], 3);
            $this->totalTaxAmount = round($reversed['total_tax_amount'], 3);
            $serviceTotal = $reversed['service_total'];
            $inclusiveChargeAmounts = $reversed['charge_amounts'];
            $finalTotal = $inclusiveGross;
        } else {
            $finalTotal = $this->discountedTotal + $serviceTotal + $this->totalTaxAmount;
        }

        // Add tip and delivery (cast to float to avoid int + string errors)
        $finalTotal += $tipAmount + $deliveryFee;
        $this->total = round($finalTotal, 2);

        // Calculate tax and charge amounts for display
        $cartHasItems = ! empty($this->orderItemList);
        $taxesForDisplay = $cartHasItems
            ? $this->taxes->map(function ($tax) {
                $amount = (($tax->tax_percent / 100) * $this->taxBase);

                return [
                    'name' => $tax->tax_name,
                    'percent' => $tax->tax_percent,
                    'amount' => $amount,
                ];
            })->toArray()
            : [];
        $chargesForDisplay = $cartHasItems
            ? $applicableCharges->map(function ($charge) use ($inclusiveChargeAmounts) {
                return [
                    'name' => $charge->charge_name ?? $charge->name,
                    'amount' => $inclusiveChargeAmounts[$charge->id]
                        ?? $charge->getAmount($this->subTotal),
                ];
            })->toArray()
            : [];

        $qrCodeImageUrl = restaurant()->customerDisplayQrCodeImageUrl();

        $customerDisplayData = \App\Support\CustomerDisplayPayload::normalize([
            'order_number' => $this->orderNumber,
            'formatted_order_number' => $this->formattedOrderNumber,
            'items' => $this->getCustomerDisplayItems(),
            'sub_total' => $this->subTotal,
            'discount' => $this->discountAmount ?? 0,
            'total' => $this->total,
            'taxes' => $taxesForDisplay,
            'extra_charges' => $chargesForDisplay,
            'tip' => $this->tipAmount,
            'delivery_fee' => $this->deliveryFee,
            'order_type' => $this->orderType,
            'status' => $this->customerDisplayStatus ?? 'idle',
            'cash_due' => ($this->customerDisplayStatus ?? null) === 'billed' ? $this->total : null,
            'qr_code_image_url' => $qrCodeImageUrl,
        ]);

        $userId = auth()->id();
        $cacheKey = 'customer_display_cart_user_' . $userId;
        Cache::put($cacheKey, $customerDisplayData, now()->addMinutes(30));

        // Broadcast customer display update if Pusher is enabled
        if (pusherSettings()->is_enabled_pusher_broadcast) {
            broadcast(new \App\Events\CustomerDisplayUpdated($customerDisplayData, $userId));
        }

        // Optionally, still dispatch browser event
        $this->dispatch('orderUpdated', [
            'order_number' => $this->orderNumber,
            'formatted_order_number' => $this->formattedOrderNumber,
            'items' => $this->getCustomerDisplayItems(),
            'sub_total' => $this->subTotal,
            'discount' => $this->discountAmount ?? 0,
            'total' => $this->total,
        ]);
    }

    private function getApplicableExtraCharges()
    {
        $orderType = $this->orderTypeSlug ?? $this->orderType;

        return collect($this->extraCharges ?? [])->filter(function ($charge) use ($orderType) {
            $allowedTypes = $charge->order_types ?? [];

            return empty($allowedTypes) || in_array($orderType, $allowedTypes);
        });
    }


    private function recalculateTaxTotals($taxBase = null)
    {
        $this->totalTaxAmount = 0;

        if ($this->taxMode === 'order') {
            // Order-level tax: calculate on tax_base (net + service_total)
            $baseForTax = $taxBase ?? $this->discountedTotal;

            foreach ($this->taxes as $tax) {
                $taxAmount = ($tax->tax_percent / 100) * $baseForTax;
                $this->totalTaxAmount += $taxAmount;
                $this->total += $taxAmount;
            }
        } elseif ($this->taxMode === 'item' && !empty($this->orderItemAmount)) {
            // Item-based taxation - taxes are already calculated in calculateTotal()
            $totalInclusiveTax = 0;
            $totalExclusiveTax = 0;
            $isInclusive = restaurant()->tax_inclusive ?? false;

            // Calculate total tax amounts
            foreach ($this->orderItemTaxDetails as $itemTaxDetail) {
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

    public function addDiscounts()
    {
        // Prevent regular discount if loyalty points are redeemed
        if ($this->loyaltyPointsRedeemed > 0) {
            $this->alert('error', __('loyalty::app.cannotAddDiscountWithLoyaltyPoints'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            $this->showDiscountModal = false;
            return;
        }

        $this->validate([
            'discountValue' => 'required|numeric|min:0',
            'discountType' => 'required|in:fixed,percent',
        ]);

        if ($this->discountType === 'percent' && $this->discountValue > 100) {
            $this->alert('error', __('messages.discountPercentError'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        $order = $this->tableOrderID ? $this->tableOrder->activeOrder : $this->orderDetail;

        if ($order) {
            $order->update([
                'discount_type' => $this->discountType,
                'discount_value' => $this->discountValue,
                'discount_amount' => $this->discountAmount,
                'total' => $this->total,
            ]);

            $this->orderDetail->refresh();

            $this->resetCartArrays();
            $this->setupOrderItems();

            $this->persistTotalsToOrder();
        }else{
            $this->calculateTotal();
        }

        $this->showDiscountModal = false;
    }

    public function removeCurrentDiscount()
    {
        $order = $this->tableOrderID ? $this->tableOrder->activeOrder : $this->orderDetail;

        // Reset discount properties
        $this->discountType = null;
        $this->discountValue = null;
        $this->discountAmount = null;

        if ($order) {
            $order->update([
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => null,
            ]);

            $this->orderDetail->refresh();

            $this->resetCartArrays();
            $this->setupOrderItems();

            $this->persistTotalsToOrder();
        }else{
            $this->calculateTotal();
        }
    }

    public function removeExtraCharge($chargeId, $orderType)
    {
        $order = $this->tableOrderID ? $this->tableOrder->activeOrder : $this->orderDetail;

        if ($order) {
            $extraCharge = $this->extraCharges->firstWhere('id', $chargeId);
            if ($extraCharge) {
                $order->extraCharges()->detach($chargeId);
                $this->total -= $extraCharge->getAmount($this->discountedTotal);
                $order->update(['total' => $this->total]);
            }
        }

        $this->extraCharges = $this->extraCharges->filter(function ($charge) use ($chargeId) {
            return $charge->id != $chargeId;
        });

        $this->calculateTotal();
    }

    public function saveOrder($action, $secondAction = null, $thirdAction = null)
    {
        if ($action !== 'cancel') {
            $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, branch());

            if (!($availability['is_open'] ?? true)) {
                $this->restaurantClosedMessage = RestaurantAvailabilityService::getMessage($availability, $this->restaurant);
                $this->showRestaurantClosedBanner = true;
                $this->alert('error', $this->restaurantClosedMessage, [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }
        }

        // Check if table is locked by another user before saving order
        if ($this->tableId && $this->orderType === 'dine_in') {
            $table = Table::find($this->tableId);
            if ($table && !$table->canBeAccessedByUser(user()->id)) {
                $session = $table->tableSession;
                $lockedByUser = $session?->lockedByUser;
                $lockedUserName = $lockedByUser?->name ?? 'Another user';

                $this->alert('error', __('messages.tableHandledByUser', ['user' => $lockedUserName, 'table' => $table->table_code]), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }
        }

        // Proceed with order saving (loyalty check happens on customer selection)
        $this->executeSaveOrder($action, $secondAction, $thirdAction);
    }

    protected function executeSaveOrder($action, $secondAction = null, $thirdAction = null)
    {
        // Capture displayed totals before any processing
        $this->captureDisplayedTotalsSnapshot();

        // Check if table is locked by another user before saving order
        if ($this->tableId && $this->orderType === 'dine_in') {
            $table = Table::find($this->tableId);
            if ($table && !$table->canBeAccessedByUser(user()->id)) {
                $session = $table->tableSession;
                $lockedByUser = $session?->lockedByUser;
                $lockedUserName = $lockedByUser?->name ?? 'Another user';

                $this->alert('error', __('messages.tableHandledByUser', ['user' => $lockedUserName, 'table' => $table->table_code]), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }
        }

        // Validate pickup date/time for pickup orders - ensure it's not in the past (only for today's date)
        if ($this->orderType === 'pickup' && $action !== 'cancel' && $this->pickupDate && $this->pickupTime) {
            // Store original values to detect if they were adjusted
            $originalPickupDate = $this->pickupDate;
            $originalPickupTime = $this->pickupTime;

            $this->updateDeliveryDateTime();

            // If values were adjusted, it means the original time was in the past (for today's date)
            if ($this->pickupDate !== $originalPickupDate || $this->pickupTime !== $originalPickupTime) {
                $this->alert('error', __('messages.pleaseSelectFutureTime'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
                return;
            }
        }

        $this->showErrorModal = true;
        $this->calculateTotal();

        if ($action !== 'cancel' && $this->isCartIngredientStockBlocked()) {
            return;
        }

        // Normalize noOfPax - ensure it's an integer or null, not empty string
        if (empty($this->noOfPax) || $this->noOfPax === '' || $this->noOfPax === '0') {
            $this->noOfPax = $this->orderType === 'dine_in' ? 1 : null;
        } else {
            $this->noOfPax = (int) $this->noOfPax;
        }

        if ($this->orderType === 'dine_in' && $this->tableId && $this->noOfPax) {
            $tbl = Table::select('id', 'table_code', 'seating_capacity')->find($this->tableId);
            if ($tbl) {
                $excludeOrderId = $this->orderID ? (int) $this->orderID : null;
                $remaining = $tbl->remainingSeats($excludeOrderId);
                if ($remaining !== null && (int) $this->noOfPax > $remaining) {
                    $this->alert('warning', __('messages.paxExceedsRemainingSeats', [
                        'pax' => $this->noOfPax,
                        'remaining' => $remaining,
                        'table' => $tbl->table_code,
                    ]), [
                        'toast' => true,
                        'position' => 'top-end',
                    ]);
                    $this->noOfPax = max($remaining, 1);
                }
            }
        }

        $wasDraft = false;

        $rules = [
            'selectDeliveryExecutive' => Rule::requiredIf($action !== 'cancel' && $this->orderType === 'delivery' && $this->selectedDeliveryApp === 'default'),
            'orderItemList' => 'required',
            'deliveryFee' => 'nullable|numeric|min:0',
        ];

        if (!$this->orderID && !$this->tableOrderID) {
            $rules['selectWaiter'] = 'required_if:orderType,dine_in';
        }

        // Require stay selection for room service orders unless room is already linked (e.g. room QR).
        if ($this->isRoomServiceOrder() && $action !== 'cancel' && ! $this->hasRoomServiceRoomContext()) {
            $rules['selectedStayId'] = 'required|exists:hotel_stays,id';
        } elseif ($this->isRoomServiceOrder() && $this->selectedStayId) {
            $rules['selectedStayId'] = 'exists:hotel_stays,id';
        }

        $messages = [
            'noOfPax.required_if' => __('messages.enterPax'),
            'tableNo.required_if' => __('messages.setTableNo'),
            'selectWaiter.required_if' => __('messages.selectWaiter'),
            'orderItemList.required' => __('messages.orderItemRequired'),
            'selectedStayId.required' => __('hotel::modules.roomService.selectStayRequired'),
            'selectedStayId.exists' => __('hotel::modules.roomService.invalidStay'),
        ];

        $this->validate($rules, $messages);

        switch ($action) {
            case 'bill':
                $successMessage = __('messages.billedSuccess');
                $status = 'billed';
                $tableStatus = 'running';
                break;

            case 'kot':
                $successMessage = __('messages.kotGenerated');
                $status = 'kot';
                $tableStatus = 'running';
                break;

            case 'draft':
                $successMessage = __('messages.orderSavedAsDraft');
                $status = 'draft';
                $tableStatus = 'available';
                break;

            case 'cancel':
                $successMessage = __('messages.orderCanceled');
                $status = 'canceled';
                $tableStatus = 'available';
                break;
        }

        // Get order type name if not already set
        $orderTypeName = $this->orderType;
        if ($this->orderTypeId) {
            $orderType = OrderType::select('order_type_name')->find($this->orderTypeId);
            $orderTypeName = $orderType->order_type_name ?? $orderTypeName;
        }

        if ((!$this->tableOrderID && !$this->orderID) || ($this->tableOrderID && !$this->tableOrder->activeOrder)) {

            if ($this->tableId && $this->orderType === 'dine_in') {
                $tableForNewOrder = Table::find($this->tableId);
                if ($tableForNewOrder) {
                    $paxToFit = $this->noOfPax ? (int) $this->noOfPax : 1;
                    if ($tableForNewOrder->isSeatFull() || !$tableForNewOrder->canAcceptPax($paxToFit)) {
                        $remaining = (int) ($tableForNewOrder->remainingSeats() ?? 0);
                        $this->alert('error', $remaining <= 0
                            ? __('messages.tableSeatsFull', ['table' => $tableForNewOrder->table_code])
                            : __('messages.paxExceedsRemainingSeats', [
                                'pax' => $paxToFit,
                                'remaining' => $remaining,
                                'table' => $tableForNewOrder->table_code,
                            ]), [
                            'toast' => true,
                            'position' => 'top-end',
                        ]);
                        return;
                    }
                }
            }

            // For draft orders, don't generate order number
            $orderNumberData = null;
            if ($action !== 'draft') {
                $orderNumberData = Order::generateOrderNumber(branch());
            }

            $table = Table::find($this->tableId);
            $reservationId = $table?->activeReservation?->id;

            // Check if there's an active reservation and show confirmation modal
            // Skip reservation check for draft orders
            if ($reservationId && $this->orderType === 'dine_in' && !$this->isSameCustomer && !$this->intendedOrderAction && $action !== 'draft') {
                $this->reservationId = $reservationId;
                $this->reservationCustomer = $table->activeReservation->customer;
                $this->reservation = $table->activeReservation;
                $this->showReservationModal = true;
                $this->intendedOrderAction = $action; // Store the intended action
                return;
            }

            if (module_enabled('Loyalty')) {
                $this->ensureTotalsIncludeLoyaltyBeforeUpdate();
            }

            $orderData = [
                'order_number' => $action === 'draft' ? null : ($orderNumberData['order_number'] ?? null),
                'formatted_order_number' => $action === 'draft' ? null : ($orderNumberData['formatted_order_number'] ?? null),
                'date_time' => now(),
                'table_id' => $this->tableIdForOrderSave(),
                'number_of_pax' => $this->noOfPax !== null && $this->noOfPax !== '' ? (int) $this->noOfPax : ($this->orderType === 'dine_in' ? 1 : null),
                'discount_type' => $this->loyaltyPointsRedeemed > 0 ? null : $this->discountType,
                'discount_value' => $this->loyaltyPointsRedeemed > 0 ? null : $this->discountValue,
                'discount_amount' => $this->loyaltyPointsRedeemed > 0 ? 0 : $this->discountAmount,
                'waiter_id' => $this->selectWaiter,
                'sub_total' => $this->subTotal,
                'total' => $this->total,
                'total_tax_amount' => $this->totalTaxAmount,
                'order_type' => $this->orderType,
                'order_type_id' => $this->orderTypeId,
                'custom_order_type_name' => $orderTypeName,
                'pickup_date' => $this->orderType === 'pickup' ? $this->deliveryDateTime : null,
                'delivery_executive_id' => ($this->orderType == 'delivery' ? $this->selectDeliveryExecutive : null),
                'delivery_fee' => ($this->orderType == 'delivery' ? $this->deliveryFee : 0),
                'delivery_app_id' => ($this->orderType == 'delivery' ? $this->normalizeDeliveryAppId() : null),
                'status' => $status,
                'order_status' => $this->orderStatus ?? 'confirmed',
                'placed_via' => 'pos',
                'tax_base' => $this->taxBase,
                'tax_mode' => $this->taxMode,
                'order_note' => $this->orderNote,
                'reservation_id' => $this->isSameCustomer ? $this->reservationId : null,
                'customer_id' => $this->isSameCustomer ? $this->reservationCustomer->id : $this->customerId,
                'pos_machine_id' => (module_enabled('MultiPOS') && function_exists('pos_machine_id')) ? pos_machine_id() : null,
            ];

            $orderData = array_merge($orderData, $this->buildRoomServiceOrderContext());

            // If stamp discount was already applied to an item, save it to order
            // This prevents the service from recalculating and double-applying the discount
            if (module_enabled('Loyalty')) {
                $orderData += $this->getLoyaltyOrderData();
                $this->applyStampDiscountToOrderData($orderData);
            }

            if (user() && in_array($status, ['kot', 'billed'], true)) {
                $orderData['added_by'] = user()->id;
            }

            // Create order first (we need the order ID for redeemPoints and redeemStamps)
            $order = Order::create($orderData);

            // CRITICAL: Redeem stamps IMMEDIATELY after order creation (before points)
            // This is disabled now - redemption happens after items are created
            // For ALL orders, stamps/points will be redeemed after items are created
            if (false && $this->selectedStampRuleId && $order->customer_id && $this->isStampsEnabledForPOS() && $status === 'kot') {
                $this->redeemStampsAfterOrderCreation($order, $status);
            }

            // CRITICAL: Deduct loyalty points IMMEDIATELY after order creation
            // This is disabled now - redemption happens after items are created
            // For ALL orders, points will be redeemed after items are created
            if (false && $this->loyaltyPointsRedeemed > 0 && $this->loyaltyDiscountAmount > 0 && $order->customer_id && $this->isPointsEnabledForPOS() && $status === 'kot') {
                $this->redeemLoyaltyPointsAfterOrderCreation($order, $status);
            }

            if (!empty($this->extraCharges)) {
                // Deduplicate charges by ID before saving
                $uniqueCharges = collect($this->extraCharges)->unique('id');
                $chargesData = $uniqueCharges
                    ->map(fn($charge) => [
                        'charge_id' => $charge->id,
                    ])->toArray();

                $order->charges()->createMany($chargesData);
            }

            // Reset reservation properties after order creation
            $this->resetReservationProperties();

            // Delete orders from merged tables after ANY successful save (including drafts)
            // This ensures merged source orders are removed whether the new order is draft, KOT, or billed
            if (!empty($this->ordersToDeleteAfterMerge)) {
                $this->deleteMergedTableOrders();
            }
        } else {

            if ($this->orderID) {
                $this->orderDetail = Order::find($this->orderID);
            }

            $order = ($this->tableOrderID ? $this->tableOrder->activeOrder : $this->orderDetail);

            // Load room service context if applicable (for existing orders)
            if ($order && $this->isRoomServiceOrder()) {
                if ($order->context_id && ! $this->selectedStayId) {
                    $this->selectedStayId = $order->context_id;
                    $this->billTo = $order->bill_to ?? 'POST_TO_ROOM';
                }

                if ($order->hotel_room_id && ! $this->hotelRoomId) {
                    $this->hotelRoomId = $order->hotel_room_id;
                }
            }

            // Store original status before update to check if converting from draft
            $wasDraft = $order->status === 'draft';

            // If converting from draft to KOT/Bill, generate order number
            $orderNumberData = null;
            if ($wasDraft && $action !== 'draft' && !$order->order_number) {
                $orderNumberData = Order::generateOrderNumber(branch());
            }

            if (module_enabled('Loyalty')) {
                $this->ensureTotalsIncludeLoyaltyBeforeUpdate();
            }

            // Check if loyalty points were already redeemed before update
            $existingRedeemedPoints = $order->loyalty_points_redeemed ?? 0;

            $updateData = [
                'date_time' => now(),
                'order_type' => $this->orderType,
                'order_type_id' => $this->orderTypeId,
                'custom_order_type_name' => $orderTypeName,
                'delivery_executive_id' => ($this->orderType == 'delivery' ? $this->selectDeliveryExecutive : null),
                'number_of_pax' => $this->noOfPax !== null && $this->noOfPax !== '' ? (int) $this->noOfPax : ($this->orderType === 'dine_in' ? 1 : null),
                'waiter_id' => $this->selectWaiter,
                'pickup_date' => $this->orderType === 'pickup' ? $this->deliveryDateTime : null,
                'table_id' => $this->tableIdForOrderSave($order),
                'sub_total' => $this->subTotal,
                'total' => $this->total,
                'total_tax_amount' => $this->totalTaxAmount,
                'delivery_fee' => ($this->orderType == 'delivery' ? $this->deliveryFee : 0),
                'delivery_app_id' => ($this->orderType == 'delivery' ? $this->normalizeDeliveryAppId() : null),
                'status' => $status,
                'order_status' => $this->orderStatus ?? 'confirmed',
                'customer_id' => $this->customerId ?? $order->customer_id,
                'discount_type' => $this->loyaltyPointsRedeemed > 0 ? null : $this->discountType,
                'discount_value' => $this->loyaltyPointsRedeemed > 0 ? null : $this->discountValue,
                'discount_amount' => $this->loyaltyPointsRedeemed > 0 ? 0 : $this->discountAmount,
                'tax_base' => $this->taxBase,
            ];

            $updateData = array_merge($updateData, $this->buildRoomServiceOrderContext($order));

            // Only include total if loyalty points are NOT being redeemed
            // If loyalty points ARE being redeemed, redemption will calculate and save the correct total
            if (module_enabled('Loyalty')) {
                // For existing orders creating a new KOT, preserve loyalty/stamp values from DB
                if ($status == 'kot' && $this->orderID) {
                    $this->appendLoyaltyFieldsToOrderUpdate($order, $updateData);
                } else {
                    $updateData += $this->getLoyaltyOrderData();
                }
            }

            if ($this->loyaltyPointsRedeemed == 0 || $existingRedeemedPoints > 0) {
                $updateData['total'] = $this->total;
            }

            // Add order number if converting from draft
            if ($orderNumberData) {
                $updateData['order_number'] = $orderNumberData['order_number'];
                $updateData['formatted_order_number'] = $orderNumberData['formatted_order_number'];
                $this->orderNumber = $orderNumberData['order_number'];
                $this->formattedOrderNumber = $orderNumberData['formatted_order_number'];
            }

            if (user() && in_array($status, ['kot', 'billed'], true)) {
                $updateData['added_by'] = user()->id;
            }

            Order::where('id', $order->id)->update($updateData);

            // Refresh order to get updated data
            $order->refresh();

            if (module_enabled('Loyalty') && method_exists($this, 'handleExistingOrderLoyaltyRedemption')) {
                $this->handleExistingOrderLoyaltyRedemption($order, $existingRedeemedPoints);
            }

            // CRITICAL: Final refresh to ensure order has latest total (especially after loyalty redemption)
            $order->refresh();

            // Update component properties with refreshed order data (especially order number)
            if ($orderNumberData) {
                $this->orderNumber = $order->order_number;
                $this->formattedOrderNumber = $order->formatted_order_number;
            }

            // Update orderDetail if it exists
            if ($this->orderDetail && $this->orderDetail->id == $order->id) {
                $this->orderDetail = $order;
            }

            // CRITICAL: Update component total to match database after all updates
            $this->total = $order->total;

            // For draft orders being converted to non-draft, delete existing order items
            // For regular orders being updated, delete items to recreate them
            // CRITICAL: Also do NOT delete when order has free stamp items (customer-site redemption):
            $isBillingKotOrder = ($status === 'billed' && $order->kot()->whereHas('items')->exists());
            $hasFreeStampItems = $status === 'billed' && $order->items()->where('is_free_item_from_stamp', true)->exists();
            // When adding a new KOT to an existing KOT order, do NOT delete order_items,
            // otherwise kot_items linked via order_item_id will be cascade-deleted.
            $isKotUpdate = $status === 'kot' && $order->kot()->whereHas('items')->exists();
            $preserveOrderItemsOnBill = $isBillingKotOrder || $hasFreeStampItems || $isKotUpdate;
            if ($wasDraft && $status !== 'draft') {
                // Converting from draft to real order - delete draft items
                $order->items()->delete();
            } elseif ($status !== 'draft' && !$preserveOrderItemsOnBill) {
                // Updating a non-draft order - delete items to recreate (skip when billing KOT order or order has free stamp items)
                $order->items()->delete();
            }
            // When billing KOT order or order has free stamp items, keep existing order_taxes so totals/tax_base stay correct
            if (!$preserveOrderItemsOnBill) {
                $order->taxes()->delete();
            }

        }

        if ($status == 'canceled') {
            $order->delete();

            Table::where('id', $this->tableId)->update([
                'available_status' => $tableStatus
            ]);
            return $this->redirect(route('pos.index'), navigate: true);
        }

        // Handle draft orders - save items but don't create KOT or bill
        if ($status == 'draft') {
            // Save order items for draft orders
            $touchedOrderItemIds = [];
            foreach ($this->orderItemList as $key => $value) {
                // Set price context before using price
                if ($this->orderTypeId) {
                    $value->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                    if (isset($this->orderItemVariation[$key])) {
                        $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                    }
                }

                $taxBreakup = isset($this->orderItemTaxDetails[$key]['tax_breakup']) ? json_encode($this->orderItemTaxDetails[$key]['tax_breakup']) : null;

                // Check if this is a free item from stamp redemption
                $isFreeItemFromStamp = strpos($key, 'free_stamp_') === 0;
                $stampRuleId = null;
                if ($isFreeItemFromStamp) {
                    // Extract stamp rule ID from key (format: free_stamp_{ruleId}_{timestamp})
                    $keyParts = explode('_', $key);
                    if (isset($keyParts[2])) {
                        $stampRuleId = $keyParts[2];
                    }
                }

                // For free items from stamps, check if item already exists in order
                // (it may have been added by the stamp redemption service)
                $menuItemId = (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menu_item_id : $this->orderItemList[$key]->id);
                $variationId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->id : null;

                // Check if this item has a stamp discount applied (not just free items)
                // CRITICAL: Only set stamp_rule_id if stamps were actually applied in POS
                // Don't set it just because the menu item has a stamp rule associated with it
                if (!$stampRuleId && !$isFreeItemFromStamp) {
                    // Only set stamp_rule_id if:
                    // 1. selectedStampRuleId is set (user explicitly applied stamps)
                    // 2. AND the item has a discount applied (amount < price * quantity)
                    if ($this->selectedStampRuleId) {
                        // Check if this item has a discount applied (stamp discount reduces amount)
                        $expectedAmount = (float)($this->orderItemVariation[$key]->price ?? $value->price ?? 0) * (int)($this->orderItemQty[$key] ?? 1);
                        $actualAmount = (float)($this->orderItemAmount[$key] ?? 0);

                        // If actual amount is significantly less than expected, stamp discount was applied
                        if (($expectedAmount > $actualAmount + 0.01) && module_enabled('Loyalty')) {
                            $stampRuleId = $this->getStampRuleId($menuItemId);
                        }
                    }

                    if (!$stampRuleId && module_enabled('Loyalty')) {
                        $handler = $this->loyaltyHandler();
                        if ($handler) {
                            [$resolvedStampRuleId] = $handler->resolveStampDiscountForItem(
                                $menuItemId,
                                (float)($this->orderItemVariation[$key]->price ?? $value->price ?? 0) * (int)($this->orderItemQty[$key] ?? 1),
                                (float)($this->orderItemAmount[$key] ?? 0)
                            );
                            if ($resolvedStampRuleId) {
                                $stampRuleId = $resolvedStampRuleId;
                            }
                        }
                    }
                }

                $itemAmount = $this->orderItemAmount[$key];
                if ($isFreeItemFromStamp) {
                    $itemAmount = 0; // Force free items to have 0 amount
                }

                $this->itemModifiersSelected[$key] = $this->itemModifiersSelected[$key] ?? [];
                $this->upsertCartLineOrderItem($order, [
                    'order_type' => $this->orderType,
                    'order_type_id' => $this->orderTypeId,
                    'order_id' => $order->id,
                    'menu_item_id' => $menuItemId,
                    'menu_item_variation_id' => $variationId,
                    'quantity' => $this->orderItemQty[$key],
                    'price' => (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $value->price),
                    'amount' => $itemAmount,
                    'note' => $this->itemNotes[$key] ?? null,
                    'tax_amount' => $this->orderItemTaxDetails[$key]['tax_amount'] ?? null,
                    'tax_percentage' => $this->orderItemTaxDetails[$key]['tax_percent'] ?? null,
                    'tax_breakup' => $taxBreakup,
                    'is_free_item_from_stamp' => $isFreeItemFromStamp,
                    'stamp_rule_id' => $stampRuleId,
                ], $this->itemModifiersSelected[$key], $touchedOrderItemIds);
            }

            // Save taxes if order-level tax mode
            if ($this->taxMode === 'order') {
                foreach ($this->taxes as $key => $value) {
                    OrderTax::create([
                        'order_id' => $order->id,
                        'tax_id' => $value->id
                    ]);
                }
            }

            // Save extra charges - remove existing charges first to prevent duplicates
            if (!empty($this->extraCharges)) {
                // Delete existing charges for this order to prevent duplicates
                $order->charges()->delete();

                // Deduplicate charges by ID before saving
                $uniqueCharges = collect($this->extraCharges)->unique('id');
                $chargesData = $uniqueCharges
                    ->map(fn($charge) => [
                        'charge_id' => $charge->id,
                    ])->toArray();

                $order->charges()->createMany($chargesData);
            }

            if (module_enabled('Loyalty')) {
                $this->handleDraftOrderLoyaltyRedemption($order);
            }
            $this->forcePersistDisplayedTotals($order);

            // Update table status
            if ($this->tableId) {
                Table::where('id', $this->tableId)->update([
                    'available_status' => $tableStatus
                ]);

                // Unlock the table for draft orders
                $table = Table::find($this->tableId);
                if ($table) {
                    $table->unlock(null, true);
                }
            }

            // Post order to folio if room service and bill_to is POST_TO_ROOM
            if ($this->isRoomServiceOrder()
                && $order->bill_to === 'POST_TO_ROOM'
                && $order->context_id
                && ($status === 'billed' || $status === 'paid')
                && !$order->posted_to_folio_at) {
                $order->refresh();
                $this->postOrderToFolio($order);
            }

            $this->dispatch('posOrderSuccess');

            $this->alert('success', $successMessage, [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            // Reset POS like KOT/Bill - refresh for new order
            $this->dispatch('resetPos');
            $this->dispatch('refreshPos');
            return;
        }

        // Handle KOT creation and totals calculation

        $kot = null;
        $kotIds = [];
        if ($status == 'kot') {
            if (in_array('Kitchen', restaurant_modules()) && in_array('kitchen', custom_module_plugins())) {
                // Group items by kot_place_id
                $groupedItems = [];

                foreach ($this->orderItemList as $key => $item) {
                    $menuItem = $this->orderItemVariation[$key]->menuItem ?? $item;
                    $kotPlaceId = $menuItem->kot_place_id ?? null;

                    if (!$kotPlaceId) {
                        continue;
                    }

                    $groupedItems[$kotPlaceId][] = [
                        'key' => $key,
                        'menu_item_id' => $menuItem->id,
                        'variation_id' => $this->orderItemVariation[$key]->id ?? null,
                        'quantity' => $this->orderItemQty[$key],
                        'modifiers' => $this->itemModifiersSelected[$key] ?? [],
                        'note' => $this->itemNotes[$key] ?? null,
                    ];
                }

                foreach ($groupedItems as $kotPlaceId => $items) {
                    $kot = Kot::create([
                        'kot_number' => Kot::generateKotNumber($order->branch),
                        'order_id' => $order->id,
                        'order_type_id' => $order->order_type_id,
                        'token_number' => Kot::generateTokenNumber(branch()->id, $order->order_type_id),
                        'kitchen_place_id' => $kotPlaceId,
                        'note' => $this->orderNote,
                    ]);

                    $kotIds[] = $kot->id;

                    foreach ($items as $item) {

                        $key       = $item['key'];
                        $menuItem  = $this->orderItemVariation[$key]->menuItem ?? $this->orderItemList[$key];
                        $variation = $this->orderItemVariation[$key] ?? null;

                        $itemPrice  = $variation ? $variation->price : $menuItem->price;
                        $quantity   = $item['quantity'];
                        $itemAmount = $this->orderItemAmount[$key] ?? ($itemPrice * $quantity);

                        $data = [
                            'key' => $key,
                            'menuItemId' => $variation ? $variation->menu_item_id : $menuItem->id,
                            'itemPrice' => $itemPrice,
                            'quantity' => $quantity,
                            'itemAmount' => $itemAmount,
                            'isFreeItemFromStamp' => str_starts_with($key, 'free_stamp_'),
                            'stampRuleId' => null,
                            'discountAmount' => 0,
                            'isDiscounted' => false,
                            'stampDataFromOrderItem' => false,
                        ];

                        if ($data['isFreeItemFromStamp']) {
                            $keyParts = explode('_', $key);
                            $data['stampRuleId'] = $keyParts[2] ?? null;
                        }

                        // Preserve stamp data from draft order item (shared logic)
                        $data = $this->preserveStampFromDraftOrderItem($data, $order, $item);

                        $modifierPrice = (float)($this->orderItemModifiersPrice[$key] ?? 0);
                        $expectedAmount = ($itemPrice + $modifierPrice) * $quantity;

                        // If cart already has discounted amount, preserve it and skip re-applying discount
                        if ($data['itemAmount'] < ($expectedAmount - 0.01)) {
                            $handler = $this->loyaltyHandler();
                            if ($handler && method_exists($handler, 'resolveStampDiscountForItem')) {
                                [$resolvedStampRuleId, $resolvedDiscount] = $handler->resolveStampDiscountForItem(
                                    $data['menuItemId'],
                                    (float)$expectedAmount,
                                    (float)$data['itemAmount']
                                );
                                if ($resolvedStampRuleId) {
                                    $data['stampRuleId'] = $resolvedStampRuleId;
                                    $data['discountAmount'] = $resolvedDiscount;
                                    $data['isDiscounted'] = $resolvedDiscount > 0;
                                }
                            }
                        } else {
                            // Resolve all loyalty logic in ONE place
                            $data = $this->resolveStampDataForItem($data);
                            $data = $this->applyStampRuleDiscount($data);
                        }

                        $kotItem = KotItem::create([
                            'kot_id' => $kot->id,
                            'menu_item_id' => $item['menu_item_id'],
                            'menu_item_variation_id' => $item['variation_id'],
                            'quantity' => $quantity,
                            'price' => $itemPrice,
                            'amount' => $data['itemAmount'],
                            'is_free_item_from_stamp' => $data['isFreeItemFromStamp'],
                            'stamp_rule_id' => $data['stampRuleId'],
                            'discount_amount' => $data['discountAmount'],
                            'is_discounted' => $data['isDiscounted'],
                            'note' => $item['note'],
                            'order_type_id' => $order->order_type_id ?? null,
                            'order_type' => $order->order_type ?? null,
                        ]);

                        $kotItem->modifierOptions()->sync($item['modifiers']);
                    }
                }
            } else {
                // No kitchen module: single KOT for all items
                $kot = Kot::create([
                    'kot_number' => Kot::generateKotNumber($order->branch) + 1,
                    'order_id' => $order->id,
                    'order_type_id' => $order->order_type_id,
                    'token_number' => Kot::generateTokenNumber(branch()->id, $order->order_type_id),
                    'note' => $this->orderNote
                ]);

                foreach ($this->orderItemList as $key => $value) {

                    $menuItem   = $this->orderItemVariation[$key]->menuItem ?? $value;
                    $variation  = $this->orderItemVariation[$key] ?? null;

                    $itemPrice  = $variation ? $variation->price : $menuItem->price;
                    $quantity   = $this->orderItemQty[$key];
                    $itemAmount = $this->orderItemAmount[$key] ?? ($itemPrice * $quantity);

                    $data = [
                        'key' => $key,
                        'menuItemId' => $variation ? $variation->menu_item_id : $menuItem->id,
                        'itemPrice' => $itemPrice,
                        'quantity' => $quantity,
                        'itemAmount' => $itemAmount,
                        'isFreeItemFromStamp' => str_starts_with($key, 'free_stamp_'),
                        'stampRuleId' => null,
                        'discountAmount' => 0,
                        'isDiscounted' => false,
                        'stampDataFromOrderItem' => false,
                    ];

                    if ($data['isFreeItemFromStamp']) {
                        $keyParts = explode('_', $key);
                        $data['stampRuleId'] = $keyParts[2] ?? null;
                    }

                    $modifierPrice = (float)($this->orderItemModifiersPrice[$key] ?? 0);
                    $expectedAmount = ($itemPrice + $modifierPrice) * $quantity;

                    // If cart already has discounted amount, preserve it and skip re-applying discount
                    if ($data['itemAmount'] < ($expectedAmount - 0.01)) {
                        $handler = $this->loyaltyHandler();
                        if ($handler && method_exists($handler, 'resolveStampDiscountForItem')) {
                            [$resolvedStampRuleId, $resolvedDiscount] = $handler->resolveStampDiscountForItem(
                                $data['menuItemId'],
                                (float)$expectedAmount,
                                (float)$data['itemAmount']
                            );
                            if ($resolvedStampRuleId) {
                                $data['stampRuleId'] = $resolvedStampRuleId;
                                $data['discountAmount'] = $resolvedDiscount;
                                $data['isDiscounted'] = $resolvedDiscount > 0;
                            }
                        }
                    } else {
                        $data = $this->resolveStampDataForItem($data);
                        $data = $this->applyStampRuleDiscount($data);
                    }

                    $kotItem = KotItem::create([
                        'kot_id' => $kot->id,
                        'menu_item_id' => $data['menuItemId'],
                        'menu_item_variation_id' => $variation->id ?? null,
                        'quantity' => $quantity,
                        'price' => $itemPrice,
                        'amount' => $data['itemAmount'],
                        'is_free_item_from_stamp' => $data['isFreeItemFromStamp'],
                        'stamp_rule_id' => $data['stampRuleId'],
                        'discount_amount' => $data['discountAmount'],
                        'is_discounted' => $data['isDiscounted'],
                        'note' => $this->itemNotes[$key] ?? null,
                        'order_type_id' => $order->order_type_id ?? null,
                        'order_type' => $order->order_type ?? null,
                    ]);

                    $kotItem->modifierOptions()->sync($this->itemModifiersSelected[$key] ?? []);
                }
            }

            // Sync newly created KOT items into order_items and link order_item_id
            $this->syncKotItemsToOrderItems($order, $kotIds ?? []);

            if ($status === 'kot' && $order->customer_id) {
                $this->handleStampRedemptionForOrder($order);
                $this->handleLoyaltyPointsRedemptionForOrder($order);
            }

            // CRITICAL: For new KOT orders, ensure tax is saved after KOT creation
            if (!$this->orderID && $status == 'kot') {
                // FIRST: Save OrderTax records if order-level tax mode
                if ($this->taxMode === 'order' && !empty($this->taxes)) {
                    foreach ($this->taxes as $tax) {
                        OrderTax::create([
                            'order_id' => $order->id,
                            'tax_id' => $tax->id
                        ]);
                    }
                }

                // Recalculate totals to ensure tax is calculated
                $this->calculateTotal();

                // Update order with all financial fields including tax, subtotal, and total
                Order::where('id', $order->id)->update([
                    'sub_total' => round($this->subTotal, 2),
                    'total_tax_amount' => round($this->totalTaxAmount, 2),
                    'tax_mode' => $this->taxMode,
                    'tax_base' => $this->taxBase,
                    'total' => round($this->total, 2),
                ]);

                // Refresh order to get updated values
                $order->refresh();
            }

            // Recalculate totals after KOT creation if editing an existing order
            if ($this->orderID || $this->tableOrderID) {
                if (module_enabled('Loyalty') && method_exists($this, 'recalculateOrderTotalAfterStampRedemption')) {
                    $this->recalculateOrderTotalAfterStampRedemption($order);
                } else {
                    $this->recalculateTotalsForKotOrderWithoutModule($order);
                }
            }

            if ($secondAction == 'bill' && $thirdAction == 'payment') {
                // Check if this is a KOT order
                $isKotOrder = ($order->status === 'kot' || ($order->kot && $order->kot->count() > 0));

                if ($isKotOrder) {
                    // Use shared billing method for KOT orders
                    $this->billKotOrder($order);

                    // Update order status to billed
                    Order::where('id', $order->id)->update([
                        'status' => 'billed'
                    ]);

                    $order->refresh();
                } else {
                    // For non-KOT orders, use original logic (draft orders)
                    // Update order status to billed first
                    Order::where('id', $order->id)->update([
                        'status' => 'billed'
                    ]);

                    // Process items from cart (original logic for non-KOT orders)
                    $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
                    $touchedOrderItemIds = [];
                    foreach ($this->orderItemList as $key => $value) {
                        // Set price context before using price
                        if ($this->orderTypeId) {
                            $value->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                            if (isset($this->orderItemVariation[$key])) {
                                $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                            }
                        }

                        // Check if this is a free item from stamp redemption
                        $isFreeItemFromStamp = strpos($key, 'free_stamp_') === 0;
                        $stampRuleId = null;
                        if ($isFreeItemFromStamp) {
                            // Extract stamp rule ID from key (format: free_stamp_{ruleId}_{timestamp})
                            $keyParts = explode('_', $key);
                            if (isset($keyParts[2])) {
                                $stampRuleId = $keyParts[2];
                            }
                        }

                        $menuItemId = (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menu_item_id : $this->orderItemList[$key]->id);
                        $variationId = (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->id : null);

                        if (!$stampRuleId && !$isFreeItemFromStamp) {
                            [$resolvedStampRuleId] = $this->resolveStampDiscountForItem(
                                $menuItemId,
                                (float)($this->orderItemVariation[$key]->price ?? $value->price ?? 0) * (int)($this->orderItemQty[$key] ?? 1),
                                (float)($this->orderItemAmount[$key] ?? 0)
                            );
                            if ($resolvedStampRuleId) {
                                $stampRuleId = $resolvedStampRuleId;
                            }
                        }

                        $itemAmount = $this->orderItemAmount[$key];
                        if ($isFreeItemFromStamp) {
                            $itemAmount = 0; // Force free items to have 0 amount
                        }

                        $this->itemModifiersSelected[$key] = $this->itemModifiersSelected[$key] ?? [];
                        $this->upsertCartLineOrderItem($order, [
                            'order_id' => $order->id,
                            'menu_item_id' => $menuItemId,
                            'menu_item_variation_id' => $variationId,
                            'quantity' => $this->orderItemQty[$key],
                            'price' => (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $value->price),
                            'amount' => $itemAmount,
                            'is_free_item_from_stamp' => $isFreeItemFromStamp,
                            'stamp_rule_id' => $stampRuleId,
                        ], $this->itemModifiersSelected[$key], $touchedOrderItemIds);
                    }

                    if ($this->taxMode === 'order') {
                        // Check if taxes already exist (to avoid duplicates)
                        $existingTaxIds = $order->taxes()->pluck('tax_id')->toArray();

                        foreach ($this->taxes as $tax) {
                            // Only create if tax doesn't already exist for this order
                            if (!in_array($tax->id, $existingTaxIds)) {
                                OrderTax::create([
                                    'order_id' => $order->id,
                                    'tax_id' => $tax->id
                                ]);
                            }
                        }
                    }

                    $order->refresh();
                    $order->load('items');

                    // Clear totals before recalculation
                    $this->total = 0;
                    $this->subTotal = 0;
                    $this->totalTaxAmount = 0;
                    $this->taxBase = 0;

                    foreach ($order->items as $item) {
                        $isFreeItem = $item->is_free_item_from_stamp ?? false;
                        if ($isFreeItem) {
                            continue;
                        }

                        $this->subTotal += $item->amount;
                        $this->total += $item->amount;
                    }

                    $this->discountedTotal = $this->total;

                    if ($order->discount_type === 'percent') {
                        $this->discountAmount = round(($this->subTotal * $order->discount_value) / 100, 2);
                    } elseif ($order->discount_type === 'fixed') {
                        $this->discountAmount = min($order->discount_value, $this->subTotal);
                    }

                    $loyaltyDiscount = floatval($order->loyalty_discount_amount ?? 0);
                    $stampDiscount = floatval($order->stamp_discount_amount ?? 0);

                    $this->total -= $this->discountAmount;
                    $this->total -= $loyaltyDiscount;
                    $this->total -= $stampDiscount;
                    $this->discountedTotal = $this->total;

                    $applicableCharges = $this->getApplicableExtraCharges();
                    $chargeTotals = RestaurantCharge::calculateTotals($applicableCharges, $this->discountedTotal);
                    $serviceTotal = $chargeTotals['total'];
                    $this->total += $serviceTotal;

                    $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? true;
                    $this->taxBase = RestaurantCharge::resolveTaxBase(
                        $this->discountedTotal,
                        $applicableCharges,
                        $includeChargesInTaxBase
                    );

                    $this->recalculateTaxTotals($this->taxBase);

                    if ($this->taxMode === 'item' && (restaurant()->tax_inclusive ?? false)) {
                        $this->subTotal -= $this->totalTaxAmount;
                    }

                    if ($this->tipAmount > 0) {
                        $this->total += $this->tipAmount;
                    }

                    if ($this->deliveryFee > 0) {
                        $this->total += $this->deliveryFee;
                    }

                    // Redeem loyalty if needed
                    if ($order->customer_id) {
                        $this->handleStampRedemptionForOrder($order);
                        $this->handleLoyaltyPointsRedemptionForOrder($order);
                    }

                    // Update order totals
                    Order::where('id', $order->id)->update([
                        'sub_total' => round($this->subTotal, 2),
                        'total' => round($this->total, 2),
                        'discount_amount' => $this->discountAmount,
                        'total_tax_amount' => round($this->totalTaxAmount, 2),
                        'tax_base' => $this->taxBase,
                        'tax_mode' => $this->taxMode,
                    ]);

                    $order->refresh();
                }

                // Show payment modal
                $this->forcePersistDisplayedTotals($order);
                $order->refresh();
                $this->dispatch('showPaymentModal', id: $order->id);

                $this->printKot($order, $kot ?? null);
                $this->printOrder($order);

                NewOrderCreated::dispatch($order);

                $this->resetPos();
                return;
            }
        }

        if ($status == 'billed') {
            // Check if this is a KOT order being billed
            // For KOT orders, copy items from kot_items instead of creating from cart
            $isKotOrder = ($order->status === 'kot' || ($order->kot && $order->kot->count() > 0));

            if ($isKotOrder) {
                // Use shared billing method for KOT orders
                $this->billKotOrder($order);

                // Update order status to billed
                Order::where('id', $order->id)->update([
                    'status' => 'billed'
                ]);

                $order->refresh();
            } else {
                // Non-KOT order: Save order items from cart (original logic)
                $touchedOrderItemIds = [];
                foreach ($this->orderItemList as $key => $value) {
                    // Set price context before using price
                    if ($this->orderTypeId) {
                        $value->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                        if (isset($this->orderItemVariation[$key])) {
                            $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                        }
                    }

                    $taxBreakup = isset($this->orderItemTaxDetails[$key]['tax_breakup']) ? json_encode($this->orderItemTaxDetails[$key]['tax_breakup']) : null;

                    // Check if this is a free item from stamp redemption
                    $isFreeItemFromStamp = strpos($key, 'free_stamp_') === 0;
                    $stampRuleId = null;
                    if ($isFreeItemFromStamp) {
                        // Extract stamp rule ID from key (format: free_stamp_{ruleId}_{timestamp})
                        $keyParts = explode('_', $key);
                        if (isset($keyParts[2])) {
                            $stampRuleId = $keyParts[2];
                        }
                    }

                    // For free items from stamps, check if item already exists in order
                    // (it may have been added by the stamp redemption service)
                    $menuItemId = (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menu_item_id : $this->orderItemList[$key]->id);
                    $variationId = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->id : null;

                    // Check if this item has a stamp discount applied (not just free items)
                    // CRITICAL: Only set stamp_rule_id if stamps were actually applied in POS
                    // Don't set it just because the menu item has a stamp rule associated with it
                    if (!$stampRuleId && !$isFreeItemFromStamp) {
                        // Only set stamp_rule_id if:
                        // 1. selectedStampRuleId is set (user explicitly applied stamps)
                        // 2. AND the item has a discount applied (amount < price * quantity)
                        if ($this->selectedStampRuleId) {
                            // Check if this item has a discount applied (stamp discount reduces amount)
                            $expectedAmount = (float)($this->orderItemVariation[$key]->price ?? $value->price ?? 0) * (int)($this->orderItemQty[$key] ?? 1);
                            $actualAmount = (float)($this->orderItemAmount[$key] ?? 0);

                            // If actual amount is significantly less than expected, stamp discount was applied
                            if ($expectedAmount > $actualAmount + 0.01) {
                                // Verify the selected stamp rule matches this menu item
                                try {
                                    if (module_enabled('Loyalty')) {
                                        $restaurantId = restaurant()->id;
                                        $stampRule = \Modules\Loyalty\Entities\LoyaltyStampRule::getRuleForMenuItem($restaurantId, $menuItemId);
                                        // Only set if the selected stamp rule matches this menu item's stamp rule
                                        if ($stampRule && $stampRule->is_active && $stampRule->id == $this->selectedStampRuleId) {
                                            $stampRuleId = $stampRule->id;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    // Silently handle - stamp rule check failed
                                }
                            }
                        }

                        if (!$stampRuleId && module_enabled('Loyalty')) {
                            $handler = $this->loyaltyHandler();
                            if ($handler) {
                                [$resolvedStampRuleId] = $handler->resolveStampDiscountForItem(
                                    $menuItemId,
                                    (float)($this->orderItemVariation[$key]->price ?? $value->price ?? 0) * (int)($this->orderItemQty[$key] ?? 1),
                                    (float)($this->orderItemAmount[$key] ?? 0)
                                );
                                if ($resolvedStampRuleId) {
                                    $stampRuleId = $resolvedStampRuleId;
                                }
                            }
                        }
                    }

                    $itemAmount = $this->orderItemAmount[$key];
                    if ($isFreeItemFromStamp) {
                        $itemAmount = 0; // Force free items to have 0 amount
                    }

                    $this->itemModifiersSelected[$key] = $this->itemModifiersSelected[$key] ?? [];
                    $this->upsertCartLineOrderItem($order, [
                        'order_type' => $this->orderType,
                        'order_type_id' => $this->orderTypeId,
                        'order_id' => $order->id,
                        'menu_item_id' => $menuItemId,
                        'menu_item_variation_id' => $variationId,
                        'quantity' => $this->orderItemQty[$key],
                        'price' => (isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $value->price),
                        'amount' => $itemAmount,
                        'note' => $this->itemNotes[$key] ?? null,
                        'tax_amount' => $this->orderItemTaxDetails[$key]['tax_amount'] ?? null,
                        'tax_percentage' => $this->orderItemTaxDetails[$key]['tax_percent'] ?? null,
                        'tax_breakup' => $taxBreakup,
                        'is_free_item_from_stamp' => $isFreeItemFromStamp,
                        'stamp_rule_id' => $stampRuleId,
                    ], $this->itemModifiersSelected[$key], $touchedOrderItemIds);
                } // End foreach for non-KOT orders
            } // End else block for non-KOT orders

            // CRITICAL: Only create taxes here for non-KOT orders
            // KOT orders already have taxes created in billKotOrder() method
            if ($this->taxMode === 'order' && !$isKotOrder) {
                // Check if taxes already exist (to avoid duplicates)
                $existingTaxIds = $order->taxes()->pluck('tax_id')->toArray();

                foreach ($this->taxes as $key => $value) {
                    // Only create if tax doesn't already exist for this order
                    if (!in_array($value->id, $existingTaxIds)) {
                        OrderTax::create([
                            'order_id' => $order->id,
                            'tax_id' => $value->id
                        ]);
                    }
                }
            }

            $order->load('charges');

            // Deduplicate charges before filtering to prevent duplicates
            $validCharges = collect($this->extraCharges ?? [])
                ->unique('id')
                ->filter(fn($charge) => in_array($this->orderTypeSlug, $charge->order_types));

            $currentChargeIds = $order->charges->pluck('charge_id')->unique();
            $validChargeIds = $validCharges->pluck('id')->unique();

            // Remove invalid charges and add new valid charges
            $order->charges()->whereNotIn('charge_id', $validChargeIds)->delete();

            $validChargeIds->diff($currentChargeIds)->each(
                fn($chargeId) =>
                OrderCharge::create(['order_id' => $order->id, 'charge_id' => $chargeId])
            );

            // Refresh order to load all OrderItems (especially for KOT orders that were just copied)
            $order->refresh();
            $order->load('items');

            $this->total = 0;
            $this->subTotal = 0;

            foreach ($order->items as $value) {
                // Skip free items from stamp redemption (they have amount = 0 anyway)
                $isFreeItem = $value->is_free_item_from_stamp ?? false;
                if ($isFreeItem) {
                    continue;
                }

                $this->subTotal = ($this->subTotal + $value->amount);
                $this->total = ($this->total + $value->amount);
            }

            $this->discountedTotal = $this->total;

            if ($order->discount_type === 'percent') {
                $this->discountAmount = round(($this->subTotal * $order->discount_value) / 100, 2);
            } elseif ($order->discount_type === 'fixed') {
                $this->discountAmount = min($order->discount_value, $this->subTotal);
            }

            // CRITICAL: Include loyalty discount if points were redeemed
            $loyaltyDiscount = floatval($order->loyalty_discount_amount ?? 0);
            // CRITICAL: Include stamp discount if stamps were redeemed
            $stampDiscount = floatval($order->stamp_discount_amount ?? 0);
            $totalDiscount = $this->discountAmount + $loyaltyDiscount + $stampDiscount;

            // Step 2: Calculate net = subtotal - discount
            $this->total -= $this->discountAmount;
            $this->total -= $loyaltyDiscount; // Subtract loyalty discount
            $this->total -= $stampDiscount; // Subtract stamp discount
            $this->discountedTotal = $this->total;

            // Step 3: Calculate service charges on net (discountedTotal)
            $applicableCharges = $this->getApplicableExtraCharges();
            $chargeTotals = RestaurantCharge::calculateTotals($applicableCharges, $this->discountedTotal);
            $serviceTotal = $chargeTotals['total'];
            $this->total += $serviceTotal;

            // Step 4: Calculate tax_base based on setting
            $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? true;
            $this->taxBase = RestaurantCharge::resolveTaxBase(
                $this->discountedTotal,
                $applicableCharges,
                $includeChargesInTaxBase
            );

            // Step 5: Calculate taxes on tax_base
            $this->recalculateTaxTotals($this->taxBase);

            if ($this->taxMode === 'item' && (restaurant()->tax_inclusive ?? false)) {
                $this->subTotal -= $this->totalTaxAmount;
            }


            if ($this->tipAmount > 0) {
                $this->total += $this->tipAmount;
            }

            if ($this->deliveryFee > 0) {
                $this->total += $this->deliveryFee;
            }

            // CRITICAL: Redeem stamps and points NOW that items exist
            $loyaltyRedemptionHappened = false;
            if ($order->customer_id) {
                // Redeem stamps if selected
                if (module_enabled('Loyalty') && $this->isStampsEnabledForPOS()) {
                    // Collect ALL unique stamp rule IDs from order items (including free items)
                    $stampRuleIdsFromOrderItems = [];
                    $order->load('items');
                    foreach ($order->items as $orderItem) {
                        if ($orderItem->stamp_rule_id && !in_array($orderItem->stamp_rule_id, $stampRuleIdsFromOrderItems)) {
                            $stampRuleIdsFromOrderItems[] = $orderItem->stamp_rule_id;
                        }
                    }

                    $stampRuleIdsToRedeem = $stampRuleIdsFromOrderItems;
                    if ($this->selectedStampRuleId && !in_array($this->selectedStampRuleId, $stampRuleIdsToRedeem)) {
                        $stampRuleIdsToRedeem[] = $this->selectedStampRuleId;
                    }

                    // Redeem stamps for each stamp rule ONCE
                    foreach ($stampRuleIdsToRedeem as $stampRuleIdToRedeem) {
                        if (!$stampRuleIdToRedeem) {
                            continue;
                        }

                        // Redeem stamps for all eligible items on this billed order
                        $this->redeemStampsForAllEligibleItems($order, $stampRuleIdToRedeem);
                    }

                    $loyaltyRedemptionHappened = true;
                    $order->refresh();
                    // Recalculate totals after stamp redemption
                    if (module_enabled('Loyalty')) {
                        $this->recalculateOrderTotalAfterStampRedemption($order);
                        $order->refresh();
                    }

                    // Update component values
                    $this->stampDiscountAmount = $order->stamp_discount_amount ?? 0;
                    $this->subTotal = $order->sub_total;
                    $this->total = $order->total;
                    $this->totalTaxAmount = $order->total_tax_amount;
                }

                // Redeem points if selected
                if ($this->loyaltyPointsRedeemed > 0 && $this->loyaltyDiscountAmount > 0 && $this->isPointsEnabledForPOS() && module_enabled('Loyalty')) {
                    $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                    $result = $loyaltyService->redeemPoints($order, $this->loyaltyPointsRedeemed);

                    if ($result['success']) {
                        $loyaltyRedemptionHappened = true;
                        $order->refresh();
                        $order->load(['taxes', 'charges.charge']);

                        // Recalculate total with loyalty discount
                        $correctTotal = $order->sub_total;
                        $correctTotal -= ($order->discount_amount ?? 0);
                        $correctTotal -= ($order->loyalty_discount_amount ?? 0);
                        // NOTE: Stamp discount is NOT subtracted here because when stamps are auto-redeemed in POS,
                        // the discount is already applied to item amounts, so sub_total already reflects it

                        $discountedBase = $correctTotal;

                        // Step 1: Calculate service charges on discounted base
                        $charges = collect($order->charges ?? [])
                            ->map(fn ($chargeRelation) => $chargeRelation->charge)
                            ->filter();

                        $chargeTotals = RestaurantCharge::calculateTotals($charges, $discountedBase);
                        $serviceTotal = $chargeTotals['total'];

                        // Step 2: Calculate tax_base based on setting
                        $includeChargesInTaxBase = restaurant()->include_charges_in_tax_base ?? true;
                        $taxBase = RestaurantCharge::resolveTaxBase($discountedBase, $charges, $includeChargesInTaxBase);

                        // Step 3: Recalculate taxes on tax_base
                        $correctTaxAmount = 0;
                        if ($order->tax_mode === 'order' && $order->taxes && $order->taxes->count() > 0) {
                            foreach ($order->taxes as $orderTax) {
                                $tax = $orderTax->tax;
                                if ($tax && isset($tax->tax_percent)) {
                                    $correctTaxAmount += ($tax->tax_percent / 100) * $taxBase;
                                }
                            }
                        } else {
                            $correctTaxAmount = $order->items->sum('tax_amount') ?? 0;
                        }
                        $correctTotal += $correctTaxAmount;

                        // Step 4: Add service charges to total
                        $correctTotal += $serviceTotal;

                        // Add tip and delivery
                        $correctTotal += ($order->tip_amount ?? 0);
                        $correctTotal += ($order->delivery_fee ?? 0);

                        // Update order with all values preserved
                        $updateData = [
                            'sub_total' => $this->subTotal,
                            'total' => round($correctTotal, 2),
                            'discount_amount' => $this->discountAmount,
                            'total_tax_amount' => round($correctTaxAmount, 2),
                            'tax_mode' => $this->taxMode,
                        ];

                        if ($order->stamp_discount_amount > 0) {
                            $updateData['stamp_discount_amount'] = $order->stamp_discount_amount;
                        }
                        if ($order->loyalty_points_redeemed > 0) {
                            $updateData['loyalty_points_redeemed'] = $order->loyalty_points_redeemed;
                        }
                        if ($order->loyalty_discount_amount > 0) {
                            $updateData['loyalty_discount_amount'] = $order->loyalty_discount_amount;
                        }

                        DB::table('orders')->where('id', $order->id)->update($updateData);
                        $order->refresh();

                        // Update component values
                        $this->subTotal = $order->sub_total;
                        $this->total = $order->total;
                        $this->totalTaxAmount = $order->total_tax_amount;
                    }
                }
            }

            // CRITICAL: Update order totals (either after loyalty or if no loyalty)
            if (!$loyaltyRedemptionHappened) {
                Order::where('id', $order->id)->update([
                    'sub_total' => $this->subTotal,
                    'total' => round($this->total, 2),
                    'discount_amount' => $this->discountAmount,
                    'total_tax_amount' => $this->totalTaxAmount,
                    'tax_base' => $this->taxBase,
                    'tax_mode' => $this->taxMode,
                ]);
            }

            // Post order to folio if room service and bill_to is POST_TO_ROOM
            if ($this->isRoomServiceOrder()
                && $order->bill_to === 'POST_TO_ROOM'
                && $order->context_id
                && !$order->posted_to_folio_at) {
                $order->refresh();
                $this->postOrderToFolio($order);
            }

            if ($order->placed_via == null || $order->placed_via == 'pos') {
                NewOrderCreated::dispatch($order);
            }

            // Do NOT call $this->resetPos() here!
            // The customer display will now show the thank you/payment screen.

            // Update customer display cache to set status to 'billed'
            $this->setCustomerDisplayStatus('billed');
        }

        if ($order) {
            if ($status == 'kot' && module_enabled('Loyalty')) {
                $this->handleKotOrderLoyaltyRedemption($order);
            }

            // For existing orders creating a new KOT, totals are recalculated after KOT creation.
            // Do not override them with the pre-save snapshot.
            if (!($status == 'kot' && $this->orderID)) {
                $this->forcePersistDisplayedTotals($order);
            }
            $order->refresh();
        }

        Table::where('id', $this->tableId)->update([
            'available_status' => $tableStatus
        ]);

        // Delete orders from merged tables after ANY successful save (including drafts)
        // This handles the case when updating an existing order
        if (!empty($this->ordersToDeleteAfterMerge)) {
            $this->deleteMergedTableOrders();
        }

        if ($status === 'kot') {
            $order->refresh();
            OrderWaiterResponseService::autoAcceptWhenPlacedByWaiterOnPos($order);
        }

        $this->dispatch('posOrderSuccess');

        $this->alert('success', $successMessage, [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        if ($status == 'kot') {
            if ($secondAction == 'print') {
                // Check if the 'kitchen' package is enabled
                $this->printKot($order, $kot, $kotIds);
            }

            if ($this->orderID) {
                return $this->redirect(route('pos.kot', $order->id) . '?show-order-detail=true', navigate: true);
            }

            $this->dispatch('resetPos');
            $this->dispatch('refreshPos');
            // return $this->redirect(route('kots.index'), navigate: true);
        }

        if ($status == 'billed') {
            // For billed status, behave same for normal and draft-converted orders
            // (no immediate redirect; let payment/print logic run as usual)

            switch ($secondAction) {

                case 'payment':
                    // CRITICAL: Refresh order to ensure we have latest total (especially after loyalty redemption)
                    $order->refresh();
                    $this->dispatch('showPaymentModal', id: $order->id);
                    break;
                case 'print':

                    $orderPlaces = \App\Models\MultipleOrder::with('printerSetting')->get();

                    foreach ($orderPlaces as $orderPlace) {
                        $printerSetting = $orderPlace->printerSetting;
                    }

                    switch ($printerSetting?->printing_choice) {
                        case 'directPrint':
                            $this->handleOrderPrint($order->id);
                            break;
                        default:
                            $url = route('orders.print', $order->id);
                            $this->dispatch('print_location', $url);
                            break;
                    }

                    $this->dispatch('resetPos');

                    try {

                        // switch ($printerSetting?->printing_choice) {
                        //     case 'directPrint':
                        //         $this->handleOrderPrint($order->id);
                        //         break;
                        //     default:
                        //         $url = route('orders.print', $order->id);
                        //         $this->dispatch('print_location', $url);
                        //         break;
                        // }
                    } catch (\Throwable $e) {
                        Log::info($e->getMessage());
                        $this->alert('error', __('messages.printerNotConnected') . ' ' . $e->getMessage(), [
                            'toast' => true,
                            'position' => 'top-end',
                            'showCancelButton' => false,
                            'cancelButtonText' => __('app.close')
                        ]);
                    }
            }

            // change
            if (!in_array($secondAction, ['payment', 'print'])) {
                $this->dispatch('showOrderDetail', id: $order->id, fromPos: true);
            }

            $this->dispatch('resetPos');
            $this->dispatch('refreshPos');
        }

        // Handle default case outside the switch block

    }

    public function printOrder($order)
    {
        // Ensure $order is an Order model instance
        if (is_numeric($order)) {
            $order = Order::find($order);
        }

        if (!$order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        // Check if order has split payments - if yes, show modal
        if ($order->split_type && $order->splitOrders()->where('status', 'paid')->count() > 0) {
            $this->showPrintOptionsModal = true;
            $this->printMode = null;
            $this->selectedSplitId = null;
            return;
        }

        // No splits - execute normal print
        $this->executePrint($order->id);
    }

    private function executePrint($orderId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return;
        }

        $orderPlace = \App\Models\MultipleOrder::with('printerSetting')->first();
        $printerSetting = $orderPlace?->printerSetting;

        try {
            switch ($printerSetting?->printing_choice) {
                case 'directPrint':
                    $this->handleOrderPrint($orderId);
                    break;
                default:
                    $url = route('orders.print', $orderId);
                    $this->dispatch('print_location', $url);
                    break;
            }
        } catch (\Throwable $e) {
            $this->alert('error', __('messages.printerNotConnected') . ' : ' . $e->getMessage(), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }
    }

    public function printKot($order, $kot = null, $kotIds = [])
    {
        // Check if the 'kitchen' package is enabled
        if (in_array('Kitchen', restaurant_modules()) && in_array('kitchen', custom_module_plugins())) {
            // Get all KOTs for this order (created above)

            if ($kotIds) {
                $kots = $order->kot()->whereIn('id', $kotIds)->with('items')->get();
            } else {
                $kots = $order->kot()->with('items')->get();
            }

            foreach ($kots as $kot) {
                $kotPlaceItems = [];

                foreach ($kot->items as $kotItem) {
                    if ($kotItem->menuItem && $kotItem->menuItem->kot_place_id) {
                        $kotPlaceId = $kotItem->menuItem->kot_place_id;

                        if (!isset($kotPlaceItems[$kotPlaceId])) {
                            $kotPlaceItems[$kotPlaceId] = [];
                        }

                        $kotPlaceItems[$kotPlaceId][] = $kotItem;
                    }
                }

                // Get the kot places and their printer settings
                $kotPlaceIds = array_keys($kotPlaceItems);
                $kotPlaces = KotPlace::with('printerSetting')->whereIn('id', $kotPlaceIds)->get();

                foreach ($kotPlaces as $kotPlace) {
                    $printerSetting = $kotPlace->printerSetting;

                    if ($printerSetting && $printerSetting->is_active == 0) {
                        $printerSetting = Printer::where('is_default', true)->first();
                    }

                    // If no printer is set, fallback to print URL dispatch
                    if (!$printerSetting) {
                        $url = route('kot.print', [$kot->id, $kotPlace?->id]);
                        $this->dispatch('print_location', $url);
                        continue;
                    }

                    try {
                        switch ($printerSetting->printing_choice) {
                            case 'directPrint':
                                $this->handleKotPrint($kot->id, $kotPlace->id);
                                break;
                            default:
                                $url = route('kot.print', [$kot->id, $kotPlace?->id]);
                                $this->dispatch('print_location', $url);
                                break;
                        }
                    } catch (\Throwable $e) {
                        $this->alert('error', __('messages.printerNotConnected') . ' ' . $e->getMessage(), [
                            'toast' => true,
                            'position' => 'top-end',
                            'showCancelButton' => false,
                            'cancelButtonText' => __('app.close')
                        ]);
                    }
                }
            }
        } else {
            $kotPlace = KotPlace::where('is_default', 1)->first();
            $printerSetting = $kotPlace->printerSetting;

            // Get the KOT for this order
            $kot = $kot ?? $order->kot()->first();

            // If no printer is set, fallback to print URL dispatch
            if (!$printerSetting) {
                $url = route('kot.print', [$kot->id, $kotPlace?->id]);
                $this->dispatch('print_location', $url);
            }

            try {
                switch ($printerSetting->printing_choice) {
                    case 'directPrint':
                        $this->handleKotPrint($kot->id, $kotPlace->id);
                        break;

                    default:
                        $url = route('kot.print', [$kot->id]);
                        $this->dispatch('print_location', $url);
                        break;
                }
            } catch (\Throwable $e) {
                $this->alert('error', __('messages.printerNotConnected') . ' ' . $e->getMessage(), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close')
                ]);
            }
        }
    }

    #[On('resetPos')]
    public function resetPos()
    {
        $preserveBilledCustomerDisplay = $this->customerDisplayStatus === 'billed';

        $this->search = null;
        $this->filterCategories = null;
        $this->menuItem = null;
        $this->subTotal = 0;
        $this->total = 0;
        $this->orderNumber = null;
        $this->formattedOrderNumber = null;
        $this->discountedTotal = 0;
        $this->tipAmount = 0;
        $this->deliveryFee = 0;
        $this->tableNo = null;
        $this->tableId = null;
        $this->noOfPax = null;
        $this->selectWaiter = user()->id;
        $this->orderItemList = [];
        $this->orderItemVariation = [];
        $this->orderItemQty = [];
        $this->orderItemAmount = [];

        // Set default order type based on restaurant settings
        $disablePopup = restaurant()->disable_order_type_popup ?? false;
        if ($disablePopup && restaurant()->default_order_type_id) {
            // Use the configured default order type
            $defaultOrderType = OrderType::find(restaurant()->default_order_type_id);
            if ($defaultOrderType && $defaultOrderType->is_active) {
                $this->orderType = $defaultOrderType->type;
                $this->orderTypeSlug = $defaultOrderType->slug;
                $this->orderTypeId = $defaultOrderType->id;
            } else {
                // Fallback to Dine In if configured default is not available
                $defaultOrderType = OrderType::where('type', 'dine_in')
                    ->where('is_active', true)
                    ->first();
                if ($defaultOrderType) {
                    $this->orderType = $defaultOrderType->type;
                    $this->orderTypeSlug = $defaultOrderType->slug;
                    $this->orderTypeId = $defaultOrderType->id;
                } else {
                    $this->orderType = 'dine_in';
                    $this->orderTypeSlug = 'dine_in';
                    $this->orderTypeId = null;
                }
            }
        } else {
            // Set default order type to Dine In (when popup is enabled)
            $defaultOrderType = OrderType::where('type', 'dine_in')
                ->where('is_active', true)
                ->first();

            if ($defaultOrderType) {
                $this->orderType = $defaultOrderType->type;
                $this->orderTypeSlug = $defaultOrderType->slug;
                $this->orderTypeId = $defaultOrderType->id;
            } else {
                //  if no default order type found
                $this->orderType = 'dine_in';
                $this->orderTypeSlug = 'dine_in';
                $this->orderTypeId = null;
            }
        }

        $this->discountType = null;
        $this->discountValue = null;
        $this->showDiscountModal = false;
        $this->selectedModifierItem = null;
        $this->modifiers = null;
        $this->allowOrderTypeSelection = false; // Reset flag on POS reset
        $this->itemModifiersSelected = [];
        $this->discountAmount = null;
        $this->orderStatus;
        $this->showNewKotButton = false;
        $this->itemNotes = []; // Reset item notes
        $this->orderItemTaxDetails = [];
        $this->totalTaxAmount = 0;
        $this->taxBase = 0; // Reset tax base
        if (! $preserveBilledCustomerDisplay) {
            $this->customerDisplayStatus = 'idle';
        }
        $this->orderID = null; // Reset order ID so draft button shows again
        $this->orderDetail = null; // Reset order detail
        $this->tableOrderID = null; // Reset table order ID
        $this->tableOrder = null; // Reset table order
        $this->customerId = null; // Reset customer ID
        $this->customer = null; // Reset customer
        $this->orderItemModifiersPrice = []; // Reset modifier prices
        $this->selectedTablesForMerge = []; // Reset selected tables for merge
        $this->ordersToDeleteAfterMerge = []; // Reset orders to delete
        $this->mergedOrderItemIds = []; // Reset merged order item IDs
        $this->mergedCartKeys = []; // Reset merged cart keys
        $this->showMergeTableModal = false; // Close merge table modal

        // Reset loyalty points redemption
        if (module_enabled('Loyalty')) {
            $this->resetLoyaltyRedemption();
        }
        if (! $preserveBilledCustomerDisplay) {
            // Save empty cart state to cache for customer display
            $customerDisplayData = \App\Support\CustomerDisplayPayload::normalize([
                'order_number' => $this->orderNumber,
                'formatted_order_number' => $this->formattedOrderNumber,
                'items' => [],
                'sub_total' => 0,
                'discount' => 0,
                'total' => 0,
                'taxes' => [],
                'extra_charges' => [],
                'tip' => 0,
                'delivery_fee' => 0,
                'order_type' => $this->orderType,
                'status' => 'idle',
                'cash_due' => null,
            ]);

            $userId = auth()->id();
            $cacheKey = 'customer_display_cart_user_' . $userId;
            Cache::put($cacheKey, $customerDisplayData, now()->addMinutes(30));

            // Broadcast customer display update if Pusher is enabled
            if (pusherSettings()->is_enabled_pusher_broadcast) {
                broadcast(new \App\Events\CustomerDisplayUpdated($customerDisplayData, $userId));
            }
            // Optionally, still dispatch browser event
            $this->dispatch('orderUpdated', [
                'order_number' => $this->orderNumber,
                'formatted_order_number' => $this->formattedOrderNumber,
                'items' => [],
                'sub_total' => 0,
                'discount' => 0,
                'total' => 0,
            ]);
        }
    }

    public function showAddDiscount()
    {
        $resolvedOrderId = $this->orderID ?: ($this->orderDetail->id ?? null);
        $orderDetail = $resolvedOrderId ? Order::find($resolvedOrderId) : null;
        if (!$orderDetail) {
            return;
        }
        if (!$this->orderID) {
            $this->orderID = $orderDetail->id;
        }
        $this->discountType = $orderDetail->discount_type ?? $this->discountType ?? 'fixed';
        $this->discountValue = $orderDetail->discount_value ?? $this->discountValue ?? null;
        $this->showDiscountModal = true;
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
        $this->showModifiersModal = false;

        $sortNumber = Str::of(implode('', Arr::flatten($modifierIds)))
            ->split(1)->sort()->implode('');

        $keyId = $this->selectedModifierItem . '-' . $sortNumber;
        if (isset(explode('_', $this->selectedModifierItem)[1])) {
            $menuItemVariation = MenuItemVariation::find(explode('_', $this->selectedModifierItem)[1]);

            // Set price context BEFORE storing to prevent price flickering
            if ($this->orderTypeId) {
                $menuItemVariation->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }

            $this->orderItemVariation[$keyId] = $menuItemVariation;
            $this->selectedModifierItem = explode('_', $this->selectedModifierItem)[0];

            // Set price context on menu item
            if ($this->orderTypeId && isset($this->orderItemList[$keyId])) {
                $this->orderItemList[$keyId]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }

            $this->orderItemAmount[$keyId] = 1 * ($this->orderItemVariation[$keyId]->price ?? $this->orderItemList[$keyId]->price);
        }

        $this->itemModifiersSelected[$keyId] = Arr::flatten($modifierIds);
        $this->orderItemQty[$this->selectedModifierItem] = isset($this->orderItemQty[$this->selectedModifierItem]) ? ($this->orderItemQty[$this->selectedModifierItem] + 1) : 1;

        // Get modifier options with price context set
        $modifierOptions = $this->getModifierOptionsProperty();
        $modifierTotal = collect($this->itemModifiersSelected[$keyId])
            ->sum(fn($modifierId) => isset($modifierOptions[$modifierId]) ? $modifierOptions[$modifierId]->price : 0);

        $this->orderItemModifiersPrice[$keyId] = (1 * (isset($this->itemModifiersSelected[$keyId]) ? $modifierTotal : 0));

        $this->syncCart($keyId);
    }

    #[Computed]
    public function getModifierOptionsProperty()
    {
        // Get only the modifiers that are actually selected
        $selectedIds = collect($this->itemModifiersSelected)->flatten()->unique()->all();

        if (empty($selectedIds)) {
            return collect();
        }

        $modifiers = ModifierOption::with([
            'prices' => function ($q) {
                $q->where('status', true);
            }
        ])->whereIn('id', $selectedIds)->get();

        // Set price context on modifier options
        if ($this->orderTypeId) {
            $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
            foreach ($modifiers as $modifier) {
                $modifier->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
            }
        }

        return $modifiers->keyBy('id');
    }

    public function saveDeliveryExecutive()
    {
        $selectedExecutive = DeliveryExecutive::find($this->selectDeliveryExecutive);

        if (!$selectedExecutive || !$selectedExecutive->isAssignable()) {
            $this->alert('error', __('messages.invalidRequest'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            return;
        }

        $this->orderDetail->update(['delivery_executive_id' => $this->selectDeliveryExecutive]);
        $this->orderDetail->refresh();
        $this->alert('success', __('messages.deliveryExecutiveAssigned'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function cancelOrder()
    {
        if (!$this->cancelReason && !$this->cancelReasonText) {
            $this->alert('error', __('modules.settings.cancelReasonRequired'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);

            return;
        }

        if ($this->orderID) {
            $order = Order::find($this->orderID);

            if ($order) {

                $order->update([
                    'status' => 'canceled',
                    'order_status' => 'cancelled',
                    'cancel_reason_id' => $this->cancelReason,
                    'cancel_reason_text' => $this->cancelReasonText ?? null,
                    'cancelled_by' => auth()->id(),
                    'cancel_time' => Carbon::now()->setTimezone(restaurant()->timezone),
                ]);

                // Refresh the model to ensure all attributes are updated
                $order->refresh();

                Table::where('id', $order->table_id)->update([
                    'available_status' => 'available',
                ]);

                $this->alert('success', __('messages.orderCanceled'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close'),
                ]);

                $this->confirmDeleteModal = false;
                $this->cancelReason = null;
                $this->cancelReasonText = null;

                return $this->redirect(route('pos.index'), navigate: true);
            }
        }
    }

    public function updatedSelectWaiter($value)
    {
        $order = null;

        if ($this->orderID) {
            $orderID = $this->orderID;
        } elseif ($this->tableOrderID && $this->tableOrder?->activeOrder) {
            $orderID = $this->tableOrder->activeOrder->id;
        } else {
            $orderID = null;
        }

        $order = Order::with(['waiter', 'table', 'branch.restaurant', 'customer'])->find($orderID);

        if ($order) {
            $previousWaiter = $order->waiter;
            $order->update(['waiter_id' => $value ? intval($value) : null]);
            $order->refresh();
            $order->loadMissing(['waiter', 'table', 'branch.restaurant', 'customer']);

            if ($order->waiter_id) {
                OrderWaiterAssigned::dispatch($order, $previousWaiter);
            }

            $this->alert('success', __('messages.waiterUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
        } else {
            $this->selectWaiter = null;
        }
    }

    public function closeErrorModal()
    {
        $this->showErrorModal = false;
        $this->showNewKotButton = false;
    }

    protected function dispatchOrderTableAssignedEvent(Table $newTable, ?Table $previousTable = null): void
    {
        $order = null;

        if ($this->orderID) {
            $orderId = $this->orderID;
        } elseif ($this->tableOrderID && $this->tableOrder?->activeOrder) {
            $orderId = $this->tableOrder->activeOrder->id;
        } else {
            $orderId = null;
        }

        $order = Order::with(['waiter', 'table', 'branch.restaurant', 'customer'])->find($orderId);

        if (!$order) {
            return;
        }

        OrderTableAssigned::dispatch($order, $newTable, $previousTable);
    }

    #[Computed]
    public function menuItems()
    {
        // Get normalized delivery app ID once for reuse
        $normalizedDeliveryAppId = $this->normalizeDeliveryAppId();
        $orderTypeId = $this->orderTypeId;
        $filterCategories = $this->filterCategories;
        $menuId = $this->menuId;
        $search = $this->search;
        $limit = $this->menuItemsLoaded;

        $cacheKey = sprintf(
            'pos.menu_items.%s.%s',
            branch()->id,
            md5(json_encode([
                'orderTypeId' => $orderTypeId,
                'deliveryAppId' => $normalizedDeliveryAppId,
                'filterCategories' => $filterCategories,
                'menuId' => $menuId,
                'search' => $search,
                'limit' => $limit,
            ]))
        );

        $query = Cache::remember(
            $cacheKey,
            now()->addHours(2),
            function () use ($orderTypeId, $normalizedDeliveryAppId, $filterCategories, $menuId, $search, $limit) {
                $builder = MenuItem::withCount('variations', 'modifierGroups')
                    ->with([
                        // Contextually eager load prices for items based on current order type and delivery app
                        'prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                            $q->where('status', true)
                                ->whereNull('menu_item_variation_id'); // Only item-level prices

                            if ($orderTypeId) {
                                $q->where(function ($query) use ($orderTypeId) {
                                    $query->where('order_type_id', $orderTypeId);
                                });
                            }

                            if ($normalizedDeliveryAppId) {
                                $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                    $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                        ->orWhereNull('delivery_app_id');
                                });
                            } else {
                                $q->whereNull('delivery_app_id');
                            }
                        },
                        // Contextually eager load variations with their prices
                        'variations.prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                            $q->where('status', true);

                            if ($orderTypeId) {
                                $q->where(function ($query) use ($orderTypeId) {
                                    $query->where('order_type_id', $orderTypeId);
                                });
                            }

                            if ($normalizedDeliveryAppId) {
                                $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                    $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                        ->orWhereNull('delivery_app_id');
                                });
                            } else {
                                $q->whereNull('delivery_app_id');
                            }
                        },
                        // Contextually eager load modifier options and their prices
                        'modifierGroups.options.prices' => function ($q) use ($orderTypeId, $normalizedDeliveryAppId) {
                            $q->where('status', true);

                            if ($orderTypeId) {
                                $q->where(function ($query) use ($orderTypeId) {
                                    $query->where('order_type_id', $orderTypeId);
                                });
                            }

                            if ($normalizedDeliveryAppId) {
                                $q->where(function ($query) use ($normalizedDeliveryAppId) {
                                    $query->where('delivery_app_id', $normalizedDeliveryAppId)
                                        ->orWhereNull('delivery_app_id');
                                });
                            } else {
                                $q->whereNull('delivery_app_id');
                            }
                        }
                    ]);

                if (!empty($filterCategories)) {
                    $builder = $builder->where('item_category_id', $filterCategories);
                }

                if (!empty($menuId)) {
                    $builder = $builder->where('menu_id', $menuId);
                }

                return $builder
                    ->matchesSearch($search)
                    ->take($limit)
                    ->get();
            }
        );

        if ($this->orderTypeId) {
            foreach ($query as $menuItem) {
                $menuItem->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);

                // Set context on variations
                foreach ($menuItem->variations as $variation) {
                    $variation->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                }

                // Set context on modifier options
                foreach ($menuItem->modifierGroups as $group) {
                    foreach ($group->options as $option) {
                        $option->setPriceContext($this->orderTypeId, $normalizedDeliveryAppId);
                    }
                }
            }
        }

        return $query;
    }

    #[Computed]
    public function orderTypes()
    {
        $showCustomOrderTypes = restaurant()->show_order_type_options;
        return OrderType::where('branch_id', branch()->id)
            ->where('is_active', true)
            ->when(!$showCustomOrderTypes, fn($q) => $q->where('is_default', true))
            ->availableForRestaurant()
            ->get();
    }

    #[Computed]
    public function allItemsLoaded()
    {
        return $this->menuItemsLoaded >= $this->totalMenuItemsCount;
    }

    #[Computed]
    public function totalMenuItemsCount()
    {
        $query = MenuItem::query();

        if (!empty($this->filterCategories)) {
            $query = $query->where('item_category_id', $this->filterCategories);
        }

        if (!empty($this->menuId)) {
            $query = $query->where('menu_id', $this->menuId);
        }

        return $query->matchesSearch($this->search)->count();
    }

    public function openRoomModal()
    {
        if (!module_enabled('Hotel')) {
            return;
        }
        $this->showRoomModal = true;
        $this->roomSearch = ''; // Reset search when opening modal
    }

    /**
     * Get active stays for room service (computed property)
     */
    #[Computed]
    public function stays()
    {
        if (!$this->isRoomServiceOrder()) {
            return collect();
        }

        $query = \Modules\Hotel\Entities\Stay::where('status', \Modules\Hotel\Enums\StayStatus::CHECKED_IN)
            ->with(['room.roomType', 'stayGuests.guest']);

        // Filter by search term (stay number or room number)
        if (!empty($this->roomSearch)) {
            $searchTerm = '%' . $this->roomSearch . '%';
            $query->where(function($q) use ($searchTerm) {
                $q->where('stay_number', 'like', $searchTerm)
                  ->orWhereHas('room', function($roomQuery) use ($searchTerm) {
                      $roomQuery->where('room_number', 'like', $searchTerm);
                  });
            });
        }

        return $query->get();
    }

    public function setStay($stayId)
    {
        if (!module_enabled('Hotel')) {
            return;
        }

        $this->selectedStayId = $stayId;
        $this->showRoomModal = false;

        if ($stayId) {
            $stay = \Modules\Hotel\Entities\Stay::with(['room.roomType', 'stayGuests.guest'])->find($stayId);
            if ($stay && $stay->stayGuests->isNotEmpty()) {
                $primaryGuest = $stay->stayGuests->where('is_primary', true)->first()
                    ?? $stay->stayGuests->first();
                if ($primaryGuest && $primaryGuest->guest) {
                    // Try to find customer by email or phone
                    $customer = \App\Models\Customer::where('email', $primaryGuest->guest->email)
                        ->orWhere('phone', $primaryGuest->guest->phone)
                        ->first();
                    if ($customer) {
                        $this->setCustomer($customer->id);
                    }
                }
            }
        }
    }

    protected function postOrderToFolio($order)
    {
        if (!module_enabled('Hotel') || !$order->context_id) {
            return;
        }

        try {
            $stay = \Modules\Hotel\Entities\Stay::with('folio')->find($order->context_id);
            if (!$stay || !$stay->folio) {
                return;
            }

            $folio = $stay->folio;
            if ($folio->status !== \Modules\Hotel\Enums\FolioStatus::OPEN) {
                return;
            }

            // Use HotelHelper to post order to folio
            if (class_exists(\Modules\Hotel\Helpers\HotelHelper::class)) {
                \Modules\Hotel\Helpers\HotelHelper::postOrderToFolio($order, $folio);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to post order to folio: ' . $e->getMessage());
        }
    }

    /**
     * Sync MultiPOS device registration flags from the current request (cookie + branch).
     * Used from render() and before cart mutations so Livewire cannot add items while POS is blocked.
     */
    protected function refreshMultiPosRegistrationState(): void
    {
        $this->shouldBlockPos = false;
        $this->hasPosMachine = false;
        $this->machineStatus = null;
        $this->posMachine = null;
        $this->limitReached = false;
        $this->limitMessage = '';

        $multiPosEnabled = module_enabled('MultiPOS') && in_array('MultiPOS', restaurant_modules());

        if (!$multiPosEnabled) {
            return;
        }

        $cookieName = config('multipos.cookie.name', 'pos_token');
        $deviceId = request()->cookie($cookieName);

        if ($deviceId) {
            $this->posMachine = \Modules\MultiPOS\Entities\PosMachine::where('device_id', $deviceId)
                ->where('branch_id', branch()->id)
                ->first();

            if ($this->posMachine) {
                $this->hasPosMachine = true;
                if (auth()->check()) {
                    try {
                        $this->posMachine->activateIfPendingRegisteredByApprover(auth()->user());
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Failed to activate POS machine: ' . $e->getMessage());
                    }
                }
                $this->machineStatus = $this->posMachine->status;
            }
        }

        if (!$this->hasPosMachine) {
            $restaurant = branch()->restaurant;
            $packageLimit = optional($restaurant->package)->multipos_limit;
            if (!is_null($packageLimit) && $packageLimit >= 0) {
                $currentCount = \Modules\MultiPOS\Entities\PosMachine::where('branch_id', branch()->id)
                    ->whereIn('status', ['active', 'pending'])
                    ->count();

                if ($currentCount >= $packageLimit) {
                    $this->limitReached = true;
                    $this->limitMessage = __('multipos::messages.registration.limit_reached.message', ['limit' => $packageLimit]);
                }
            }
        }

        $this->shouldBlockPos = !$this->hasPosMachine || $this->machineStatus === 'pending' || $this->machineStatus === 'declined';
    }

    public function render()
    {
        // Only generate order number if there is no existing order or table order without active order
        // Don't generate order number if we already have one set
        if ((!$this->orderID && !$this->tableOrderID) || ($this->tableOrderID && !$this->tableOrder->activeOrder)) {
            // Only generate if we don't already have an order number set
            if (!$this->orderNumber) {
                // Check if we're loading a draft order
                if ($this->orderID) {
                    $order = Order::find($this->orderID);
                    if ($order && $order->status === 'draft' && !$order->order_number) {
                        $this->orderNumber = __('modules.order.draftOrder');
                        $this->formattedOrderNumber = __('modules.order.draftOrder');
                    } else {
                        $orderNumberData = Order::generateOrderNumber(branch());
                        $this->orderNumber = $orderNumberData['order_number'];
                        $this->formattedOrderNumber = $orderNumberData['formatted_order_number'];
                    }
                } else {
                    $orderNumberData = Order::generateOrderNumber(branch());
                    $this->orderNumber = $orderNumberData['order_number'];
                    $this->formattedOrderNumber = $orderNumberData['formatted_order_number'];
                }
            }
        } elseif ($this->orderID && $this->orderDetail) {
            // If we have an order loaded, ensure order number is synced from the order
            // This handles the case when a draft order is converted to a real order or when draft order has order number
            if ($this->orderDetail->order_number) {
                $this->orderNumber = $this->orderDetail->order_number;
                $this->formattedOrderNumber = $this->orderDetail->formatted_order_number ?? $this->orderDetail->order_number;
            }
        }

        $orderTypes = $this->orderTypes;
        $orderTypeFirst = $orderTypes->first();

        if ($orderTypes->count() === 1 && (in_array($orderTypeFirst->slug, ['dine_in', 'pickup']))) {
            $this->orderTypeSlug = $orderTypeFirst->slug;
            $this->orderType = $orderTypeFirst->type;
            $this->orderTypeId = $orderTypeFirst->id;
        }

        $this->showRestaurantClosedBanner = false;
        $this->refreshMultiPosRegistrationState();

        $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, branch());
        if (!($availability['is_open'] ?? true)) {
            $this->restaurantClosedMessage = RestaurantAvailabilityService::getMessage($availability, $this->restaurant);
            $this->showRestaurantClosedBanner = true;
        }

        $branch = branch();
        $deliveryPlatforms = Cache::remember(
            'delivery_platforms_' . $this->restaurant->id,
            60 * 60 * 24,
            fn () => DeliveryPlatform::where('is_active', true)->orderBy('name')->get()
        );

        return view('livewire.pos.pos', array_merge(
            PosOrderTypeClientData::buildModalScriptPayload(
                (int) $branch->id,
                $branch,
                $this->orderTypes,
                $deliveryPlatforms
            ),
            ['modalDeliveryPlatforms' => $deliveryPlatforms]
        ));
    }

    // Update item notes and save to database if applicable
    public function updateItemNote($itemId, $note)
    {
        $this->itemNotes[$itemId] = $note;

        if (!$this->orderDetail) {
            return;
        }

        // Extract the KOT ID and item ID from the itemId string
        $parts = explode('_', str_replace('"', '', $itemId));

        // Handle draft orders - they have order_item_ prefix
        if (count($parts) >= 3 && $parts[0] === 'order' && $parts[1] === 'item') {
            $orderItemId = $parts[2];
            OrderItem::where('id', $orderItemId)->update(['note' => $note]);
            return;
        }

        if (count($parts) < 3 || $parts[0] !== 'kot') {
            return;
        }

        KotItem::where('kot_id', $parts[1])
            ->where('id', $parts[2])
            ->update(['note' => $note]);
    }

    public function updateOrderItemTaxDetails()
    {
        $this->orderItemTaxDetails = [];

        if ($this->taxMode !== 'item' || !is_array($this->orderItemAmount)) {
            return;
        }

        // Use the actual orderItemAmount values which already have discounts applied
        // Don't recalculate discounts - use the actual discounted amounts
        foreach ($this->orderItemAmount as $key => $value) {
            $menuItem = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->menuItem : $this->orderItemList[$key];

            // Set price context before using price
            if ($this->orderTypeId) {
                $menuItem->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());

                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
            }

            $qty = $this->orderItemQty[$key] ?? 1;
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $menuItem->price;
            $modifierPrice = $this->orderItemModifiersPrice[$key] ?? 0;
            $itemPriceWithModifiers = $basePrice + $modifierPrice;

            // Use the actual discounted amount from orderItemAmount (already has discounts applied)
            // Calculate per-unit price after discount and round to 2 decimal places
            $itemPriceAfterDiscount = $qty > 0 ? round($value / $qty, 2) : $itemPriceWithModifiers;

            $taxes = $menuItem->taxes ?? collect();
            $isInclusive = restaurant()->tax_inclusive;

            // Calculate taxes on the discounted price
            $taxResult = MenuItem::calculateItemTaxes($itemPriceAfterDiscount, $taxes, $isInclusive);

            // Calculate display price (for tax-inclusive, subtract tax from discounted price)
            $displayPrice = $isInclusive ? ($itemPriceAfterDiscount - ($taxResult['tax_amount'] ?? 0)) : $itemPriceAfterDiscount;
            $displayPrice = round($displayPrice, 2);

            $this->orderItemTaxDetails[$key] = [
                'tax_amount' => round($taxResult['tax_amount'] * $qty, 2),
                'tax_percent' => $taxResult['tax_percentage'],
                'tax_breakup' => $taxResult['tax_breakdown'],
                'tax_type' => $taxResult['inclusive'],
                'base_price' => round($itemPriceWithModifiers, 2),
                'discounted_price' => $itemPriceAfterDiscount,
                'display_price' => $displayPrice,
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

        // Check if we have session data arrays (for active POS session)
        if (isset($this->orderItemList[$key])) {
            // Set price context before using price
            if ($this->orderTypeId) {
                $this->orderItemList[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
            }

            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $this->orderItemList[$key]->price;
            $modifierPrice = $this->orderItemModifiersPrice[$key] ?? 0;
            return $basePrice + $modifierPrice;
        }

        // For existing order items (when viewing order details), calculate from the order item itself
        if ($this->orderDetail && isset($this->orderDetail->items[$key])) {
            $orderItem = $this->orderDetail->items[$key];

            // Set price context on menu items and variations
            if ($this->orderTypeId) {
                $orderItem->menuItem->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                if ($orderItem->menuItemVariation) {
                    $orderItem->menuItemVariation->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
                // Set price context on modifier options
                foreach ($orderItem->modifierOptions as $modifierOption) {
                    $modifierOption->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
            }

            $basePrice = !is_null($orderItem->menuItemVariation) ? $orderItem->menuItemVariation->price : $orderItem->menuItem->price;
            $modifierPrice = $orderItem->modifierOptions->sum('price');

            // If tax is inclusive, calculate the display price without tax
            if (restaurant()->tax_inclusive && restaurant()->tax_mode === 'item') {
                $menuItem = $orderItem->menuItem;
                $taxes = $menuItem->taxes ?? collect();
                $itemPriceWithModifiers = $basePrice + $modifierPrice;

                if ($taxes->isNotEmpty()) {
                    $taxPercent = $taxes->sum('tax_percent');
                    $displayPrice = $itemPriceWithModifiers / (1 + $taxPercent / 100);
                    return $displayPrice;
                }
            }

            return $basePrice + $modifierPrice;
        }

        return 0;
    }

    // Add a helper to format items for customer display
    private function getCustomerDisplayItems()
    {
        $selectableEu = restaurant()->selectableEuAllergenKeys();
        $euEnabled = $selectableEu !== [];

        $items = [];
        foreach ($this->orderItemList as $key => $item) {
            // Set price context before using prices
            if ($this->orderTypeId) {
                $item->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                if (isset($this->orderItemVariation[$key])) {
                    $this->orderItemVariation[$key]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                }
            }

            $variation = $this->orderItemVariation[$key] ?? null;
            $basePrice = $variation->price ?? $item->price ?? 0;
            $modifiers = [];
            $modifierTotal = 0;
            if (!empty($this->itemModifiersSelected[$key])) {
                foreach ($this->itemModifiersSelected[$key] as $modifierId) {
                    $modifier = \App\Models\ModifierOption::find($modifierId);
                    if ($modifier) {
                        // Set price context for modifier
                        if ($this->orderTypeId) {
                            $modifier->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
                        }
                        $modifiers[] = [
                            'name' => $modifier->name,
                            'price' => $modifier->price,
                        ];
                        $modifierTotal += $modifier->price;
                    }
                }
            }
            $totalUnitPrice = $basePrice + $modifierTotal;

            $euAllergenKeys = [];
            if ($euEnabled) {
                $stored = is_object($item) && isset($item->eu_allergen_keys)
                    ? (array) $item->eu_allergen_keys
                    : (array) ($item['eu_allergen_keys'] ?? []);
                $euAllergenKeys = array_values(array_unique(array_intersect(
                    EuAnnexIiAllergens::keys(),
                    $selectableEu,
                    array_filter($stored, 'is_string')
                )));
            }

            $storedDietary = is_object($item) && isset($item->dietary_labels)
                ? (array) $item->dietary_labels
                : (array) ($item['dietary_labels'] ?? []);
            $dietaryLabels = DietaryLabels::normalize($storedDietary);

            $qty = $this->orderItemQty[$key] ?? 1;
            $sellByWeight = (bool) (is_object($item)
                ? ($item->sell_by_weight ?? false)
                : ($item['sell_by_weight'] ?? false));

            $items[] = [
                'name' => $item->item_name ?? ($item['name'] ?? 'Item'),
                'qty' => $qty,
                'qty_label' => \App\Support\PosQuantity::formatDisplay($qty, $sellByWeight),
                'price' => $basePrice, // keep for reference
                'total_unit_price' => $totalUnitPrice, // <-- add this
                'variation' => $variation ? [
                    'name' => $variation->variation ?? null,
                    'price' => $variation->price ?? null,
                ] : null,
                'modifiers' => $modifiers,
                'notes' => $this->itemNotes[$key] ?? null,
                'eu_allergen_keys' => $euAllergenKeys,
                'dietary_labels' => $dietaryLabels,
            ];
        }
        return $items;
    }

    public function newOrder()
    {
        $this->resetPos();

        // Set the default order type after reset
        $defaultOrderType = OrderType::where('branch_id', branch()->id)
            ->where('is_active', true)
            ->first();

        if ($defaultOrderType) {
            $this->orderTypeId = $defaultOrderType->id;
            $this->orderType = $defaultOrderType->type;
            $this->orderTypeSlug = $defaultOrderType->slug;
        }

        $this->setCustomerDisplayStatus('idle');
        $this->calculateTotal();
    }

    public function updateQty($id)
    {
        if (($this->orderID && !user_can('Update Order')) || (!$this->orderID && !user_can('Create Order'))) {
            return;
        }
        // Force consistent order id for stamp recalculation
        if (!$this->orderID && $this->orderDetail && $this->orderDetail->id) {
            $this->orderID = $this->orderDetail->id;
        }

        // If this is a free stamp item, cap qty by eligible items and available stamps
        if ($this->isFreeStampKey($id) && module_enabled('Loyalty')) {
            [$maxAllowed, $eligibleQty, $maxByStamps] = $this->getFreeStampLimitsForKey($id);
            $desired = intval($this->orderItemQty[$id]);
            if ($desired > $maxAllowed) {
                $reason = $maxByStamps < $eligibleQty ? __('loyalty::app.insufficientStamps') : __('messages.maxLimitReached');
                $this->alert('info', $reason, ['toast' => true, 'position' => 'top-end']);
            }
            $this->orderItemQty[$id] = min(max(1, $desired), max(1, $maxAllowed));
            $this->orderItemAmount[$id] = 0;
            $this->calculateTotal();
            return;
        }
        // Ensure quantity is at least 1
        $this->orderItemQty[$id] = max(1, intval($this->orderItemQty[$id]));

        // Set price context before using price
        if ($this->orderTypeId) {
            if (isset($this->orderItemVariation[$id])) {
                $this->orderItemVariation[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
            if (isset($this->orderItemList[$id])) {
                $this->orderItemList[$id]->setPriceContext($this->orderTypeId, $this->normalizeDeliveryAppId());
            }
        }

        // Update the amount based on the new quantity
        $basePrice = $this->orderItemVariation[$id]->price ?? $this->orderItemList[$id]->price;
        $this->orderItemAmount[$id] = $this->orderItemQty[$id] * ($basePrice + ($this->orderItemModifiersPrice[$id] ?? 0));

        // Skip stamp/points redemption triggers on KOT detail page
        if (!$this->isOrderDetailKotView()) {
            // Re-evaluate stamp redemption when quantity is edited
            $this->checkAndAutoRedeemStampsForItem($id);
        }
        // Recalculate the total
        $this->calculateTotal();
    }

    private function isOrderDetailKotView(): bool
    {
        return $this->orderDetail instanceof Order
            && ($this->orderDetail->status ?? null) === 'kot';
    }

    /**
     * Set the customer display status and immediately update the cache.
     */
    public function setCustomerDisplayStatus($status)
    {
        $this->customerDisplayStatus = $status;
        $this->calculateTotal();
    }

    /**
     * Confirm that the customer is the same as the reservation
     */
    public function confirmSameCustomer()
    {
        $this->isSameCustomer = true;
        $this->showReservationModal = false;
        $this->saveOrder($this->intendedOrderAction ?? 'kot');
    }

    /**
     * Confirm that the customer is different from the reservation
     */
    public function confirmDifferentCustomer()
    {
        $this->isSameCustomer = false;
        $this->showReservationModal = false;
        $this->saveOrder($this->intendedOrderAction ?? 'kot');
    }

    /**
     * Close the reservation modal
     */
    public function closeReservationModal()
    {
        $this->showReservationModal = false;
        $this->reservationId = null;
        $this->reservationCustomer = null;
        $this->reservation = null;
        $this->isSameCustomer = false;
        $this->intendedOrderAction = null;
    }

    /**
     * Reset reservation properties
     */
    public function resetReservationProperties()
    {
        $this->reservationId = null;
        $this->reservationCustomer = null;
        $this->reservation = null;
        $this->isSameCustomer = false;
        $this->intendedOrderAction = null;
    }

    /**
     * Refresh order data when KOT items are cancelled
     */
    public function refreshOrderData($orderId)
    {
        // Only refresh if we're currently viewing this order
        if ($this->orderID == $orderId) {
            // Refresh the order detail
            $this->orderDetail = Order::with(['kot.items.menuItem', 'kot.items.menuItemVariation', 'kot.items.modifierOptions'])->find($orderId);

            // Reset and reload order items
            $this->orderItemList = [];
            $this->orderItemQty = [];
            $this->orderItemAmount = [];
            $this->orderItemVariation = [];
            $this->itemModifiersSelected = [];
            $this->itemNotes = [];
            $this->orderItemModifiersPrice = [];
            $this->orderItemTaxDetails = [];

            // Reload order items from KOT items
            $this->setupOrderItems();
        }
    }

    #[Computed]
    public function categoryList()
    {
        return ItemCategory::select('id', 'category_name')
            ->withCount(['items' => function ($query) {
                if (!empty($this->menuId)) {
                    $query->where('menu_id', $this->menuId);
                }
            }])->having('items_count', '>', 0)->get();
    }

    /**
     * Load more menu items on scroll
     */
    public function loadMoreMenuItems()
    {
        // Don't load more if all items are already loaded
        if ($this->allItemsLoaded) {
            return;
        }

        $this->menuItemsLoaded += $this->menuItemsPerPage;
    }

    /**
     * Reset loaded items count when filters change
     */
    public function updatedSearch()
    {
        $this->menuItemsLoaded = $this->menuItemsPerPage;
    }

    public function updatedFilterCategories()
    {
        $this->menuItemsLoaded = $this->menuItemsPerPage;
    }

    public function updatedMenuId()
    {
        $this->menuItemsLoaded = $this->menuItemsPerPage;
    }

    /**
     * Get the assigned waiter from assign_waiter_to_tables if table is assigned to order
     * Returns null if no table assignment exists
     */
    #[Computed]
    public function assignedWaiterFromTable()
    {
        if (!$this->tableId) {
            return null;
        }

        $today = now()->format('Y-m-d');

        $assignment = DB::table('assign_waiter_to_tables')
            ->where('table_id', $this->tableId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->orderBy('created_at', 'desc')
            ->first();

        // Use waiter_id if available, otherwise fallback to backup_waiter_id
        $waiterId = $assignment?->waiter_id ?? $assignment?->backup_waiter_id ?? null;

        $restricted = PosWaitersCache::actorIsRestrictedPosWaiter(user(), (int) $this->restaurant->id);

        if ($waiterId && !$restricted) {
            $this->selectWaiter = $waiterId;
        }

        return ($waiterId && !$restricted) ? User::find($waiterId) : null;
    }

    /**
     * Get the current waiter - prioritizes assigned waiter from table, then order's selected waiter
     */
    #[Computed]
    public function currentWaiter()
    {
        // First try to get waiter from table assignment
        $waiter = $this->assignedWaiterFromTable;

        // If no table assignment, get from order's selected waiter
        if (!$waiter && $this->selectWaiter && $this->users) {
            $waiter = $this->users->firstWhere('id', $this->selectWaiter);
        }

        return $waiter;
    }

    /**
     * Get the waiter name to display - prioritizes assigned waiter from table, then order's waiter
     */
    #[Computed]
    public function waiterName()
    {
        $waiter = $this->currentWaiter;
        return $waiter?->name ?? __('modules.order.selectWaiter');
    }

    /**
     * Check if waiter is locked (assigned to table)
     */
    #[Computed]
    public function isWaiterLocked()
    {
        if (PosWaitersCache::actorIsRestrictedPosWaiter(user(), (int) $this->restaurant->id)) {
            return true;
        }

        return $this->assignedWaiterFromTable !== null;
    }

    /**
     * Sync selectWaiter with assigned waiter from table if available
     */
    private function syncWaiterFromTableAssignment()
    {
        if (!$this->tableId || $this->orderID) {
            return;
        }

        $assignedWaiter = $this->assignedWaiterFromTable;
        if ($assignedWaiter) {
            $this->selectWaiter = $assignedWaiter->id;
        }
    }


    /**
     * @param \App\Models\Order $order
     * @param int $stampRuleId
     * @return void
     */
    private function billKotOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // CRITICAL: Refresh order to ensure we have latest data
            $order->refresh();

            // CRITICAL: Check if order is already billed and has items - prevent reprocessing
            if ($order->status === 'billed' && $order->items()->count() > 0) {
                // Check if all kot_items are already linked to order_items
                $order->load('kot.items');
                $allItemsLinked = true;
                $unlinkedKotItems = [];

                foreach ($order->kot as $kot) {
                    foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                        if (!$kotItem->order_item_id) {
                            $allItemsLinked = false;
                            $unlinkedKotItems[] = $kotItem->id;
                        }
                    }
                }

                if ($allItemsLinked) {
                    return; // Already processed
                }
            }

            // Ensure KOT relationships are loaded with all necessary data
            $order->load([
                'kot.items.menuItem',
                'kot.items.menuItemVariation',
                'kot.items.modifierOptions',
                'kot.items.orderItem',
                'items' // Load existing order_items to check for duplicates
            ]);

            // Log total kot_items count for debugging
            $totalKotItemsCount = 0;
            $nonCancelledKotItemsCount = 0;
            foreach ($order->kot as $kot) {
                $totalKotItemsCount += $kot->items->count();
                $nonCancelledKotItemsCount += $kot->items->where('status', '!=', 'cancelled')->count();
            }

            // Copy all items from kot_items to order_items
            foreach ($order->kot as $kot) {
                // Ensure items relationship is loaded for this KOT
                if (!$kot->relationLoaded('items')) {
                    $kot->load('items.menuItem', 'items.menuItemVariation', 'items.modifierOptions', 'items.orderItem');
                }

                // Get all non-cancelled kot_items (include null status as valid)
                $kotItems = $kot->items->filter(function ($item) {
                    return $item->status !== 'cancelled';
                });

                foreach ($kotItems as $kotItem) {
                    // Check if this kot_item is already linked to an OrderItem
                    $existingOrderItem = null;
                    if ($kotItem->order_item_id) {
                        // Refresh items relationship to ensure we have latest data
                        if (!$order->relationLoaded('items')) {
                            $order->load('items');
                        }

                        // Check if the linked OrderItem exists in the loaded relationship or database
                        $existingOrderItem = $order->items->firstWhere('id', $kotItem->order_item_id);
                        if (!$existingOrderItem) {
                            // Not in loaded relationship, check database
                            $existingOrderItem = $order->items()->find($kotItem->order_item_id);
                        }

                        if ($existingOrderItem) {
                            // Item already linked and exists - skip to avoid duplicates
                            continue;
                        }
                        // Linked order_item doesn't exist (was deleted?) - proceed to create new one
                    }

                    // If not linked, check for existing OrderItem with matching characteristics
                    // CRITICAL: Only check if kot_item doesn't have order_item_id
                    // If it has order_item_id but item doesn't exist, we need to create a new one
                    if (!$existingOrderItem && !$kotItem->order_item_id) {
                        // Refresh items relationship to check for newly created items
                        if (!$order->relationLoaded('items')) {
                            $order->load('items');
                        }

                        // CRITICAL: Check for existing OrderItem that matches this kot_item
                        // Match by: menu_item_id, variation_id, free_item flag, stamp_rule_id, AND quantity
                        // Also check that the existing item doesn't already have a different kot_item linked
                        $existingOrderItem = $order->items->first(function ($item) use ($kotItem) {
                            // Must match all these criteria
                            $matches = $item->menu_item_id == $kotItem->menu_item_id
                                && $item->menu_item_variation_id == $kotItem->menu_item_variation_id
                                && $item->is_free_item_from_stamp == ($kotItem->is_free_item_from_stamp ?? false)
                                && $item->stamp_rule_id == $kotItem->stamp_rule_id
                                && $item->quantity == $kotItem->quantity;

                            // Also check that this order_item isn't already linked to a different kot_item
                            if ($matches) {
                                // Check if any kot_item is already linked to this order_item
                                $linkedKotItem = \App\Models\KotItem::where('order_item_id', $item->id)
                                    ->where('id', '!=', $kotItem->id)
                                    ->first();
                                if ($linkedKotItem) {
                                    // Another kot_item is already linked - don't reuse this order_item
                                    return false;
                                }
                            }

                            return $matches;
                        });

                        // If not found in loaded relationship, check database
                        if (!$existingOrderItem) {
                            // Query database but exclude items already linked to other kot_items
                            // Check if any kot_item is already linked to potential matching order_items
                            $linkedOrderItemIds = \App\Models\KotItem::where('order_item_id', '!=', null)
                                ->where('id', '!=', $kotItem->id)
                                ->pluck('order_item_id')
                                ->toArray();

                            $existingOrderItem = $order->items()
                                ->where('menu_item_id', $kotItem->menu_item_id)
                                ->where('menu_item_variation_id', $kotItem->menu_item_variation_id)
                                ->where('is_free_item_from_stamp', $kotItem->is_free_item_from_stamp ?? false)
                                ->where('stamp_rule_id', $kotItem->stamp_rule_id)
                                ->where('quantity', $kotItem->quantity)
                                ->whereNotIn('id', $linkedOrderItemIds)
                                ->first();
                        }
                    }

                    if (!$existingOrderItem) {
                        // Get tax details if item-level tax mode
                        $taxAmount = null;
                        $taxPercentage = null;
                        $taxBreakup = null;
                        if ($this->taxMode === 'item' && isset($this->orderItemTaxDetails)) {
                            // Try to find tax details from cart (if available)
                            foreach ($this->orderItemList as $key => $cartItem) {
                                if ($cartItem->id == $kotItem->menu_item_id) {
                                    $taxAmount = $this->orderItemTaxDetails[$key]['tax_amount'] ?? null;
                                    $taxPercentage = $this->orderItemTaxDetails[$key]['tax_percent'] ?? null;
                                    $taxBreakup = isset($this->orderItemTaxDetails[$key]['tax_breakup'])
                                        ? json_encode($this->orderItemTaxDetails[$key]['tax_breakup'])
                                        : null;
                                    break;
                                }
                            }
                        }

                        // CRITICAL: Calculate amount correctly
                        // If kot_item has amount set, use it (preserves discounts and free items)
                        // Otherwise calculate from price * quantity
                        $itemAmount = $kotItem->amount ?? 0;
                        if ($itemAmount == 0 && !($kotItem->is_free_item_from_stamp ?? false)) {
                            // Calculate amount from price if not set and not free
                            $itemPrice = $kotItem->price ?? ($kotItem->menuItem->price ?? 0);
                            $itemAmount = $itemPrice * $kotItem->quantity;

                            // Apply discount if kot_item has discount_amount
                            if ($kotItem->discount_amount && $kotItem->discount_amount > 0) {
                                $itemAmount -= $kotItem->discount_amount;
                            }
                        }

                        $orderItem = OrderItem::create([
                            'order_type' => $order->order_type,
                            'order_type_id' => $order->order_type_id,
                            'order_id' => $order->id,
                            'menu_item_id' => $kotItem->menu_item_id,
                            'menu_item_variation_id' => $kotItem->menu_item_variation_id,
                            'quantity' => $kotItem->quantity,
                            'price' => $kotItem->price ?? ($kotItem->menuItem->price ?? 0),
                            'amount' => max(0, round($itemAmount, 2)), // Preserve amount from kot_items (0 for free items, discounted for discounted items)
                            'note' => $kotItem->note,
                            'tax_amount' => $taxAmount,
                            'tax_percentage' => $taxPercentage,
                            'tax_breakup' => $taxBreakup,
                            'is_free_item_from_stamp' => $kotItem->is_free_item_from_stamp ?? false,
                            'stamp_rule_id' => $kotItem->stamp_rule_id,
                        ]);

                        // Copy modifiers from kot_item
                        $kotItem->load('modifierOptions');
                        $orderItem->modifierOptions()->sync($kotItem->modifierOptions->pluck('id')->toArray());

                        // Link kot_item to order_item
                        $kotItem->update(['order_item_id' => $orderItem->id]);
                        $order->load('items');
                    } else {
                        // OrderItem already exists - link kot_item to it if not already linked
                        if (!$kotItem->order_item_id) {
                            $kotItem->update(['order_item_id' => $existingOrderItem->id]);
                        }
                    }
                }
            }

            // Final verification: Log how many items were created
            $order->refresh();
            $order->load('items');

            // Save order-level taxes if needed
            if ($this->taxMode === 'order') {
                // Check if taxes already exist (to avoid duplicates)
                $existingTaxIds = $order->taxes()->pluck('tax_id')->toArray();

                foreach ($this->taxes as $tax) {
                    // Only create if tax doesn't already exist for this order
                    if (!in_array($tax->id, $existingTaxIds)) {
                        OrderTax::create([
                            'order_id' => $order->id,
                            'tax_id' => $tax->id
                        ]);
                    }
                }
            }

            // CRITICAL: Redeem loyalty stamps/points AFTER OrderItems are created
            // Only redeem stamps if they were actually applied in POS (not just because items have stamp_rule_id)
            if ($order->customer_id) {
                $stampRuleIdsToRedeem = [];

                // Check 1: selectedStampRuleId (explicit selection)
                if ($this->selectedStampRuleId) {
                    $stampRuleIdsToRedeem[] = $this->selectedStampRuleId;
                }

                // Check 2: Items with discounts applied (stamp discounts reduce amount)
                $order->load('items');
                foreach ($order->items as $orderItem) {
                    // Skip free items (they're handled separately)
                    if ($orderItem->is_free_item_from_stamp ?? false) {
                        if ($orderItem->stamp_rule_id && !in_array($orderItem->stamp_rule_id, $stampRuleIdsToRedeem)) {
                            $stampRuleIdsToRedeem[] = $orderItem->stamp_rule_id;
                        }
                        continue;
                    }

                    // Check if item has a discount applied (amount < expected)
                    $expectedAmount = (float)($orderItem->price ?? 0) * (int)($orderItem->quantity ?? 1);
                    $actualAmount = (float)($orderItem->amount ?? 0);

                    // If actual amount is significantly less than expected, discount was applied
                    if ($expectedAmount > $actualAmount + 0.01 && $orderItem->stamp_rule_id) {
                        if (!in_array($orderItem->stamp_rule_id, $stampRuleIdsToRedeem)) {
                            $stampRuleIdsToRedeem[] = $orderItem->stamp_rule_id;
                        }
                    }
                }

                // Check 3: Free items in kot_items (indicates stamps were applied)
                foreach ($order->kot as $kot) {
                    foreach ($kot->items as $kotItem) {
                        if (($kotItem->is_free_item_from_stamp ?? false) && $kotItem->stamp_rule_id) {
                            if (!in_array($kotItem->stamp_rule_id, $stampRuleIdsToRedeem)) {
                                $stampRuleIdsToRedeem[] = $kotItem->stamp_rule_id;
                            }
                        }
                    }
                }

                // Only redeem if stamps were actually applied in POS
                if (empty($stampRuleIdsToRedeem)) {
                    // Skip stamp redemption - no stamps were applied in POS
                } else {
                    // Redeem stamps for each stamp rule ONCE
                    // The helper method handles all duplicate checking internally
                    foreach ($stampRuleIdsToRedeem as $stampRuleIdToRedeem) {
                        if (!$stampRuleIdToRedeem || !$this->isStampsEnabledForPOS()) {
                            continue;
                        }

                        if (module_enabled('Loyalty')) {
                            // CRITICAL: Call ONCE per stamp rule - the helper method handles duplicate prevention
                            $this->redeemStampsForAllEligibleItems($order, $stampRuleIdToRedeem);

                            $order->refresh();
                            $order->load('items');
                        }
                    }
                }

                // Redeem only additional points beyond what was already redeemed on this order.
                $alreadyRedeemedPoints = (int) ($order->loyalty_points_redeemed ?? 0);
                $requestedPoints = (int) ($this->loyaltyPointsRedeemed ?? 0);
                $additionalPointsToRedeem = max(0, $requestedPoints - $alreadyRedeemedPoints);

                if (module_enabled('Loyalty') && $additionalPointsToRedeem > 0 && $this->loyaltyDiscountAmount > 0 && $this->isPointsEnabledForPOS()) {
                    $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                    $result = $loyaltyService->redeemPoints($order, $additionalPointsToRedeem);

                    if ($result['success']) {
                        $order->refresh();
                        $order->load(['taxes', 'charges.charge', 'items']);
                    }
                }
            }

            // Handle charges
            $order->load('charges');

            // Deduplicate charges before filtering to prevent duplicates
            $validCharges = collect($this->extraCharges ?? [])
                ->unique('id')
                ->filter(fn($charge) => in_array($this->orderTypeSlug, $charge->order_types));

            $currentChargeIds = $order->charges->pluck('charge_id')->unique();
            $validChargeIds = $validCharges->pluck('id')->unique();

            // Remove invalid charges and add new valid charges
            $order->charges()->whereNotIn('charge_id', $validChargeIds)->delete();

            $validChargeIds->diff($currentChargeIds)->each(
                fn($chargeId) =>
                OrderCharge::create(['order_id' => $order->id, 'charge_id' => $chargeId])
            );

            // Refresh order to load all OrderItems
            $order->refresh();
            $order->load('items');

            // Clear totals before recalculation
            $this->total = 0;
            $this->subTotal = 0;
            $this->totalTaxAmount = 0;
            $this->taxBase = 0;

            // Calculate subtotal from OrderItems (excluding free items)
            foreach ($order->items as $item) {
                // Skip free items from stamp redemption (they have amount = 0 anyway)
                $isFreeItem = $item->is_free_item_from_stamp ?? false;
                if ($isFreeItem) {
                    continue;
                }

                $this->subTotal += $item->amount;
                $this->total += $item->amount;
            }

            $this->discountedTotal = $this->total;

            // CRITICAL: Refresh order to get latest discount values after loyalty redemption
            $order->refresh();

            // Apply regular discount (from order settings)
            if ($order->discount_type === 'percent') {
                $this->discountAmount = round(($this->subTotal * $order->discount_value) / 100, 2);
            } elseif ($order->discount_type === 'fixed') {
                $this->discountAmount = min($order->discount_value, $this->subTotal);
            } else {
                // Use existing discount_amount from order (if set)
                $this->discountAmount = $order->discount_amount ?? 0;
            }

            // CRITICAL: Get loyalty discounts from order (set by redemption service)
            // These values are set by the LoyaltyService when redeeming stamps/points
            $loyaltyDiscount = floatval($order->loyalty_discount_amount ?? 0);
            $stampDiscount = floatval($order->stamp_discount_amount ?? 0);

            // Calculate net = subtotal - discount
            $this->total -= $this->discountAmount;
            $this->total -= $loyaltyDiscount;
            $this->total -= $stampDiscount;
            $this->discountedTotal = $this->total;

            // Calculate service charges on net (discountedTotal)
            $applicableCharges = $this->getApplicableExtraCharges();
            $chargeTotals = RestaurantCharge::calculateTotals($applicableCharges, $this->discountedTotal);
            $serviceTotal = $chargeTotals['total'];
            $this->total += $serviceTotal;

            // Calculate tax_base based on setting
            $includeChargesInTaxBase = $this->restaurant->include_charges_in_tax_base ?? true;
            $this->taxBase = RestaurantCharge::resolveTaxBase(
                $this->discountedTotal,
                $applicableCharges,
                $includeChargesInTaxBase
            );

            // Calculate taxes: for item-level tax use order items (cart orderItemTaxDetails may be empty when billing KOT without deleting items)
            if ($this->taxMode === 'item') {
                $this->totalTaxAmount = (float) $order->items->sum('tax_amount');
                $isInclusive = restaurant()->tax_inclusive ?? false;
                if (!$isInclusive && $this->totalTaxAmount > 0) {
                    $this->total += $this->totalTaxAmount;
                }
                if ($isInclusive) {
                    $this->subTotal -= $this->totalTaxAmount;
                }
            } else {
                $this->recalculateTaxTotals($this->taxBase);
            }

            // Add tip and delivery
            if ($this->tipAmount > 0) {
                $this->total += $this->tipAmount;
            }

            if ($this->deliveryFee > 0) {
                $this->total += $this->deliveryFee;
            }

            // Update order with calculated totals and persist loyalty/stamp discounts
            $order->refresh();
            $updateData = [
                'sub_total' => round($this->subTotal, 2),
                'total' => round($this->total, 2),
                'discount_amount' => $this->discountAmount,
                'total_tax_amount' => round($this->totalTaxAmount, 2),
                'tax_base' => $this->taxBase,
                'tax_mode' => $this->taxMode,
            ];
            if ($order->loyalty_discount_amount !== null) {
                $updateData['loyalty_discount_amount'] = round((float) $order->loyalty_discount_amount, 2);
            }
            if ($order->stamp_discount_amount !== null) {
                $updateData['stamp_discount_amount'] = round((float) $order->stamp_discount_amount, 2);
            }
            if ($order->loyalty_points_redeemed !== null) {
                $updateData['loyalty_points_redeemed'] = (int) $order->loyalty_points_redeemed;
            }
            Order::where('id', $order->id)->update($updateData);

            // Refresh order to get updated values
            $order->refresh();
        });
    }

    /**
     * Handle print option selection from modal
     */
    public function handlePrintOption($mode)
    {
        if (!$this->orderDetail) {
            return;
        }

        $this->printMode = $mode;

        switch ($mode) {
            case 'all':
                // Print summary + all individual splits
                $this->printAllReceipts();
                break;
            case 'summary':
                // Print summary only
                $this->printSummaryReceipt();
                break;
            case 'individual':
                // Print individual splits only
                $this->printIndividualReceipts();
                break;
            case 'single':
                // Show split selection - don't close modal yet
                return;
            default:
                break;
        }

        // Close modal after printing (except for single mode)
        if ($mode !== 'single') {
            $this->showPrintOptionsModal = false;
            $this->printMode = null;
        }
    }

    public function printAllReceipts()
    {
        $url = route('orders.print-split-receipts', [
            'orderId' => $this->orderDetail->id,
            'includeSummary' => true
        ]);

        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
    }

    public function printSummaryReceipt()
    {
        $url = route('orders.print', $this->orderDetail->id);
        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
    }

    public function printIndividualReceipts()
    {
        // Use the optimized controller for all individual receipts on one page
        $url = route('orders.print-split-receipts', ['orderId' => $this->orderDetail->id]);
        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
    }

    public function printSingleSplit()
    {
        if (!$this->selectedSplitId) {
            $this->alert('error', __('modules.order.selectSplitToPrint'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        // Use the optimized controller with specific split filter
        $url = route('orders.print-split-receipts', [
            'orderId' => $this->orderDetail->id,
            'splitId' => $this->selectedSplitId
        ]);
        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
        $this->selectedSplitId = null;
    }

    /**
     * Create order_items for KOT items (and link order_item_id) when KOT is generated.
     * This is used to keep order_items in sync for KOT orders without waiting for billing.
     *
     * @param \App\Models\Order $order
     * @param array<int> $kotIds
     * @return void
     */
    private function syncKotItemsToOrderItems(Order $order, array $kotIds = []): void
    {
        $order->load([
            'kot.items.menuItem',
            'kot.items.menuItemVariation',
            'kot.items.modifierOptions',
            'kot.items.orderItem',
        ]);

        $kots = $order->kot;
        if (!empty($kotIds)) {
            $kots = $kots->whereIn('id', $kotIds);
        }

        foreach ($kots as $kot) {
            foreach ($kot->items as $kotItem) {
                if ($kotItem->status === 'cancelled') {
                    continue;
                }

                if ($kotItem->order_item_id && $kotItem->orderItem) {
                    continue;
                }

                $itemAmount = $kotItem->amount ?? 0;
                if ($itemAmount == 0 && !($kotItem->is_free_item_from_stamp ?? false)) {
                    $itemPrice = $kotItem->price ?? ($kotItem->menuItem->price ?? 0);
                    $itemAmount = $itemPrice * $kotItem->quantity;
                    if ($kotItem->discount_amount && $kotItem->discount_amount > 0) {
                        $itemAmount -= $kotItem->discount_amount;
                    }
                }

                $taxAmount = null;
                $taxPercentage = null;
                $taxBreakup = null;
                if ($this->taxMode === 'item' && !($kotItem->is_free_item_from_stamp ?? false)) {
                    $menuItem = $kotItem->menuItem;
                    if ($menuItem) {
                        if (!$menuItem->relationLoaded('taxes')) {
                            $menuItem->load('taxes');
                        }

                        $qty = max(1, (int) $kotItem->quantity);
                        $perUnitAmount = round(((float) $itemAmount) / $qty, 2);
                        $isInclusive = (bool) (restaurant()->tax_inclusive ?? false);
                        $taxes = $menuItem->taxes ?? collect();

                        if ($taxes->isNotEmpty()) {
                            $taxResult = MenuItem::calculateItemTaxes($perUnitAmount, $taxes, $isInclusive);
                            $taxAmount = round((float) ($taxResult['tax_amount'] ?? 0) * $qty, 2);
                            $taxPercentage = $taxResult['tax_percentage'] ?? null;
                            $taxBreakup = isset($taxResult['tax_breakdown']) ? json_encode($taxResult['tax_breakdown']) : null;
                        } else {
                            $taxAmount = 0;
                            $taxPercentage = 0;
                            $taxBreakup = json_encode([]);
                        }
                    }
                }

                $orderItem = OrderItem::create([
                    'order_type' => $order->order_type,
                    'order_type_id' => $order->order_type_id,
                    'order_id' => $order->id,
                    'menu_item_id' => $kotItem->menu_item_id,
                    'menu_item_variation_id' => $kotItem->menu_item_variation_id,
                    'quantity' => $kotItem->quantity,
                    'price' => $kotItem->price ?? ($kotItem->menuItem->price ?? 0),
                    'amount' => max(0, round($itemAmount, 2)),
                    'note' => $kotItem->note,
                    'tax_amount' => $taxAmount,
                    'tax_percentage' => $taxPercentage,
                    'tax_breakup' => $taxBreakup,
                    'is_free_item_from_stamp' => $kotItem->is_free_item_from_stamp ?? false,
                    'stamp_rule_id' => $kotItem->stamp_rule_id,
                ]);

                $kotItem->load('modifierOptions');
                $orderItem->modifierOptions()->sync($kotItem->modifierOptions->pluck('id')->toArray());

                $kotItem->update(['order_item_id' => $orderItem->id]);
            }
        }
    }

    protected function unsetLoyaltyOrderRule($key, $menuItemId)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            $handler->unsetLoyaltyOrderRule($key, $menuItemId);
        }
    }

    protected function freeLoyaltyAmountRedeem($orderItem)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            $handler->freeLoyaltyAmountRedeem($orderItem);
        }
    }


    public function recalculateLoyaltyDiscount()
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            $handler->recalculateLoyaltyDiscount();
        }
    }

    public function getStampRuleId($menuItemId)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->getStampRuleId($menuItemId);
        }

        return null;
    }

    protected function resolveStampDataForItem(array $data)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->resolveStampDataForItem($data);
        }

        return $data;
    }

    protected function resolveSelectedStampDiscount(array $data)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->resolveSelectedStampDiscount($data);
        }

        return $data;
    }

    protected function resolveStampViaHandler(array $data)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->resolveStampViaHandler($data);
        }

        return $data;
    }

    protected function applyStampRuleDiscount(array $data)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->applyStampRuleDiscount($data);
        }

        return $data;
    }

    protected function preserveStampFromDraftOrderItem(array $data, $order, array $item)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            return $handler->preserveStampFromDraftOrderItem($data, $order, $item);
        }

        return $data;
    }

    protected function handleStampRedemptionForOrder($order)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            $handler->handleStampRedemptionForOrder($order);
        }
    }

    protected function handleLoyaltyPointsRedemptionForOrder($order)
    {
        $handler = $this->loyaltyHandler();

        if ($handler) {
            $handler->handleLoyaltyPointsRedemptionForOrder($order);
        }
    }

    private function isCartIngredientStockBlocked(): bool
    {
        $branchId = branch()?->id;

        if (! $branchId) {
            return false;
        }

        $result = IngredientStockGuard::validateCartFromOrderState(
            $branchId,
            $this->orderItemList ?? [],
            $this->orderItemQty ?? [],
            $this->orderItemVariation ?? [],
            $this->itemModifiersSelected ?? [],
        );

        if ($result['ok']) {
            return false;
        }

        $this->alert('error', $result['error'] ?? IngredientStockGuard::insufficientStockMessage(), [
            'toast' => true,
            'position' => 'top-end',
        ]);

        return true;
    }

    private function isIngredientStockBlockedForLine(
        int $menuItemId,
        ?int $menuItemVariationId,
        array $modifierOptionIds,
        float|int $quantity,
    ): bool {
        $branchId = branch()?->id;

        if (! $branchId) {
            return false;
        }

        $result = IngredientStockGuard::validateOrderLine(
            $branchId,
            $menuItemId,
            $menuItemVariationId,
            $modifierOptionIds,
            (float) $quantity,
        );

        if ($result['ok']) {
            return false;
        }

        $this->alert('error', $result['error'] ?? IngredientStockGuard::insufficientStockMessage(), [
            'toast' => true,
            'position' => 'top-end',
        ]);

        return true;
    }
}
