<?php

namespace App\Livewire\Order;

use App\Concerns\PrintsShopKot;
use App\Services\ShopCartKotPrintUrls;
use App\Enums\OrderStatus;
use App\Events\OrderTableAssigned;
use App\Events\OrderWaiterAssigned;
use Carbon\Carbon;
use App\Models\Tax;
use App\Models\Order;
use App\Models\Table;
use App\Models\Printer;
use Livewire\Component;
use App\Models\MenuItem;
use App\Models\OrderTax;
use App\Models\OrderItem;
use App\Models\OrderCharge;
use App\Models\Payment;
use App\Models\RestaurantCharge;
use Livewire\Attributes\On;
use App\Traits\PrinterSetting;
use App\Models\KotCancelReason;
use App\Models\DeliveryExecutive;
use App\Models\Kot;
use App\Models\KotItem;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class OrderDetail extends Component
{

    use LivewireAlert, PrinterSetting, PrintsShopKot;

    public $order;
    public $taxes;
    public $total = 0;
    public $subTotal = 0;
    public $showOrderDetail = false;
    public $showAddCustomerModal = false;
    public $showTableModal = false;
    public $showTableChangeConfirmationModal = false;
    public $pendingTable = null;
    public $cancelOrderModal = false;
    public $deleteOrderModal = false;
    public $tableNo;
    public $tableId;
    public $orderStatus;
    public $discountAmount = 0;
    public $deliveryExecutives;
    public $deliveryExecutive;
    public $orderProgressStatus;
    public $fromPos = null;
    public $confirmDeleteModal = false;
    public $cancelReasons;
    public $cancelReason;
    public $cancelReasonText;
    public $totalTaxAmount = 0;
    public $taxMode;
    public $currencyId;
    public $users;
    public $selectWaiter;
    public $confirmDeleteItemModal = false;
    public $itemToDelete;
    public $showKotAlert = false;
    public $usingKotItemsFallback = false;
    public $orderItemsCount = 0;
    public $showPrintOptionsModal = false;
    public $printMode = null; // 'all', 'summary', 'individual', 'single'
    public $selectedSplitId = null;

    // Discount modal
    public $showDiscountModal = false;
    public $discountType = 'fixed';
    public $discountValue = null;

    public function mount()
    {
        $this->total = 0;
        $this->subTotal = 0;
        $this->taxes = Tax::all();
        $this->deliveryExecutives = DeliveryExecutive::assignable()
            ->where('is_online', true)
            ->get();
        if ($this->order) {
            $this->deliveryExecutive = $this->order->delivery_executive_id;
        }
        $this->cancelReasons = KotCancelReason::where('cancel_order', true)->get();

        $this->users = \App\Services\Pos\PosWaitersCache::remember((int) restaurant()->id, (int) branch()->id);
    }

    public function printOrder($orderId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        // Check if order has split payments
        if ($order->split_type && $order->splitOrders()->where('status', 'paid')->count() > 0) {
            // Open print options modal instead of printing directly
            $this->showPrintOptionsModal = true;
            $this->printMode = null;
            $this->selectedSplitId = null;
            return;
        }

        // Original print logic for non-split orders
        $this->executePrint($orderId);
    }

    public function executePrint($orderId)
    {
        $order = Order::find($orderId);

        // Check if order has paid split orders
        $hasPaidSplits = $order->split_type && $order->splitOrders()->where('status', 'paid')->count() > 0;

        $orderPlaces = \App\Models\MultipleOrder::with('printerSetting')->get();

        foreach ($orderPlaces as $orderPlace) {
            $printerSetting = $orderPlace->printerSetting;
        }

        try {

            switch ($printerSetting?->printing_choice) {
            case 'directPrint':

                $this->handleOrderPrint($orderId);
                    break;
            default:
                // Use print-split-receipts route if order has paid splits, otherwise regular print
                $url = $hasPaidSplits
                    ? route('orders.print-split-receipts', ['orderId' => $orderId])
                    : route('orders.print', $orderId);
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

    public function handlePrintOption($mode)
    {
        if (!$this->order) {
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
            'orderId' => $this->order->id,
            'includeSummary' => true
        ]);

        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
    }

    public function printSummaryReceipt()
    {
        $url = route('orders.print', $this->order->id);
        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
    }

    public function printIndividualReceipts()
    {
        // Use the optimized controller for all individual receipts on one page
        $url = route('orders.print-split-receipts', ['orderId' => $this->order->id]);
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
            'orderId' => $this->order->id,
            'splitId' => $this->selectedSplitId
        ]);
        $this->dispatch('print_location', $url);

        $this->showPrintOptionsModal = false;
        $this->printMode = null;
        $this->selectedSplitId = null;
    }

    /**
     * Eager loads used when opening or refreshing the order detail drawer.
     *
     * @return array<int, string>
     */
    protected function orderDetailRelations(): array
    {
        $relations = [
            'items',
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'kot.items.menuItem',
            'kot.items.menuItemVariation',
            'kot.items.modifierOptions',
            'payments',
            'cancelReason',
            'orderCashCollection',
            'deliveryExecutive',
            'waiter',
            'orderType',
        ];
        if (function_exists('module_enabled') && module_enabled('Hotel') && in_array('Hotel', restaurant_modules())) {
            $relations[] = 'hotelStay.room';
            $relations[] = 'hotelStay.stayGuests.guest';
            $relations[] = 'hotelRoom';
        }

        return $relations;
    }

    #[On('showOrderDetail')]
    public function showOrder($id, $fromPos = null)
    {
        $this->order = Order::with($this->orderDetailRelations())
            ->when(is_numeric($id), fn ($q) => $q->where('id', $id), fn ($q) => $q->where('uuid', $id))
            ->first();

        if (!$this->order) {
            $this->showOrderDetail = false;
            return;
        }

        if (isHotelModuleEnabled()) {
            $this->order->append(['context_room_number', 'context_stay_number']);
        }

        $this->orderStatus = $this->order->status;
        $this->fromPos = $fromPos;
        $this->orderProgressStatus = $this->order->order_status?->value ?? 'placed';
        $restaurant = restaurant();
        $this->currencyId = $restaurant->currency_id;
        $this->taxMode = $this->order?->tax_mode ?? ($this->restaurant->tax_mode ?? 'order');

        if ($this->taxMode === 'item') {
            $this->totalTaxAmount = $this->order?->total_tax_amount ?? 0;
        }

        $this->selectWaiter = $this->order->waiter_id;
        $this->usingKotItemsFallback = false;
        $this->orderItemsCount = $this->order->items->count();

        // Some POS/KOT flows persist rows in kot_items while order_items may still be empty.
        // Use KOT items for display/count fallback so recent-order detail stays accurate.
        if ($this->orderItemsCount === 0) {
            $kotItems = $this->order->kot
                ->where('status', '!=', 'cancelled')
                ->flatMap(function ($kot) {
                    return $kot->items->where('status', '!=', 'cancelled');
                })
                ->values();

            if ($kotItems->isNotEmpty()) {
                $this->order->setRelation('items', $kotItems);
                $this->usingKotItemsFallback = true;
                $this->orderItemsCount = $kotItems->count();
            }
        }

        $this->showOrderDetail = true;

        $this->showKotAlert = $this->order->kot->isEmpty() && !in_array($this->order->status, ['draft', 'canceled']);

        // Reset table change confirmation modal state to prevent it from opening automatically
        $this->showTableChangeConfirmationModal = false;
        $this->pendingTable = null;
    }

    #[On('setOrderDetailTable')]
    public function setTable(Table $table)
    {
        // Check if there's an existing table assigned
        $hasTable = !is_null($this->tableNo) ||
                   ($this->order && $this->order->table);

        if ($hasTable) {
            // Store the selected table temporarily and show confirmation modal
            $this->pendingTable = $table;
            $this->showTableModal = false;
            $this->showTableChangeConfirmationModal = true;
        } else {
            // No existing table, apply immediately
            $this->tableNo = $table->table_code;
            $this->tableId = $table->id;

            if ($this->order) {
                $currentOrder = Order::where('id', $this->order->id)->first();

                Table::where('id', $currentOrder->table_id)->update([
                    'available_status' => 'available'
                ]);

                $previousTable = $currentOrder->table_id ? Table::find($currentOrder->table_id) : null;
                $currentOrder->update(['table_id' => $table->id]);

                if ($this->order->date_time->timezone(timezone())->format('d-m-Y') == now(timezone())->format('d-m-Y')) {
                    Table::where('id', $this->tableId)->update([
                        'available_status' => 'running'
                    ]);
                }

                $this->order = $currentOrder->fresh(['customer', 'branch.restaurant', 'waiter', 'table']);

                OrderTableAssigned::dispatch($this->order, $table, $previousTable);

                $this->dispatch('showOrderDetail', id: $this->order->id);
            }

            $this->dispatch('posOrderSuccess');
            $this->dispatch('refreshOrders');
            $this->dispatch('refreshPos');

            $this->showTableModal = false;
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

        $this->tableNo = $table->table_code;
        $this->tableId = $table->id;

        if ($this->order) {
            $currentOrder = Order::where('id', $this->order->id)->first();

            // Get previous table before updating
            $previousTable = $currentOrder->table_id ? Table::find($currentOrder->table_id) : null;

            // Release previous table
            if ($currentOrder->table_id) {
                Table::where('id', $currentOrder->table_id)->update([
                    'available_status' => 'available'
                ]);
            }

            $currentOrder->update(['table_id' => $table->id]);

            if ($this->order->date_time->format('d-m-Y') == now()->format('d-m-Y')) {
                Table::where('id', $this->tableId)->update([
                    'available_status' => 'running'
                ]);
            }

            $this->order = $currentOrder->fresh(['customer', 'branch.restaurant', 'waiter', 'table']);

            OrderTableAssigned::dispatch($this->order, $table, $previousTable);

            $this->dispatch('showOrderDetail', id: $this->order->id);
        }

        // Clear pending table and close modals
        $this->pendingTable = null;
        $this->showTableChangeConfirmationModal = false;

        $this->dispatch('posOrderSuccess');
        $this->dispatch('refreshOrders');
        $this->dispatch('refreshPos');
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

    public function saveOrderStatus()
    {
        if ($this->order) {
            Order::where('id', $this->order->id)->update(['status' => $this->orderStatus]);

            $this->dispatch('posOrderSuccess');
            $this->dispatch('refreshOrders');
            $this->dispatch('refreshPos');
        }
    }

    public function showAddCustomer($id)
    {
        $this->order = Order::find($id);
        $this->showAddCustomerModal = true;
    }

    public function showDeleteItemModal($id)
    {
        $this->itemToDelete = $id;
        $this->confirmDeleteItemModal = true;
    }

    public function deleteOrderItems($id)
    {
        // CRITICAL: Do NOT delete kot_items - preserve KOT/kitchen history and audit trail.
        // Only remove the order item from the bill; kot_items stay for reporting and kitchen flow.
        OrderItem::destroy($id);

        if ($this->order) {
            $this->order->refresh();

            if ($this->order->items->count() === 0) {
                $this->deleteOrder($this->order->id);
                return;
            }

            // Recalculate order totals properly
            $this->recalculateOrderTotals();
        }

        $this->confirmDeleteItemModal = false;
        $this->itemToDelete = null;

        $this->alert('success', __('messages.orderItemDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->dispatch('refreshPos');
    }

    public function updatedOrderProgressStatus($value)
    {
        if (empty($this->order) || is_null($value)) {
            return;
        }

        if (OrderStatus::tryFrom((string) $value) === null) {
            return;
        }

        $this->order->update(['order_status' => $value]);
        $this->orderProgressStatus = $value;

        if ($value === 'confirmed') {
            $this->order->kot->each(function ($kot) {
                $kot->update(['status' => 'in_kitchen']);
            });
        }

        $this->dispatch('posOrderSuccess');
        $this->dispatch('refreshOrders');
        $this->dispatch('refreshPos');
    }

    public function saveOrder($action)
    {

        switch ($action) {
        case 'bill':
            $successMessage = __('messages.billedSuccess');
            $status = 'billed';
            $tableStatus = 'running';
                break;

        case 'kot':
                return $this->redirect(route('pos.show', $this->order->table_id));
        }

        $taxes = Tax::all();

        Order::where('id', $this->order->id)->update([
            'date_time' => now(),
            'status' => $status
        ]);

        if ($status == 'billed') {
            $totalTaxAmount = 0;

            // CRITICAL: If OrderItems already exist (e.g. customer-site order), do NOT create new ones
            // from KOT items - that would duplicate items. Only create from KOT when no order items exist (POS flow).
            $existingOrderItemsCount = $this->order->items()->count();
            if ($existingOrderItemsCount === 0) {
                foreach ($this->order->kot as $kot) {
                    foreach ($kot->items as $item) {
                        $price = (($item->menu_item_variation_id) ? $item->menuItemVariation->price : $item->menuItem->price);
                        $amount = $price * $item->quantity;

                        // Calculate tax for item-level taxation
                        $taxAmount = 0;
                        $taxPercentage = 0;
                        $taxBreakup = null;

                        if ($this->taxMode === 'item') {
                            $menuItem = $item->menuItem;
                            $taxes = $menuItem->taxes ?? collect();
                            $isInclusive = restaurant()->tax_inclusive ?? false;

                            if ($taxes->isNotEmpty()) {
                                $taxResult = MenuItem::calculateItemTaxes($price, $taxes, $isInclusive);
                                $taxAmount = $taxResult['tax_amount'] * $item->quantity;
                                $taxPercentage = $taxResult['tax_percentage'];
                                $taxBreakup = json_encode($taxResult['tax_breakdown']);
                                $totalTaxAmount += $taxAmount;
                            }
                        }

                        OrderItem::create([
                            'order_id' => $this->order->id,
                            'menu_item_id' => $item->menu_item_id,
                            'menu_item_variation_id' => $item->menu_item_variation_id,
                            'quantity' => $item->quantity,
                            'price' => $item->price ?? $price,
                            'amount' => $item->amount ?? $amount, // Preserve amount from kot_items (includes discounts)
                            'tax_amount' => $taxAmount,
                            'tax_percentage' => $taxPercentage,
                            'tax_breakup' => $taxBreakup,
                            'is_free_item_from_stamp' => $item->is_free_item_from_stamp ?? false,
                            'stamp_rule_id' => $item->stamp_rule_id,
                        ]);
                    }
                }
            } else {
                // OrderItems already exist (customer-site flow) - use existing items for tax total
                if ($this->taxMode === 'item') {
                    foreach ($this->order->items as $orderItem) {
                        $totalTaxAmount += ($orderItem->tax_amount ?? 0);
                    }
                }
            }

            if ($this->taxMode === 'order') {
                // Only create OrderTax if they don't already exist (e.g. customer-site order)
                $existingTaxIds = OrderTax::where('order_id', $this->order->id)->pluck('tax_id')->toArray();
                foreach ($taxes as $value) {
                    if (!in_array($value->id, $existingTaxIds)) {
                        OrderTax::create([
                            'order_id' => $this->order->id,
                            'tax_id' => $value->id
                        ]);
                    }
                }
            }

            $this->total = 0;
            $this->subTotal = 0;

            foreach ($this->order->load('items')->items as $value) {
                if ($this->taxMode === 'item') {
                    $isInclusive = restaurant()->tax_inclusive ?? false;
                    if ($isInclusive) {
                        // For inclusive tax: subtract tax from amount to get subtotal
                        $this->subTotal += ($value->amount - ($value->tax_amount ?? 0));
                    } else {
                        // For exclusive tax: amount is subtotal
                        $this->subTotal += $value->amount;
                    }
                } else {
                    $this->subTotal += $value->amount;
                }
                $this->total += $value->amount;
            }

            // Step 1: Calculate discounts first
            $regularDiscount = 0;
            $loyaltyDiscount = floatval($this->order->loyalty_discount_amount ?? 0);

            if ($this->order->discount_type === 'percent') {
                $regularDiscount = round(($this->subTotal * $this->order->discount_value) / 100, 2);
            } elseif ($this->order->discount_type === 'fixed') {
                $regularDiscount = min($this->order->discount_value, $this->subTotal);
            }
            $this->discountAmount = $regularDiscount;

            // Step 2: Calculate net = subtotal - regular discount - loyalty discount
            $net = $this->subTotal - $regularDiscount - $loyaltyDiscount;

            // Step 3: Calculate service charges on net (after discounts)
            $charges = collect($this->order->charges ?? [])
                ->map(fn ($charge) => $charge->charge)
                ->filter();
            $chargeTotals = RestaurantCharge::calculateTotals($charges, $net);
            $serviceTotal = $chargeTotals['total'];

            // Step 4: Calculate tax_base based on setting
            $includeChargesInTaxBase = restaurant()->include_charges_in_tax_base ?? true;
            $taxBase = RestaurantCharge::resolveTaxBase($net, $charges, $includeChargesInTaxBase);

            // Step 5: Calculate taxes on tax_base
            if ($this->taxMode === 'order') {
                foreach ($taxes as $value) {
                    $taxAmount = ($value->tax_percent / 100) * $taxBase;
                    $this->total += $taxAmount;
                    $totalTaxAmount += $taxAmount;
                }
            } elseif ($this->taxMode === 'item') {
                $isInclusive = restaurant()->tax_inclusive ?? false;
                if (!$isInclusive) {
                    // For exclusive taxes, add tax to total
                    $this->total += $totalTaxAmount;
                }
            }

            // Apply discounts to total
            $this->total -= $regularDiscount;
            $this->total -= $loyaltyDiscount;

            // CRITICAL: Redeem loyalty stamps AFTER OrderItems are created
            // Only redeem if POS loyalty is enabled (stamps enabled for POS)
            if ($this->order->customer_id && $this->isStampsEnabledForPOS() && module_enabled('Loyalty')) {
                try {
                    // Get stamp rule IDs from kot_items
                    $stampRuleIdsFromKotItems = [];
                    foreach ($this->order->kot as $kot) {
                        foreach ($kot->items as $kotItem) {
                            if ($kotItem->stamp_rule_id && !in_array($kotItem->stamp_rule_id, $stampRuleIdsFromKotItems)) {
                                $stampRuleIdsFromKotItems[] = $kotItem->stamp_rule_id;
                            }
                        }
                    }

                    // Redeem stamps for each stamp rule
                    if (!empty($stampRuleIdsFromKotItems)) {
                        $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);

                        foreach ($stampRuleIdsFromKotItems as $stampRuleId) {
                            // Check if more items can be redeemed
                            $this->order->refresh();
                            $this->order->load('items');

                            $eligibleItemsCount = $this->order->items()
                                ->where(function ($q) use ($stampRuleId) {
                                    $q->where('stamp_rule_id', $stampRuleId)
                                      ->where(function ($q2) {
                                          $q2->whereNull('is_free_item_from_stamp')
                                             ->orWhere('is_free_item_from_stamp', false);
                                      });
                                })
                                ->orWhere(function ($q) {
                                    $q->whereNull('stamp_rule_id')
                                      ->where(function ($q2) {
                                          $q2->whereNull('is_free_item_from_stamp')
                                             ->orWhere('is_free_item_from_stamp', false);
                                      });
                                })
                                ->count();

                            $existingTransactionsCount = 0;
                            if (module_enabled('Loyalty')) {
                                $existingTransactionsCount = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $this->order->id)
                                    ->where('stamp_rule_id', $stampRuleId)
                                    ->where('type', 'REDEEM')
                                    ->count();
                            }

                            // Redeem stamps for all eligible items
                            if ($eligibleItemsCount > $existingTransactionsCount) {
                                $this->redeemStampsForAllEligibleItems($this->order, $stampRuleId);
                                $this->order->refresh();
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to redeem stamps in OrderDetail::saveOrder: ' . $e->getMessage());
                }
            }

            $updateData = [
                'sub_total' => $this->subTotal,
                'total' => $this->total,
                'discount_amount' => $this->discountAmount,
                'total_tax_amount' => $totalTaxAmount,
                'tax_base' => $taxBase ?? null,
            ];
            // Persist loyalty/stamp discounts from order (set by loyalty service or customer site)
            if ($this->order->loyalty_discount_amount !== null) {
                $updateData['loyalty_discount_amount'] = round((float) $this->order->loyalty_discount_amount, 2);
            }
            if ($this->order->stamp_discount_amount !== null) {
                $updateData['stamp_discount_amount'] = round((float) $this->order->stamp_discount_amount, 2);
            }
            if ($this->order->loyalty_points_redeemed !== null) {
                $updateData['loyalty_points_redeemed'] = (int) $this->order->loyalty_points_redeemed;
            }
            Order::where('id', $this->order->id)->update($updateData);
        }

        Table::where('id', $this->tableId)->update([
            'available_status' => $tableStatus
        ]);


        $this->alert('success', $successMessage, [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        if ($status == 'billed') {
            $this->order = Order::with($this->orderDetailRelations())->find($this->order->id);
            $this->orderStatus = $this->order->status;

            // Close the slide-over from the client (Alpine leave transition) instead of flipping
            // showOrderDetail in this response (avoids fighting entangle / a rough close). Sync
            // Livewire state after the animation; do not dispatch showOrderDetail — that reopens the drawer.
            $this->js(<<<'JS'
                requestAnimationFrame(() => {
                    const el = document.getElementById('order-detail-drawer');
                    if (el && window.Alpine) {
                        const d = Alpine.$data(el);
                        if (d && Object.prototype.hasOwnProperty.call(d, 'show')) {
                            d.show = false;
                        }
                    }
                    setTimeout(() => $wire.set('showOrderDetail', false), 280);
                });
            JS);

            $this->dispatch('posOrderSuccess');
            $this->dispatch('refreshOrders');
            $this->dispatch('resetPos');
        }
    }

    public function showPayment($id)
    {
        $this->dispatch('showPaymentModal', id: $id);
    }

    /**
     * Redeem stamps for all eligible items for a given order and stamp rule.
     * This ensures multiple items can redeem stamps correctly.
     */
    private function redeemStampsForAllEligibleItems(Order $order, int $stampRuleId): void
    {
        if (!module_enabled('Loyalty')) {
            return;
        }

        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $maxIterations = 100;

            for ($i = 0; $i < $maxIterations; $i++) {
                // Count existing transactions BEFORE this iteration
                $beforeTransactionCount = 0;
                if (module_enabled('Loyalty')) {
                    $beforeTransactionCount = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $order->id)
                        ->where('stamp_rule_id', $stampRuleId)
                        ->where('type', 'REDEEM')
                        ->count();
                }

                // Count eligible items
                $order->refresh();
                $order->load('items');

                $eligibleItemsCount = $order->items()
                    ->where(function ($q) use ($stampRuleId) {
                        $q->where('stamp_rule_id', $stampRuleId)
                          ->where(function ($q2) {
                              $q2->whereNull('is_free_item_from_stamp')
                                 ->orWhere('is_free_item_from_stamp', false);
                          });
                    })
                    ->orWhere(function ($q) {
                        $q->whereNull('stamp_rule_id')
                          ->where(function ($q2) {
                              $q2->whereNull('is_free_item_from_stamp')
                                 ->orWhere('is_free_item_from_stamp', false);
                          });
                    })
                    ->count();

                if ($eligibleItemsCount <= 0) {
                    break;
                }

                // Call the service to redeem one item
                $result = $loyaltyService->redeemStamps($order, $stampRuleId);

                if (!is_array($result) || !($result['success'] ?? false)) {
                    break;
                }

                // Count transactions AFTER this iteration
                $order->refresh();
                $afterTransactionCount = 0;
                if (module_enabled('Loyalty')) {
                    $afterTransactionCount = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $order->id)
                        ->where('stamp_rule_id', $stampRuleId)
                        ->where('type', 'REDEEM')
                        ->count();
                }

                // If no new transaction was created, stop looping
                if ($afterTransactionCount <= $beforeTransactionCount) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('redeemStampsForAllEligibleItems in OrderDetail - failed', [
                'order_id' => $order->id ?? null,
                'stamp_rule_id' => $stampRuleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function cancelOrderStatus($id)
    {
        // Validate that a cancel reason is provided
        if (!$this->cancelReason && !$this->cancelReasonText) {
            $this->alert('error', __('modules.settings.cancelReasonRequired'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
            return;
        }

        if ($id) {
            $order = Order::find($id);

            if ($order) {
                $order->update([
                    'status' => 'canceled',
                    'order_status' => 'cancelled',
                    'cancel_reason_id' => $this->cancelReason,
                    'cancel_reason_text' => $this->cancelReasonText,
                    'cancelled_by' => auth()->id(),
                    'cancel_time' => Carbon::now()->setTimezone(restaurant()->timezone),
                ]);

                // Update table status
                if ($order->table_id) {
                    $table = Table::find($order->table_id);

                    if ($table) {
                        $table->update(['available_status' => 'available']);

                        // Release table session lock if exists
                        if ($table->tableSession) {
                            $table->tableSession->releaseLock();
                        }
                    }
                }


                $this->alert('success', __('messages.orderCanceled'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close'),
                ]);

                $this->confirmDeleteModal = false;
                $this->cancelReason = null;
                $this->cancelReasonText = null;

                return $this->redirect(route('pos.index'));
            }
        }
    }

    public function cancelOrder($id)
    {
        // Validate that a cancel reason is provided
        if (!$this->cancelReason && !$this->cancelReasonText) {
            $this->alert('error', __('modules.settings.cancelReasonRequired'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
            return;
        }

        $order = Order::find($id);

        if ($order) {
            $order->update([
                'status' => 'canceled',
                'order_status' => 'cancelled',
                'cancel_reason_id' => $this->cancelReason,
                'cancel_reason_text' => $this->cancelReasonText,
                'cancelled_by' => auth()->id(),
                'cancel_time' => now(),
            ]);
            $order->kot()->delete();
            $order->payments()->delete();

            if ($order->table_id) {
                Table::where('id', $order->table_id)->update([
                    'available_status' => 'available',
                ]);
            }
            $this->cancelOrderModal = false;
            $this->confirmDeleteModal = false;
            $this->cancelReason = null;
            $this->cancelReasonText = null;
            $this->dispatch('showOrderDetail', id: $this->order->id);
            $this->dispatch('posOrderSuccess');
            $this->dispatch('refreshOrders');

            $this->alert('success', __('messages.orderCanceled'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            if ($this->fromPos) {
                return $this->redirect(route('pos.index'));
            } else {
                $this->dispatch('resetPos');
            }
        }
    }

    public function paymentReceived($orderId, $status)
    {
        $order = Order::with('payments')->find($orderId);

        if (!$order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }

        if ($status === 'received') {
            $wasPaid = $order->status === 'paid';
            $amountPaid = $order->payments->sum('amount');
            $order->update([
                'status' => 'paid',
                'amount_paid' => $amountPaid
            ]);

            if (!$wasPaid && $order->placed_via === 'shop') {
                $order->loadMissing('branch.restaurant');
                if (ShopCartKotPrintUrls::shouldPrintKotOnShopPaymentVerified($order, $order->branch?->restaurant)) {
                    $this->printKot($order->fresh([
                        'kot.items.menuItem',
                        'kot.items.menuItemVariation',
                        'kot.items.modifierOptions',
                    ]));
                }
            }

            // Earn loyalty points/stamps after payment confirmation (customer site + kiosk)
            if (!$wasPaid && module_enabled('Loyalty')) {
                $hasPointsEarned = class_exists(\Modules\Loyalty\Entities\LoyaltyLedger::class)
                    && \Modules\Loyalty\Entities\LoyaltyLedger::where('order_id', $order->id)
                        ->where('type', 'EARN')
                        ->exists();
                $hasStampsEarned = class_exists(\Modules\Loyalty\Entities\LoyaltyStampTransaction::class)
                    && \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $order->id)
                        ->where('type', 'EARN')
                        ->exists();

                if (!$hasPointsEarned || !$hasStampsEarned) {
                    event(new \App\Events\SendNewOrderReceived($order));
                }
            }
        } elseif ($status === 'not_received') {
            $latestPayment = $order->payments->last();
            if ($latestPayment) {
                $latestPayment->delete();
            }
            $order->update(['status' => 'payment_due']);
        }

        $this->alert('success', __('messages.statusUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->dispatch('showOrderDetail', id: $this->order->id);
        $this->dispatch('refreshOrders');
        $this->dispatch('refreshPos');
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


        $this->deleteOrderModal = false;
        $this->showOrderDetail = false;
        $order = null;
        $this->order = null;

        $this->alert('success', __('messages.orderDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);


        if ($this->fromPos) {
            return $this->redirect(route('pos.index'));
        }
        else {

            $this->dispatch('refreshOrders');
            $this->dispatch('refreshPos');
            $this->dispatch('refreshKots');
        }

    }

    public function saveDeliveryExecutive()
    {
        $selectedExecutive = DeliveryExecutive::find($this->deliveryExecutive);

        if (!$selectedExecutive || !$selectedExecutive->isAssignable()) {
            $this->alert('error', __('messages.invalidRequest'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            return;
        }

        $this->order->update(['delivery_executive_id' => $this->deliveryExecutive]);
        $this->order->fresh();
        $this->alert('success', __('messages.deliveryExecutiveAssigned'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function removeCharge($chargeId)
    {
        $charge = OrderCharge::find($chargeId);

        if ($charge) {
            $charge->delete();
            $this->order->refresh();

            // Recalculate order totals properly
            $this->recalculateOrderTotals();
        }
    }

    public function updatePaymentMethod($id, $paymentMethod)
    {
        if (!$id || !$paymentMethod || !$this->order) {
            return;
        }

        $payment = $this->order->payments()->whereId($id)->first();

        if (!$payment) {
            return;
        }

        $payment->payment_method = $paymentMethod;
        $payment->save();

        $hasPaymentDue = $this->order->payments->contains('payment_method', 'due');

        $newStatus = $hasPaymentDue ? 'payment_due' : 'paid';

        if ($this->order->status !== $newStatus) {
            $this->order->status = $newStatus;
            $this->order->save();
        }

        $this->alert('success', __('messages.statusUpdated'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->dispatch('showOrderDetail', id: $this->order->id);
        $this->dispatch('refreshOrders');
    }

    public function updatedSelectWaiter($value)
    {
        if ($this->order) {
            $currentOrder = Order::with(['waiter', 'table', 'branch.restaurant', 'customer'])->find($this->order->id);
            $previousWaiter = $currentOrder?->waiter;

            $currentOrder?->update(['waiter_id' => $value ?: null]);
            $this->order = $currentOrder?->fresh(['waiter', 'table', 'branch.restaurant', 'customer']);

            if ($this->order?->waiter_id) {
                OrderWaiterAssigned::dispatch($this->order, $previousWaiter);
            }

            $this->alert('success', __('messages.waiterUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }
    }

    /**
     * Create KOT for the order
     */
    public function createKot()
    {
        // Validate order exists
        if (!$this->order || $this->order->kot->isNotEmpty() || $this->order->items->isEmpty()) {
            $this->showAlert('error', __('messages.invalidRequest'));
            return;
        }

        try {
            // Check if Kitchen module is enabled
            if (in_array('Kitchen', restaurant_modules()) && in_array('kitchen', custom_module_plugins())) {
                // Group items by kot_place_id
                $groupedItems = [];

                foreach ($this->order->items as $orderItem) {
                    $menuItem = $orderItem->menuItem;
                    $kotPlaceId = $menuItem->kot_place_id ?? null;

                    if (!$kotPlaceId) {
                        continue;
                    }

                    $groupedItems[$kotPlaceId][] = [
                        'order_item' => $orderItem,
                        'menu_item_id' => $menuItem->id,
                        'variation_id' => $orderItem->menu_item_variation_id,
                        'quantity' => $orderItem->quantity,
                        'modifiers' => $orderItem->modifierOptions->pluck('id')->toArray(),
                        'note' => $orderItem->note,
                    ];
                }

                // Create KOT for each place
                foreach ($groupedItems as $kotPlaceId => $items) {
                    $kot = Kot::create([
                        'kot_number' => Kot::generateKotNumber($this->order->branch),
                        'order_id' => $this->order->id,
                        'order_type_id' => $this->order->order_type_id,
                        'token_number' => Kot::generateTokenNumber(branch()->id, $this->order->order_type_id),
                        'kitchen_place_id' => $kotPlaceId,
                    ]);

                    foreach ($items as $item) {
                        $kotItem = KotItem::create([
                            'kot_id' => $kot->id,
                            'menu_item_id' => $item['menu_item_id'],
                            'menu_item_variation_id' => $item['variation_id'],
                            'quantity' => $item['quantity'],
                            'note' => $item['note'],
                            'order_type_id' => $this->order->order_type_id,
                            'order_type' => $this->order->order_type,
                        ]);
                        $kotItem->modifierOptions()->sync($item['modifiers']);
                    }
                }
            } else {
                // No kitchen module: single KOT for all items
                $kot = Kot::create([
                    'kot_number' => Kot::generateKotNumber($this->order->branch),
                    'order_id' => $this->order->id,
                    'order_type_id' => $this->order->order_type_id,
                    'token_number' => Kot::generateTokenNumber(branch()->id, $this->order->order_type_id),
                ]);

                foreach ($this->order->items as $orderItem) {
                    $kotItem = KotItem::create([
                        'kot_id' => $kot->id,
                        'menu_item_id' => $orderItem->menu_item_id,
                        'menu_item_variation_id' => $orderItem->menu_item_variation_id,
                        'quantity' => $orderItem->quantity,
                        'note' => $orderItem->note,
                        'order_type_id' => $this->order->order_type_id,
                        'order_type' => $this->order->order_type,
                    ]);
                    $kotItem->modifierOptions()->sync($orderItem->modifierOptions->pluck('id')->toArray());
                }
            }

            // Refresh order data
            $this->order->refresh();
            $this->showKotAlert = false;

            $this->showAlert('success', __('messages.kotGenerated'));

            // Refresh related components
            $this->dispatch('refreshOrders');
            $this->dispatch('refreshPos');
            $this->dispatch('refreshKots');
            $this->dispatch('showOrderDetail', id: $this->order->id);

        } catch (\Exception $e) {
            $this->showAlert('error', __('messages.invalidRequest') . ': ' . $e->getMessage());
        }
    }

    private function showAlert($type, $message)
    {
        $this->alert($type, $message, [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    /**
     * Whether discount can be added/removed for the current order.
     * Allowed for billed and payment_due (due payments) orders.
     */
    private function canModifyDiscount(): bool
    {
        return $this->order && in_array($this->order->status, ['billed', 'payment_due'], true);
    }

    /**
     * Sync the due payment row with the remaining balance after total changes.
     */
    private function syncDuePaymentAmount(): void
    {
        if (!$this->order || $this->order->status !== 'payment_due') {
            return;
        }

        Payment::where('order_id', $this->order->id)->where('payment_method', 'due')->delete();

        $orderPaidAmount = Payment::where('order_id', $this->order->id)
            ->where('payment_method', '!=', 'due')
            ->sum('amount');

        $remaining = max(0, round(floatval($this->order->total) - floatval($orderPaidAmount), 2));

        if ($remaining > 0) {
            Payment::create([
                'order_id' => $this->order->id,
                'payment_method' => 'due',
                'amount' => $remaining,
                'balance' => 0,
                'is_due' => true,
            ]);

            $this->order->update([
                'amount_paid' => $orderPaidAmount,
                'status' => 'payment_due',
            ]);
        } else {
            $this->order->update([
                'amount_paid' => $orderPaidAmount,
                'status' => 'paid',
            ]);
        }

        $this->order->refresh();
        $this->order->load('payments');
    }

    /**
     * Open discount modal for adding discount to billed / payment_due orders
     */
    public function showAddDiscount()
    {
        if (!$this->canModifyDiscount()) {
            return;
        }
        $this->discountType = $this->order->discount_type ?? 'fixed';
        $this->discountValue = null;
        $this->showDiscountModal = true;
    }

    /**
     * Save discount and recalculate all order totals
     */
    public function addDiscounts()
    {
        if (!$this->canModifyDiscount()) {
            return;
        }

        if (($this->order->loyalty_points_redeemed ?? 0) > 0) {
            $this->showAlert('error', __('loyalty::app.cannotAddDiscountWithLoyaltyPoints'));
            $this->showDiscountModal = false;
            return;
        }

        $this->validate([
            'discountValue' => 'required|numeric|min:0',
            'discountType'  => 'required|in:fixed,percent',
        ]);

        if ($this->discountType === 'percent' && $this->discountValue > 100) {
            $this->showAlert('error', __('messages.discountPercentError'));
            return;
        }

        $this->order->update([
            'discount_type'  => $this->discountType,
            'discount_value' => $this->discountValue,
        ]);

        $this->recalculateOrderTotals();
        $this->syncDuePaymentAmount();
        $this->showDiscountModal = false;
        $this->showAlert('success', __('messages.discountApplied'));
        $this->dispatch('refreshOrders');
        $this->dispatch('refreshPos');
        $this->dispatch('refreshPayments');
    }

    /**
     * Remove discount and recalculate all order totals
     */
    public function removeDiscount()
    {
        if (!$this->canModifyDiscount()) {
            return;
        }

        $this->order->update([
            'discount_type'   => null,
            'discount_value'  => null,
            'discount_amount' => null,
        ]);

        // Reset component properties to match Pos.php removeCurrentDiscount()
        $this->discountType  = null;
        $this->discountValue = null;
        $this->discountAmount = null;

        $this->recalculateOrderTotals();
        $this->syncDuePaymentAmount();
        $this->showAlert('success', __('messages.discountRemoved'));
        $this->dispatch('refreshOrders');
        $this->dispatch('refreshPos');
        $this->dispatch('refreshPayments');
    }

    /**
     * Recalculate order totals — mirrors the same step-by-step logic as POS calculateTotal().
     */
    public function recalculateOrderTotals()
    {
        if (!$this->order) {
            return;
        }

        $this->order->refresh();
        $this->order->load(['items', 'charges.charge', 'taxes.tax', 'payments', 'orderType']);

        // Step 1: Calculate SubTotal from items
        $this->subTotal = 0;
        foreach ($this->order->items as $item) {
            $this->subTotal += $item->amount;
        }

        // Step 2: Calculate discount amount (exactly like Pos.php)
        $discountAmount = 0;
        if (($this->order->loyalty_points_redeemed ?? 0) > 0) {
            // Loyalty discount takes priority
            $discountAmount = $this->order->loyalty_discount_amount ?? 0;
        } elseif ($this->order->discount_type === 'percent') {
            $discountAmount = round(($this->subTotal * $this->order->discount_value) / 100, 2);
        } elseif ($this->order->discount_type === 'fixed') {
            $discountAmount = min((float) $this->order->discount_value, $this->subTotal);
        }

        // Step 3: Calculate discountedTotal (net after discount)
        $discountedTotal = $this->subTotal - $discountAmount;

        // Step 4: Calculate service charges on discountedTotal
        $orderType = $this->order->orderType?->slug ?? $this->order->order_type;
        $charges = collect($this->order->charges ?? [])
            ->map(fn ($orderCharge) => $orderCharge->charge)
            ->filter(function ($charge) use ($orderType) {
                $allowedTypes = $charge->order_types ?? [];

                return empty($allowedTypes) || in_array($orderType, $allowedTypes);
            });

        $chargeTotals = RestaurantCharge::calculateTotals($charges, $discountedTotal);
        $serviceTotal = $chargeTotals['total'];

        // Step 5: Calculate tax base based on restaurant setting
        $includeChargesInTaxBase = restaurant()->include_charges_in_tax_base ?? true;
        $taxBase = RestaurantCharge::resolveTaxBase($discountedTotal, $charges, $includeChargesInTaxBase);
        $taxBase = max(0.0, (float) $taxBase);

        // Step 6: Calculate taxes (order-level or item-level)
        $totalTaxAmount = 0;
        if ($this->taxMode === 'order') {
            // Order-level tax: calculate on tax_base
            foreach ($this->order->taxes as $orderTax) {
                if ($orderTax->tax) {
                    $totalTaxAmount += ($orderTax->tax->tax_percent / 100) * $taxBase;
                }
            }
        } else {
            // Item-level tax: use stored tax amounts from items
            foreach ($this->order->items as $item) {
                $totalTaxAmount += ($item->tax_amount ?? 0);
            }
        }

        // Step 7: Calculate final total (exactly like Pos.php)
        $finalTotal = $discountedTotal + $serviceTotal;

        // Add taxes based on mode
        if ($this->taxMode === 'order') {
            $finalTotal += $totalTaxAmount;
        } else {
            // For item tax with inclusive mode, tax is already in subtotal
            $isInclusive = restaurant()->tax_inclusive ?? false;
            if (!$isInclusive) {
                $finalTotal += $totalTaxAmount;
            }
        }

        // Add tip and delivery fee
        $finalTotal += ($this->order->tip_amount ?? 0);
        if ($this->order->order_type === 'delivery' && !is_null($this->order->delivery_fee)) {
            $finalTotal += $this->order->delivery_fee;
        }

        $this->total = round($finalTotal, 2);

        // Step 8: Persist all calculated values to database
        $this->order->update([
            'sub_total'        => $this->subTotal,
            'total'            => $this->total,
            'discount_amount'  => $discountAmount,
            'total_tax_amount' => $totalTaxAmount,
            'tax_base'         => $taxBase,
        ]);

        // Refresh order to ensure component has latest values
        $this->order->refresh();
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
            $basePrice = isset($this->orderItemVariation[$key]) ? $this->orderItemVariation[$key]->price : $this->orderItemList[$key]->price;
            $modifierPrice = $this->orderItemModifiersPrice[$key] ?? 0;
            return $basePrice + $modifierPrice;
        }

        // For existing order items (when viewing order details), calculate from the order item itself
        if ($this->order && isset($this->order->items[$key])) {
            $orderItem = $this->order->items[$key];
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

    /**
     * Check if stamps are enabled for POS platform
     * This restricts redemption/earning when POS loyalty is disabled
     */
    private function isStampsEnabledForPOS(): bool
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

        try {
            if (module_enabled('Loyalty')) {
                $restaurantId = restaurant()->id ?? null;
                if ($restaurantId) {
                    $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
                    if (!$settings->enabled) {
                        return false;
                    }

                    $loyaltyType = $settings->loyalty_type ?? 'points';
                    $stampsEnabled = in_array($loyaltyType, ['stamps', 'both']) && ($settings->enable_stamps ?? true);

                    if (!$stampsEnabled) {
                        return false;
                    }

                    // Check if new platform field exists
                    $hasNewField = !is_null($settings->enable_stamps_for_pos);

                    if ($hasNewField) {
                        // Use loose comparison because DB returns 1/0, not true/false
                        return (bool) $settings->enable_stamps_for_pos;
                    } else {
                        // Fallback to old field
                        return (bool) ($settings->enable_for_pos ?? true);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }

        return false;
    }

    public function render()
    {
        return view('livewire.order.order-detail');
    }

}
