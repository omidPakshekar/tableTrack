<?php

namespace App\Livewire\Order;

use App\Models\BranchOperationalShift;
use App\Models\DeliveryPlatform;
use App\Models\Kot;
use App\Models\KotCancelReason;
use App\Models\KotItem;
use App\Models\Order;
use App\Models\OrderNotificationSetting;
use App\Models\PusherSetting;
use App\Models\ReceiptSetting;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Orders extends Component
{

    use LivewireAlert;
    use WithPagination;

    protected $listeners = ['refreshOrders' => '$refresh'];

    public $orderID;
    public $filterOrders;
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $receiptSettings;
    public $waiters;
    public $filterWaiter;
    public $tables;
    public $filterTable = '';
    public $pollingEnabled = true;
    public $pollingInterval = 10;
    public $filterOrderType = '';
    public $deliveryApps;
    public $filterDeliveryApp = '';
    public $filterShift = '';
    public $shifts;
    public $cancelReasons;
    public $selectedCancelReason;
    public $cancelComment;
    public $perPage = 20;
    public $hasMore = false;
    public $isLoadingMore = false;
    public $showMergeModal = false;
    public $unpaidOrders = [];
    public $selectedOrders = [];
    private const DEFAULT_TIMEZONE = 'UTC';
    public $deliveryExecutiveId = null;
    public $deliveryExecutiveName = null;
    public $backUrl = null;
    public $isDeliveryExecutiveContext = false;
    public $trackingEnabled = false;
    public $mapApiKey = null;
    public $mapProvider = 'google';

    public function mount($deliveryExecutiveId = null, $deliveryExecutiveName = null, $backUrl = null)
    {
        $tz = $this->getRestaurantTimezone();
        $this->deliveryExecutiveId = !empty($deliveryExecutiveId) ? (int)$deliveryExecutiveId : null;
        $this->deliveryExecutiveName = $deliveryExecutiveName;
        $this->backUrl = $backUrl;
        $this->isDeliveryExecutiveContext = !empty($this->deliveryExecutiveId);
        $this->trackingEnabled = $this->isDeliveryExecutiveContext && module_enabled('RestApi');
        $this->mapApiKey = global_setting()->google_map_api_key ?? restaurant()?->map_api_key ?? null;
        $this->mapProvider = global_setting()->map_provider ?? 'google';

        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('orders_date_range_type', 'today');
        $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
        $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
        $this->waiters = \App\Services\Pos\PosWaitersCache::remember((int) restaurant()->id, (int) branch()->id);
        $this->tables = Table::query()
            ->with('area:id,area_name')
            ->orderBy('table_code')
            ->get(['id', 'table_code', 'area_id']);
        $this->deliveryApps = DeliveryPlatform::all();

        // Load operational shifts for the current branch (for filter dropdown)
        // Will be filtered by current day of week in render() method
        $this->shifts = BranchOperationalShift::where('branch_id', branch()->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get();

        // Load polling settings from cookies
        $this->pollingEnabled = filter_var(request()->cookie('orders_polling_enabled', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->pollingInterval = (int)request()->cookie('orders_polling_interval', 10);


        if (!is_null($this->orderID)) {
            $this->dispatch('showOrderDetail', id: $this->orderID);
        }

        $this->setDateRange();
        $this->cancelReasons = KotCancelReason::where('cancel_order', true)->get();

        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $this->filterWaiter = user()->id;
        }
    }

    private function resetPerPage(): void
    {
        $this->perPage = 20;
    }

    #[On('newOrderCreated')]
    public function handleNewOrder($data = null)
    {
        $this->showNewOrderNotification();
    }

    #[On('viewOrder')]
    public function viewOrder($data)
    {

        if (is_array($data) && isset($data['orderID'])) {
            $orderId = $data['orderID'];
            $url = route('pos.kot', [$orderId]) . '?show-order-detail=true';
            $this->js("window.location.href = '{$url}'");
            return;
        }

        Log::warning('viewOrder: Invalid data format', ['data' => $data]);
    }

    /**
     * Show notification for new orders
     */
    private function showNewOrderNotification()
    {
        $recentOrder = Order::with('table', 'customer', 'orderType')
            ->where('status', '<>', 'draft')
            ->whereNotNull('order_number')
            ->orderBy(DB::raw('CAST(order_number AS UNSIGNED)'), 'desc')
            ->first();

        if ($recentOrder) {
            // Check order notification settings for current user's role
            $user = user();
            $restaurant = restaurant();

            if ($user && $restaurant && method_exists($user, 'roles')) {
                $orderNotificationSetting = OrderNotificationSetting::where('restaurant_id', $restaurant->id)
                    ->whereIn('role_id', $user->roles->pluck('id'))
                    ->where('hide_new_order_notification', true)
                    ->exists();

                if ($orderNotificationSetting) {
                    // Skip showing notification if setting is enabled for this role
                    session()->put('new_order_notification_pending', false);
                    return;
                }
            }

            // Build order description
            $orderDescription = __('modules.order.newOrderReceived') . ': ' . $recentOrder->show_formatted_order_number;


            // Add table info if it exists
            if ($recentOrder->table && $recentOrder->table->table_code) {
                $orderDescription .= ' - ' . __('modules.table.table') . ': ' . $recentOrder->table->table_code;
            }
            // Add customer info for delivery/pickup orders
            else if ($recentOrder->customer && $recentOrder->customer->name) {
                $orderDescription .= ' - ' . $recentOrder->customer->name;
            }

            // Prefer stored/custom order type name (same as order list); fall back to lang key for legacy slugs
            $orderTypeLabel = $recentOrder->custom_order_type_name
                ?? $recentOrder->orderType?->order_type_name
                ?? (Lang::has('modules.order.' . $recentOrder->order_type)
                    ? __('modules.order.' . $recentOrder->order_type)
                    : null);

            if ($orderTypeLabel) {
                $orderDescription .= ' (' . $orderTypeLabel . ')';
            }

            $this->confirm($orderDescription, [
                'position' => 'center',
                'confirmButtonText' => __('modules.order.viewOrder'),
                'confirmButtonColor' => '#16a34a',
                'onConfirmed' => 'viewOrder',
                'showCancelButton' => true,
                'cancelButtonText' => __('app.close'),
                'data' => [
                    'orderID' => $recentOrder->id
                ]
            ]);
        }

        // Mark notification as shown in session
        session()->put('new_order_notification_pending', false);
    }

    public function refreshNewOrders()
    {
        $this->dispatch('$refresh');
    }

    private function getOrdersCount()
    {
        $tz = $this->getRestaurantTimezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $start = parseRestaurantDate($this->startDate, $dateFormat, $tz)
            ->startOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $end = parseRestaurantDate($this->endDate, $dateFormat, $tz)
            ->endOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        return Order::where('orders.date_time', '>=', $start)
            ->where('orders.date_time', '<=', $end)
            ->count();
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('orders_date_range_type', $value, 60 * 24 * 30)); // 30 days
        $this->resetPerPage();
    }

    public function updatedPollingEnabled($value)
    {
        cookie()->queue(cookie('orders_polling_enabled', $value ? 'true' : 'false', 60 * 24 * 30)); // 30 days
    }

    public function updatedPollingInterval($value)
    {
        cookie()->queue(cookie('orders_polling_interval', (int)$value, 60 * 24 * 30)); // 30 days
    }

    public function updatedFilterOrders(): void
    {
        $this->resetPerPage();
    }

    public function updatedFilterOrderType(): void
    {
        $this->resetPerPage();
    }

    public function updatedFilterDeliveryApp(): void
    {
        $this->resetPerPage();
    }

    public function updatedFilterWaiter(): void
    {
        $this->resetPerPage();
    }

    public function updatedFilterTable(): void
    {
        $this->resetPerPage();
    }

    public function updatedFilterShift(): void
    {
        $this->resetPerPage();
    }

    public function setDateRange()
    {
        $tz = $this->getRestaurantTimezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        switch ($this->dateRangeType) {
            case 'today':
                $this->startDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentWeek':
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;

            case 'lastWeek':
                $this->startDate = Carbon::now($tz)->subWeek()->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subWeek()->endOfWeek()->displayDate($dateFormat);
                break;

            case 'last7Days':
                $this->startDate = Carbon::now($tz)->subDays(7)->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentMonth':
                $this->startDate = Carbon::now($tz)->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfMonth()->displayDate($dateFormat);
                break;

            case 'lastMonth':
                $this->startDate = Carbon::now($tz)->subMonth()->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subMonth()->endOfMonth()->displayDate($dateFormat);
                break;

            case 'currentYear':
                $this->startDate = Carbon::now($tz)->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfYear()->displayDate($dateFormat);
                break;

            case 'lastYear':
                $this->startDate = Carbon::now($tz)->subYear()->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subYear()->endOfYear()->displayDate($dateFormat);
                break;

            default:
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;
        }

        // Clear shift filter if not viewing today (shift filter only works for today)
        if ($this->dateRangeType !== 'today') {
            $this->filterShift = null;
        }
    }


    public function showTableOrderDetail($id)
    {
        return $this->redirect(route('pos.order', [$id]));
    }

    public function confirmCancelOrder()
    {
        // Validate that a cancel reason is provided
        if (!$this->selectedCancelReason && !$this->cancelComment) {
            $this->dispatchBrowserEvent('orderCancelled', ['message' => __('modules.settings.cancelReasonRequired'), 'type' => 'error']);
            return;
        }

        $order = Order::find($this->orderID);
        $order->status = 'cancelled';
        $order->cancel_reason_id = $this->selectedCancelReason;
        $order->cancel_comment = $this->cancelComment;
        $order->cancelled_by = auth()->id();
        $order->cancel_time = now();
        $order->save();

        $this->dispatchBrowserEvent('orderCancelled', ['message' => __('messages.orderCanceled')]);
    }

    public function loadMore(): void
    {
        if ($this->isLoadingMore || !$this->hasMore) {
            return;
        }

        $this->isLoadingMore = true;
        $this->perPage += 20;
    }

    public function openMergeModal()
    {
        $this->fetchUnpaidOrders();
        $this->showMergeModal = true;
    }

    public function closeMergeModal()
    {
        $this->showMergeModal = false;
        $this->unpaidOrders = [];
        $this->selectedOrders = [];
    }

    public function mergeOrders()
    {
        if (empty($this->selectedOrders) || count($this->selectedOrders) < 2) {
            $this->alert('error', __('modules.order.selectAtLeastTwoOrders'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        try {
            DB::beginTransaction();

            // Get the first order as the base order
            $baseOrderId = $this->selectedOrders[0];
            $baseOrder = Order::with(['items', 'taxes', 'charges', 'kot.items.modifierOptions'])->find($baseOrderId);

            if (!$baseOrder) {
                throw new \Exception(__('messages.orderNotFound'));
            }

            // Get or create a KOT for the base order if it doesn't have one
            $baseKot = $baseOrder->kot->first();
            if (!$baseKot) {
                $baseKot = Kot::create([
                    'kot_number' => Kot::generateKotNumber($baseOrder->branch),
                    'order_id' => $baseOrder->id,
                    'order_type_id' => $baseOrder->order_type_id,
                    'token_number' => Kot::generateTokenNumber(branch()->id, $baseOrder->order_type_id),
                    'status' => 'in_kitchen',
                ]);
                // Refresh base order to include the new KOT
                $baseOrder->refresh();
                $baseOrder->load('kot.items.menuItem.taxes', 'kot.items.menuItemVariation', 'kot.items.modifierOptions');
            } else {
                // Ensure base KOT has all necessary relationships loaded
                $baseKot->load('items.menuItem.taxes', 'items.menuItemVariation', 'items.modifierOptions');
            }

            // Get all other orders to merge
            $ordersToMerge = Order::with([
                'items.modifierOptions',
                'taxes',
                'charges',
                'kot.items.menuItem.taxes',
                'kot.items.menuItemVariation',
                'kot.items.modifierOptions'
            ])
                ->whereIn('id', array_slice($this->selectedOrders, 1))
                ->get();

            // Pre-calculate merged financial totals from ALL selected orders
            // so final total = sum of each order's total (as you requested)
            $allOrders = collect([$baseOrder])->merge($ordersToMerge);
            $mergedSubTotal = (float)$allOrders->sum('sub_total');
            $mergedTotal = (float)$allOrders->sum('total');
            $mergedDiscount = (float)$allOrders->sum('discount_amount');
            $mergedTax = (float)$allOrders->sum('total_tax_amount');
            $mergedTip = (float)$allOrders->sum('tip_amount');
            $mergedDeliveryFee = (float)$allOrders->sum('delivery_fee');

            // Loyalty stamp fields are optional — never break merge when module/columns are absent.
            $loyaltyStampsActive = $this->loyaltyStampFeaturesAvailable();
            $mergedStampDiscount = $loyaltyStampsActive
                ? (float) $allOrders->sum('stamp_discount_amount')
                : 0;

            // Merge items from other orders into base order
            foreach ($ordersToMerge as $order) {
                // Merge order items
                foreach ($order->items as $item) {
                    $existingItemQuery = $baseOrder->items()
                        ->where('menu_item_id', $item->menu_item_id)
                        ->where('menu_item_variation_id', $item->menu_item_variation_id);

                    // Keep free stamp items separate from paid lines when Loyalty stamps are active.
                    if ($loyaltyStampsActive) {
                        $existingItemQuery->where(
                            'is_free_item_from_stamp',
                            (bool) ($item->is_free_item_from_stamp ?? false)
                        );
                    }

                    $existingItem = $existingItemQuery->first();

                    if ($existingItem) {
                        // Update quantity and amount
                        $existingItem->quantity += $item->quantity;
                        $existingItem->amount += $item->amount;
                        if ($loyaltyStampsActive && !$existingItem->stamp_rule_id && $item->stamp_rule_id) {
                            $existingItem->stamp_rule_id = $item->stamp_rule_id;
                        }
                        $existingItem->save();

                        // Merge modifier options
                        $existingModifiers = $existingItem->modifierOptions()->pluck('modifier_options.id')->toArray();
                        $newModifiers = $item->modifierOptions()->pluck('modifier_options.id')->toArray();
                        $mergedModifiers = array_unique(array_merge($existingModifiers, $newModifiers));
                        $existingItem->modifierOptions()->sync($mergedModifiers);
                    } else {
                        // Create new item in base order
                        $newItemData = [
                            'menu_item_id' => $item->menu_item_id,
                            'menu_item_variation_id' => $item->menu_item_variation_id,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'amount' => $item->amount,
                            'tax_amount' => $item->tax_amount ?? 0,
                            'note' => $item->note,
                        ];
                        if ($loyaltyStampsActive) {
                            $newItemData['is_free_item_from_stamp'] = (bool) ($item->is_free_item_from_stamp ?? false);
                            $newItemData['stamp_rule_id'] = $item->stamp_rule_id ?? null;
                        }
                        $newItem = $baseOrder->items()->create($newItemData);

                        // Copy modifier options
                        if ($item->modifierOptions->isNotEmpty()) {
                            $modifierData = [];
                            foreach ($item->modifierOptions as $modifier) {
                                $modifierData[$modifier->id] = [
                                    'modifier_option_name' => $modifier->pivot->modifier_option_name ?? $modifier->name,
                                    'modifier_option_price' => $modifier->pivot->modifier_option_price ?? $modifier->price,
                                ];
                            }
                            $newItem->modifierOptions()->sync($modifierData);
                        }
                    }
                }

                // Merge KOT items from all KOTs of the order being merged
                foreach ($order->kot as $kot) {
                    foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                        $existingKotItemQuery = $baseKot->items()
                            ->where('menu_item_id', $kotItem->menu_item_id)
                            ->where('menu_item_variation_id', $kotItem->menu_item_variation_id)
                            ->where('status', '!=', 'cancelled');

                        if ($loyaltyStampsActive) {
                            $existingKotItemQuery->where(
                                'is_free_item_from_stamp',
                                (bool) ($kotItem->is_free_item_from_stamp ?? false)
                            );
                        }

                        $existingKotItem = $existingKotItemQuery->first();

                        if ($existingKotItem) {
                            // Update quantity
                            $existingKotItem->quantity += $kotItem->quantity;
                            if (isset($kotItem->amount)) {
                                $existingKotItem->amount = (float) ($existingKotItem->amount ?? 0) + (float) $kotItem->amount;
                            }
                            if ($loyaltyStampsActive && !$existingKotItem->stamp_rule_id && $kotItem->stamp_rule_id) {
                                $existingKotItem->stamp_rule_id = $kotItem->stamp_rule_id;
                            }
                            $existingKotItem->save();

                            // Merge modifier options
                            $existingModifiers = $existingKotItem->modifierOptions()->pluck('modifier_options.id')->toArray();
                            $newModifiers = $kotItem->modifierOptions()->pluck('modifier_options.id')->toArray();
                            $mergedModifiers = array_unique(array_merge($existingModifiers, $newModifiers));
                            $existingKotItem->modifierOptions()->sync($mergedModifiers);
                        } else {
                            // Create new KOT item in base KOT
                            $newKotItemData = [
                                'menu_item_id' => $kotItem->menu_item_id,
                                'menu_item_variation_id' => $kotItem->menu_item_variation_id,
                                'quantity' => $kotItem->quantity,
                                'note' => $kotItem->note,
                                'status' => $kotItem->status ?? 'pending',
                                'order_type_id' => $kotItem->order_type_id,
                                'order_type' => $kotItem->order_type,
                            ];
                            if (isset($kotItem->amount)) {
                                $newKotItemData['amount'] = $kotItem->amount;
                            }
                            if (isset($kotItem->price)) {
                                $newKotItemData['price'] = $kotItem->price;
                            }
                            if ($loyaltyStampsActive) {
                                $newKotItemData['is_free_item_from_stamp'] = (bool) ($kotItem->is_free_item_from_stamp ?? false);
                                $newKotItemData['stamp_rule_id'] = $kotItem->stamp_rule_id ?? null;
                                if (isset($kotItem->discount_amount)) {
                                    $newKotItemData['discount_amount'] = $kotItem->discount_amount;
                                }
                            }
                            $newKotItem = $baseKot->items()->create($newKotItemData);

                            // Copy modifier options
                            if ($kotItem->modifierOptions->isNotEmpty()) {
                                $modifierIds = $kotItem->modifierOptions->pluck('id')->toArray();
                                $newKotItem->modifierOptions()->sync($modifierIds);
                            }
                        }
                    }
                }

                // Merge taxes (avoid duplicates)
                foreach ($order->taxes as $tax) {
                    $exists = $baseOrder->taxes()->where('tax_id', $tax->tax_id)->exists();
                    if (!$exists) {
                        $baseOrder->taxes()->create([
                            'tax_id' => $tax->tax_id,
                        ]);
                    }
                }

                // Merge charges (avoid duplicates)
                foreach ($order->charges as $charge) {
                    $exists = $baseOrder->charges()->where('charge_id', $charge->charge_id)->exists();
                    if (!$exists) {
                        $baseOrder->charges()->create([
                            'charge_id' => $charge->charge_id,
                        ]);
                    }
                }
            }

            // Update base order financial fields using summed values from all merged orders
            // This makes final total = sum of totals of each order.
            // Store discount as fixed amount so the first order's percent/value is not
            // incorrectly shown/reapplied against the combined subtotal later.
            $orderUpdateData = [
                'sub_total' => $mergedSubTotal,
                'total' => $mergedTotal,
                'discount_amount' => $mergedDiscount,
                'discount_type' => $mergedDiscount > 0 ? 'fixed' : null,
                'discount_value' => $mergedDiscount > 0 ? $mergedDiscount : null,
                'total_tax_amount' => $mergedTax,
                'tip_amount' => $mergedTip,
                'delivery_fee' => $mergedDeliveryFee,
            ];
            if ($loyaltyStampsActive) {
                $orderUpdateData['stamp_discount_amount'] = $mergedStampDiscount;
            }
            $baseOrder->update($orderUpdateData);

            // Delete merged orders
            $orderIdsToDelete = $ordersToMerge->pluck('id')->toArray();

            // Delete KOTs and KOT items for orders to be deleted
            foreach ($ordersToMerge as $order) {
                foreach ($order->kot as $kot) {
                    KotItem::where('kot_id', $kot->id)->delete();
                    $kot->delete();
                }
            }

            // Delete order items, taxes, charges for orders to be deleted
            \App\Models\OrderItem::whereIn('order_id', $orderIdsToDelete)->delete();
            \App\Models\OrderTax::whereIn('order_id', $orderIdsToDelete)->delete();
            \App\Models\OrderCharge::whereIn('order_id', $orderIdsToDelete)->delete();

            // Delete payments if any
            \App\Models\Payment::whereIn('order_id', $orderIdsToDelete)->delete();

            // Delete the orders
            Order::whereIn('id', $orderIdsToDelete)->delete();

            DB::commit();

            $this->alert('success', __('modules.order.ordersMergedSuccessfully'), [
                'toast' => true,
                'position' => 'top-end',
                'timer' => 3000,
            ]);

            $this->closeMergeModal();
            $this->dispatch('refreshOrders');
            $this->resetPerPage();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error merging orders: ' . $e->getMessage());
            $this->alert('error', __('messages.somethingWentWrong'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    private function recalculateOrderTotals($order)
    {
        $order->refresh();
        $order->load([
            'items',
            'taxes.tax',
            'charges.charge',
            'kot.items.menuItem.taxes',
            'kot.items.menuItemVariation',
            'kot.items.modifierOptions'
        ]);

        $subTotal = 0;
        $totalTaxAmount = 0;

        // Calculate subtotal from OrderItems (if they exist)
        foreach ($order->items as $item) {
            $subTotal += $item->amount;
            if ($item->tax_amount) {
                $totalTaxAmount += $item->tax_amount;
            }
        }

        // If no OrderItems or subtotal is 0, calculate from KOT items
        if ($subTotal == 0 && $order->kot->isNotEmpty()) {
            foreach ($order->kot as $kot) {
                foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                    // Get menu item price
                    $menuItem = $kotItem->menuItem;
                    $menuItemVariation = $kotItem->menuItemVariation;

                    // Set price context if order type is available
                    if ($order->order_type_id && $menuItem) {
                        $menuItem->setPriceContext($order->order_type_id, $order->delivery_app_id);
                        if ($menuItemVariation) {
                            $menuItemVariation->setPriceContext($order->order_type_id, $order->delivery_app_id);
                        }
                        // Set context on modifiers
                        foreach ($kotItem->modifierOptions as $modifier) {
                            $modifier->setPriceContext($order->order_type_id, $order->delivery_app_id);
                        }
                    }

                    $menuItemPrice = $menuItem->price ?? 0;
                    $variationPrice = $menuItemVariation ? $menuItemVariation->price : 0;
                    $basePrice = $variationPrice ?: $menuItemPrice;

                    // Add modifier prices
                    $modifierPrice = $kotItem->modifierOptions->sum('price');
                    $itemTotal = ($basePrice + $modifierPrice) * $kotItem->quantity;

                    $subTotal += $itemTotal;
                }
            }

            // Calculate item-level taxes from KOT items if tax mode is 'item'
            $taxMode = restaurant()->tax_mode ?? 'order';
            if ($taxMode === 'item') {
                foreach ($order->kot as $kot) {
                    foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                        $menuItem = $kotItem->menuItem;
                        if ($menuItem) {
                            // Load taxes if not already loaded
                            if (!$menuItem->relationLoaded('taxes')) {
                                $menuItem->load('taxes');
                            }

                            if ($menuItem->taxes && $menuItem->taxes->isNotEmpty()) {
                                $menuItemPrice = $menuItem->price ?? 0;
                                $menuItemVariation = $kotItem->menuItemVariation;
                                $variationPrice = $menuItemVariation ? $menuItemVariation->price : 0;
                                $basePrice = $variationPrice ?: $menuItemPrice;
                                $modifierPrice = $kotItem->modifierOptions->sum('price');
                                $itemPriceWithModifiers = $basePrice + $modifierPrice;

                                $taxes = $menuItem->taxes;
                                $isInclusive = restaurant()->tax_inclusive ?? false;
                                $taxResult = \App\Models\MenuItem::calculateItemTaxes($itemPriceWithModifiers, $taxes, $isInclusive);

                                $itemTaxAmount = $taxResult['tax_amount'] * $kotItem->quantity;
                                $totalTaxAmount += $itemTaxAmount;
                            }
                        }
                    }
                }
            }
        }

        // Calculate order-level taxes if applicable
        $taxMode = restaurant()->tax_mode ?? 'order';
        if ($taxMode === 'order') {
            foreach ($order->taxes as $orderTax) {
                if ($orderTax->tax) {
                    $taxAmount = ($orderTax->tax->tax_percent / 100) * $subTotal;
                    $totalTaxAmount += $taxAmount;
                }
            }
        }

        // Calculate discount
        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = round(($subTotal * $order->discount_value) / 100, 2);
        } elseif ($order->discount_type === 'fixed') {
            $discountAmount = min($order->discount_value, $subTotal);
        }

        $discountedTotal = $subTotal - $discountAmount;

        // Calculate charges
        $chargesAmount = 0;
        foreach ($order->charges as $orderCharge) {
            $charge = $orderCharge->charge;
            if ($charge && method_exists($charge, 'getAmount')) {
                $chargeAmount = $charge->getAmount($discountedTotal);
                $chargesAmount += $chargeAmount;
            }
        }

        // Calculate total
        $total = $subTotal + $totalTaxAmount - $discountAmount + $chargesAmount;

        // Add tip if any
        if ($order->tip_amount > 0) {
            $total += $order->tip_amount;
        }

        // Add delivery fee if any
        if ($order->delivery_fee > 0) {
            $total += $order->delivery_fee;
        }

        // Update order
        $order->update([
            'sub_total' => $subTotal,
            'total' => $total,
            'discount_amount' => $discountAmount,
            'total_tax_amount' => $totalTaxAmount,
        ]);
    }

    private function fetchUnpaidOrders()
    {
        $tz = $this->getRestaurantTimezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $start = parseRestaurantDate($this->startDate, $dateFormat, $tz)
            ->startOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $end = parseRestaurantDate($this->endDate, $dateFormat, $tz)
            ->endOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $this->unpaidOrders = Order::with('table', 'waiter', 'customer', 'orderType')
            ->where('orders.date_time', '>=', $start)
            ->where('orders.date_time', '<=', $end)
            ->where('status', 'kot')
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'canceled')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function render()
    {
        $data = $this->fetchOrders();
        $orders = $data['orders'];
        $ordersTotal = $data['ordersTotal'];
        $statusCounts = $data['statusCounts'];

        // Check for new orders and show popup
        $playSound = false;
        $pendingNotification = session()->get('new_order_notification_pending', false);

        if ($pendingNotification) {
            $playSound = true;
            $this->showNewOrderNotification();
        }

        $kotCount = $statusCounts['kot'] ?? 0;
        $billedCount = $statusCounts['billed'] ?? 0;
        $paymentDue = $statusCounts['payment_due'] ?? 0;
        $paidOrders = $statusCounts['paid'] ?? 0;
        $canceledOrders = $statusCounts['canceled'] ?? 0;
        $outDeliveryOrders = $statusCounts['out_for_delivery'] ?? 0;
        $deliveredOrders = $statusCounts['delivered'] ?? 0;
        $draftOrders = $statusCounts['draft'] ?? 0;

        $receiptSettings = branch()->receiptSetting;

        // Check if "today" is selected
        $tz = $this->getRestaurantTimezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $startDateObj = parseRestaurantDate($this->startDate, $dateFormat, $tz);
        $endDateObj = parseRestaurantDate($this->endDate, $dateFormat, $tz);
        $todayDateObj = Carbon::now($tz);

        $isToday = ($this->dateRangeType === 'today') ||
                (($this->startDate === $this->endDate) &&
                    ($startDateObj->toDateString() === $todayDateObj->toDateString()));

        // Get business day boundaries for informational message - only if today is selected
        $businessDayInfo = null;
        $filteredShifts = collect();

        if ($isToday && branch()) {
            // Get business day info
            $boundaries = getBusinessDayBoundaries(branch(), now());
            $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
            $timeFormat = branch()->restaurant->time_format ?? 'h:i A';

            // Use business_day_end for display (shows full business day end, not "now")
            $displayEnd = isset($boundaries['business_day_end'])
                ? $boundaries['business_day_end']
                : $boundaries['end'];

            $businessDayStart = $boundaries['start']->setTimezone($restaurantTimezone);
            $calendarDate = $boundaries['calendar_date'];

            // If business day ends on next calendar day, show info
            if ($displayEnd->toDateString() !== $calendarDate) {
                $businessDayInfo = [
                    'start' => $businessDayStart->format($timeFormat),
                    'end' => $displayEnd->format($timeFormat),
                    'end_date' => $displayEnd->toDateString(),
                    'calendar_date' => $calendarDate,
                    'extends_to_next_day' => true,
                ];
            } else {
                // Business day is within same calendar day
                $businessDayInfo = [
                    'start' => $businessDayStart->format($timeFormat),
                    'end' => $displayEnd->format($timeFormat),
                    'end_date' => $displayEnd->toDateString(),
                    'calendar_date' => $calendarDate,
                    'extends_to_next_day' => false,
                ];
            }

            // Filter shifts to only show those that apply to today's day of week
            $currentDayOfWeek = Carbon::now($tz)->format('l'); // e.g., "Friday"
            $filteredShifts = $this->shifts->filter(function($shift) use ($currentDayOfWeek) {
                // Ensure day_of_week is an array (handle both array and JSON string)
                $shiftDays = $shift->day_of_week ?? [];
                if (is_string($shiftDays)) {
                    $shiftDays = json_decode($shiftDays, true) ?? [];
                }
                if (!is_array($shiftDays)) {
                    $shiftDays = [];
                }

                // Include shift if it has 'All' days or includes the current day
                // Only return true if the shift actually applies to today
                $applies = in_array('All', $shiftDays) || in_array($currentDayOfWeek, $shiftDays);
                return $applies;
            })->map(function ($shift) use ($restaurantTimezone, $timeFormat) {
                $startLocal = $this->convertUtcTimeToRestaurantTime($shift->start_time, $restaurantTimezone);
                $endLocal = $this->convertUtcTimeToRestaurantTime($shift->end_time, $restaurantTimezone);

                $shift->start_time_local = $startLocal;
                $shift->end_time_local = $endLocal;
                $shift->start_time_display = Carbon::createFromFormat('H:i', $startLocal)->format($timeFormat);
                $shift->end_time_display = Carbon::createFromFormat('H:i', $endLocal)->format($timeFormat);

                return $shift;
            })->values(); // Re-index the collection to ensure clean array keys

            // If a shift is currently selected but it's not in the filtered list, clear it
            if (!empty($this->filterShift)) {
                $shiftIds = $filteredShifts->pluck('id')->toArray();
                if (!in_array($this->filterShift, $shiftIds)) {
                    $this->filterShift = null;
                }
            }
        } else {
            // Not viewing today - don't show shifts or business day info
            $filteredShifts = collect();
        }

        return view('livewire.order.orders', [
            'orders' => $orders,
            'ordersTotal' => $ordersTotal,
            'businessDayInfo' => $businessDayInfo,
            'filteredShifts' => $filteredShifts, // Pass filtered shifts (only for today) - use different name to avoid conflict
            'isToday' => $isToday, // Pass flag to view
            'hasMore' => $this->hasMore,
            'isDeliveryExecutiveContext' => $this->isDeliveryExecutiveContext,
            'deliveryExecutiveId' => $this->deliveryExecutiveId,
            'deliveryExecutiveName' => $this->deliveryExecutiveName,
            'trackingEnabled' => $this->trackingEnabled,
            'backUrl' => $this->backUrl,
            'mapApiKey' => $this->mapApiKey,
            'mapProvider' => $this->mapProvider,
            'kotCount' => $kotCount,
            'billedCount' => $billedCount,
            'paymentDueCount' => $paymentDue,
            'paidOrdersCount' => $paidOrders,
            'canceledOrdersCount' => $canceledOrders,
            'outDeliveryOrdersCount' => $outDeliveryOrders,
            'deliveredOrdersCount' => $deliveredOrders,
            'draftOrdersCount' => $draftOrders,
            'receiptSettings' => $receiptSettings, // Pass the fetched receipt settings to the view
            'orderID' => $this->orderID,
            'playSound' => $playSound ?? false,
            'unpaidOrders' => $this->unpaidOrders,
        ]);
    }

    private function fetchOrders(): array
    {
        $tz = $this->getRestaurantTimezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Check if we're viewing "today" (startDate and endDate are the same and equal to today)
        $startDateObj = parseRestaurantDate($this->startDate, $dateFormat, $tz);
        $endDateObj = parseRestaurantDate($this->endDate, $dateFormat, $tz);
        $todayDateObj = Carbon::now($tz);

        $isToday = ($this->startDate === $this->endDate) &&
                ($startDateObj->toDateString() === $todayDateObj->toDateString());

        if ($isToday && branch()) {
            // Use business day boundaries for "today"
            $boundaries = getBusinessDayBoundaries(branch(), now());

            $start = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
            $end = $boundaries['end']->setTimezone('UTC')->toDateTimeString();
        } else {
            // Use calendar day boundaries for other date ranges
            $start = $startDateObj->startOfDay()
                ->setTimezone('UTC')
                ->toDateTimeString();

            $end = $endDateObj->endOfDay()
                ->setTimezone('UTC')
                ->toDateTimeString();
        }

        $ordersQuery = Order::withCount('items')
            ->with('table', 'waiter', 'customer', 'orderType', 'deliveryApp', 'charges.charge')
            ->orderBy('id', 'desc')
            ->where('orders.date_time', '>=', $start)
            ->where('orders.date_time', '<=', $end);

        if ($this->isDeliveryExecutiveContext) {
            $ordersQuery->where('delivery_executive_id', $this->deliveryExecutiveId);
        }

        if (!empty($this->filterOrderType)) {
            $ordersQuery->where('order_type', $this->filterOrderType);
        }

        if (!empty($this->filterDeliveryApp)) {
            if ($this->filterDeliveryApp === 'direct') {
                $ordersQuery->whereNull('delivery_app_id');
            } else {
                $ordersQuery->where('delivery_app_id', $this->filterDeliveryApp);
            }
        }

        if (!empty($this->filterOrders)) {
            $ordersQuery->where('status', $this->filterOrders);
        }

        if ($this->filterWaiter) {
            $ordersQuery->where('waiter_id', $this->filterWaiter);
        }

        if (!empty($this->filterTable)) {
            $ordersQuery->where('table_id', $this->filterTable);
        }

        // Filter by shift if selected (apply at query level for proper timezone handling)
        if (!empty($this->filterShift)) {
            $selectedShift = BranchOperationalShift::find($this->filterShift);
            if ($selectedShift && branch()) {
                $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
                $shiftStartTime = $this->convertUtcTimeToRestaurantTime($selectedShift->start_time, $restaurantTimezone);
                $shiftEndTime = $this->convertUtcTimeToRestaurantTime($selectedShift->end_time, $restaurantTimezone);

                // Build query to match orders within shift times for each day in the date range
                $ordersQuery->where(function($query) use ($selectedShift, $restaurantTimezone, $shiftStartTime, $shiftEndTime, $tz, $dateFormat) {
                    // Parse the start and end dates from UTC back to restaurant timezone to iterate
                    $startDate = parseRestaurantDate($this->startDate, $dateFormat, $tz);
                    $endDate = parseRestaurantDate($this->endDate, $dateFormat, $tz);

                    $currentDate = $startDate->copy();
                    $firstCondition = true;

                    while ($currentDate->lte($endDate)) {
                        $dayOfWeek = $currentDate->format('l'); // e.g., "Monday"
                        $shiftDays = $selectedShift->day_of_week ?? [];

                        // Check if shift applies to this day
                        if (in_array('All', $shiftDays) || in_array($dayOfWeek, $shiftDays)) {
                            // Parse shift times in restaurant timezone for this date
                            $shiftStart = Carbon::parse(
                                $currentDate->toDateString() . ' ' . $shiftStartTime,
                                $restaurantTimezone
                            );

                            $shiftEnd = Carbon::parse(
                                $currentDate->toDateString() . ' ' . $shiftEndTime,
                                $restaurantTimezone
                            );

                            // Handle overnight shifts
                            if ($shiftEndTime < $shiftStartTime) {
                                $shiftEnd->addDay();
                            }

                            // Convert to UTC for database query
                            $shiftStartUTC = $shiftStart->setTimezone('UTC')->toDateTimeString();
                            $shiftEndUTC = $shiftEnd->setTimezone('UTC')->toDateTimeString();

                            // Use where for first condition, orWhere for subsequent ones
                            if ($firstCondition) {
                                $query->where(function($q) use ($shiftStartUTC, $shiftEndUTC) {
                                    $q->where('orders.date_time', '>=', $shiftStartUTC)
                                      ->where('orders.date_time', '<=', $shiftEndUTC);
                                });
                                $firstCondition = false;
                            } else {
                                $query->orWhere(function($q) use ($shiftStartUTC, $shiftEndUTC) {
                                    $q->where('orders.date_time', '>=', $shiftStartUTC)
                                      ->where('orders.date_time', '<=', $shiftEndUTC);
                                });
                            }
                        }

                        $currentDate->addDay();
                    }
                });
            }
        }

        // Get all orders
        $allOrders = $ordersQuery->get();

        // Calculate status counts after shift filtering
        $statusCounts = $allOrders->groupBy('status')->map(function ($group) {
            return $group->count();
        });

        $ordersTotal = $allOrders->count();

        // Apply pagination after filtering
        $orders = $allOrders->take($this->perPage);

        $orders->each(function ($order) {
            $orderTypeSlug = optional($order->orderType)->slug ?? $order->order_type ?? null;
            $discountedSubTotal = $order->sub_total - ($order->discount_amount ?? 0);

            $disallowedChargeTotal = collect($order->charges ?? [])
                ->filter(function ($item) use ($orderTypeSlug) {
                    $charge = $item->charge;
                    if (!$charge) {
                        return false;
                    }

                    $allowedTypes = $charge->order_types ?? [];

                    return !empty($allowedTypes) && !in_array($orderTypeSlug, $allowedTypes);
                })
                ->sum(function ($item) use ($discountedSubTotal) {
                    $charge = $item->charge;

                    return $charge ? $charge->getAmount($discountedSubTotal) : 0;
                });

            $order->display_total = max(($order->total ?? 0) - $disallowedChargeTotal, 0);
        });
        $this->hasMore = $ordersTotal > $orders->count();
        $this->isLoadingMore = false;

        return [
            'orders' => $orders,
            'ordersTotal' => $ordersTotal,
            'statusCounts' => $statusCounts,
        ];
    }

    private function convertUtcTimeToRestaurantTime(?string $time, string $restaurantTimezone): string
    {
        if (!$time) {
            return '00:00';
        }

        return Carbon::now(self::DEFAULT_TIMEZONE)
            ->setTimeFromTimeString($time)
            ->setTimezone($restaurantTimezone)
            ->format('H:i');
    }

    private function getRestaurantTimezone(): string
    {
        return branch()->restaurant->timezone ?? timezone();
    }

    /**
     * Loyalty stamp columns/features are optional. Order merge must work when
     * the Loyalty module is disabled or stamp columns are missing.
     */
    private function loyaltyStampFeaturesAvailable(): bool
    {
        try {
            return function_exists('module_enabled')
                && module_enabled('Loyalty')
                && Schema::hasColumn('orders', 'stamp_discount_amount')
                && Schema::hasColumn('order_items', 'is_free_item_from_stamp');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
