<?php

namespace App\Livewire\Order;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SplitOrder;
use App\Models\PredefinedAmount;
use App\Notifications\SendOrderBill;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\Log;
use App\Events\SendOrderBillEvent;
use App\Services\RestaurantAvailabilityService;
use App\Support\CustomerDisplayPayload;
use Illuminate\Support\Facades\Cache;

class AddPayment extends Component
{
    use LivewireAlert;

    public $order;
    public $showAddPaymentModal = false;
    public $paymentMethod = 'cash';
    public $paymentAmount = 0;
    public $returnAmount = 0;
    public $balanceAmount = 0;
    public $dueAmount = 0;
    public $paidAmount = 0;
    // Split payment properties
    public $showSplitOptions = false;
    public $splitType = null;
    public $numberOfSplits = 2;
    public $customSplits = [];
    public $splits = [];
    public $availableItems = [];
    public $activeSplitId = 1;
    public $totalExtraCharges = 0;
    public $tipPercentage;
    public $tipAmount;
    public $tipMode = 'percentage';
    public $tipNote = '';
    public $showTipModal = false;
    public $canAddTip;
    public $predefinedAmounts = [];
    // Payer information for split payments
    public $payerNames = [];

    #[On('showPaymentModal')]
    public function showPaymentModal($id)
    {
        // CRITICAL: Load order fresh from database to ensure we have latest total
        $this->order = Order::with([
            'items',
            'items.menuItem',
            'taxes',
            'payments',
            'splitOrders.items',
            'charges.charge'
        ])->find($id);

        $this->canAddTip = restaurant()->enable_tip_pos && $this->order->status !== 'paid';

        // Load predefined amounts
        $this->predefinedAmounts = restaurant()->predefinedAmounts()->pluck('amount')->toArray();

        // If no predefined amounts exist, use defaults
        if (empty($this->predefinedAmounts)) {
            $this->predefinedAmounts = [50, 100, 500, 1000];
        }

        // Include both regular discount and loyalty discount (using helper from trait)
        $regularDiscount = floatval($this->order->discount_amount ?? 0);
        $loyaltyDiscount = 0;
        $totalDiscount = $regularDiscount;

        if (function_exists('module_enabled') && module_enabled('Loyalty')) {
            try {
                if (module_enabled('Loyalty')) {
                    $totalDiscount = \Modules\Loyalty\Traits\HasLoyaltyIntegration::calculateTotalDiscount($this->order);
                    $loyaltyDiscount = \Modules\Loyalty\Traits\HasLoyaltyIntegration::getLoyaltyDiscountFromOrder($this->order);
                }
            } catch (\Exception $e) {
                // Silently fail if module is not properly loaded
                Log::debug('Loyalty module not available: ' . $e->getMessage());
            }
        }

        $subTotal = floatval($this->order->sub_total ?? 0);
        $discountedSubTotal = max(0, $subTotal - $totalDiscount);

        $charges = $this->order->charges;
        $extraCharges = $charges->map(function ($charge) use ($discountedSubTotal) {
            $chargeAmount = $charge->charge->charge_type == 'percent'
                ? ($charge->charge->charge_value / 100) * $discountedSubTotal
                : $charge->charge->charge_value;
            return [
                'name' => $charge->charge->charge_name,
                'amount' => $chargeAmount,
                'rate' => $charge->charge->charge_value,
                'type' => $charge->charge->charge_type,
            ];
        })->toArray();
        $this->totalExtraCharges = collect($extraCharges)->sum('amount');

        $this->paymentMethod = 'cash';
        $this->updateAmountDetails();
        $this->showAddPaymentModal = true;

        // Refresh available items data
        $this->refreshAvailableItems();

        $this->initializeSplits();
    }

    private function refreshAvailableItems()
    {
        // Include both regular discount and loyalty discount (using helper from trait)
        $regularDiscount = floatval($this->order->discount_amount ?? 0);
        $loyaltyDiscount = 0;
        $totalDiscount = $regularDiscount;

        if (function_exists('module_enabled') && module_enabled('Loyalty')) {
            try {
                if (module_enabled('Loyalty')) {
                    $totalDiscount = \Modules\Loyalty\Traits\HasLoyaltyIntegration::calculateTotalDiscount($this->order);
                    $loyaltyDiscount = \Modules\Loyalty\Traits\HasLoyaltyIntegration::getLoyaltyDiscountFromOrder($this->order);
                }
            } catch (\Exception $e) {
                // Silently fail if module is not properly loaded
                Log::debug('Loyalty module not available: ' . $e->getMessage());
            }
        }
        $totalTip = floatval($this->order->tip_amount ?? 0);
        $totalBaseAmount = $this->order->items->sum('amount');
        $totalQuantity = $this->order->items->sum('quantity');
        $taxMode = restaurant()->tax_mode ?? 'order';

        // Get already paid item quantities from split orders
        $paidItemQuantities = $this->getPaidItemQuantities();

        $this->availableItems = $this->order->items->map(function($orderItem) use ($totalDiscount, $totalTip, $totalBaseAmount, $totalQuantity, $taxMode, $paidItemQuantities) {
            $unitBasePrice = $orderItem->amount / $orderItem->quantity;

            $itemDiscount = $totalBaseAmount > 0 ? ($orderItem->amount / $totalBaseAmount) * $totalDiscount : 0;
            $itemTip = $totalBaseAmount > 0 ? ($orderItem->amount / $totalBaseAmount) * $totalTip : 0;
            $unitDiscount = $itemDiscount / $orderItem->quantity;
            $unitTip = $itemTip / $orderItem->quantity;
            $unitBasePriceAfterDiscount = $unitBasePrice - $unitDiscount;
            $itemExtraCharges = $this->totalExtraCharges / $totalQuantity;

            $itemTaxAmount = 0;

            // Handle tax calculation based on tax mode
            if ($taxMode === 'item') {
                // For item-level tax, use the tax amount stored in the order item
                $itemTaxAmount = floatval($orderItem->tax_amount ?? 0) / $orderItem->quantity;
            } else {
                // For order-level tax, calculate proportionally
                if ($this->order->total > 0) {
                    foreach ($this->order->taxes as $tax) {
                        if (!$tax->tax) {
                            continue;
                        }
                        $itemTaxAmount += (($tax->tax->tax_percent / 100) * $unitBasePriceAfterDiscount);
                    }
                }
            }

            $unitTotalPrice = $unitBasePriceAfterDiscount + $itemTaxAmount + $itemExtraCharges + $unitTip;

            // Calculate remaining quantity (total - already paid)
            $paidQuantity = $paidItemQuantities[$orderItem->id] ?? 0;
            $remainingQuantity = $orderItem->quantity - $paidQuantity;

            return [
                'id' => $orderItem->id,
                'name' => $orderItem->menuItem->item_name,
                'quantity' => $orderItem->quantity,
                'paid_quantity' => $paidQuantity,
                'remaining' => $remainingQuantity,
                'price' => $unitTotalPrice, // per unit price including taxes, charges, discount, tip
                'base_price' => $unitBasePrice, // per unit base price BEFORE discount (for display)
                'base_price_after_discount' => $unitBasePriceAfterDiscount, // per unit base price after discount (for calculation)
                'tax_amount' => $itemTaxAmount, // per unit tax based on tax mode
                'total' => $orderItem->total,
                'extra_charges' => $itemExtraCharges, // per unit extra charges
                'discount' => $unitDiscount, // per unit discount
                'tip' => $unitTip, // per unit tip
                'order_item_id' => $orderItem->id,
                'tax_mode' => $taxMode
            ];
        })->filter(function($item) {
            // Only show items that have remaining quantity to be paid
            return $item['remaining'] > 0;
        })->values()->toArray();
    }

    public function updateAmountDetails()
    {
        if ($this->order && $this->order->split_type === 'items') {
            // For split by items, calculate based on split orders
            $totalPaidAmount = $this->order->splitOrders()
                ->where('status', 'paid')
                ->sum('amount');

            $this->dueAmount = $this->order->total - $totalPaidAmount;
            $this->paidAmount = $totalPaidAmount;
        } else {
            // For regular payments and other split types
            $totalPaidAmount = $this->order->payments
                ->where('payment_method', '!=', 'due')
                ->sum('amount');

            $this->dueAmount = $this->order->total - $totalPaidAmount;
            $this->paidAmount = $totalPaidAmount;
        }

        if ($this->paymentMethod === 'due') {
            $this->paymentAmount = 0;
            $this->returnAmount = 0;
            $this->balanceAmount = max(0, $this->dueAmount);
        } else {
            $this->paymentAmount = max(0, $this->dueAmount);
            $this->returnAmount = 0;
            $this->balanceAmount = 0;
        }
    }

    public function getAmountOnDueProperty(): float
    {
        if (! $this->order) {
            return 0;
        }

        $receivedAmount = max(0, floatval($this->paymentAmount) - floatval($this->returnAmount));

        return $this->getBalanceAfterPayment($receivedAmount);
    }

    private function getPaidItemQuantities()
    {
        $paidQuantities = [];

        // Get all paid split orders for this order
        $paidSplitOrders = $this->order->splitOrders()
            ->where('status', 'paid')
            ->with('items')
            ->get();

        foreach ($paidSplitOrders as $splitOrder) {
            foreach ($splitOrder->items as $splitItem) {
                $orderItemId = $splitItem->order_item_id;
                $paidQuantities[$orderItemId] = ($paidQuantities[$orderItemId] ?? 0) + $splitItem->quantity;
            }
        }

        return $paidQuantities;
    }

    private function initializeSplits()
    {
        if ($this->splitType === 'items') {
            // Refresh available items first
            $this->refreshAvailableItems();

            // Check if there are unpaid items
            $hasUnpaidItems = collect($this->availableItems)->where('remaining', '>', 0)->count() > 0;

            if ($hasUnpaidItems) {
                $this->splits = [
                    1 => [
                        'id' => 1,
                        'items' => [],
                        'paymentMethod' => 'cash',
                        'amount' => 0,
                        'total' => 0
                    ]
                ];
                $this->activeSplitId = 1;
            } else {
                // No unpaid items, clear splits
                $this->splits = [];
                $this->activeSplitId = null;
            }
        } else {
            // For other split types
            $this->customSplits = [1, 2];

            foreach ($this->customSplits as $splitNumber) {
                $this->splits[$splitNumber] = [
                    'id' => $splitNumber,
                    'paymentMethod' => 'cash',
                    'amount' => 0,
                    'total' => 0  // Initialize total for consistency
                ];
            }

            if ($this->splitType === 'equal' || is_null($this->splitType)) {
                $splitAmount = $this->dueAmount / $this->numberOfSplits;
                foreach ($this->splits as $i => $split) {
                    if ($i > 0) {
                        $this->splits[$i]['amount'] = $splitAmount;
                    }
                }
            } elseif ($this->splitType === 'custom') {
                // For custom split, automatically split the unpaid amount
                $splitAmount = $this->dueAmount / count($this->customSplits);
                foreach ($this->customSplits as $splitNumber) {
                    $this->splits[$splitNumber]['amount'] = $splitAmount;
                }
            }
        }

        $this->updateBalanceAmount();
    }

    public function updatedPaymentAmount()
    {
        if ($this->paymentMethod === 'due') {
            $this->returnAmount = 0;
            $this->balanceAmount = max(0, floatval($this->dueAmount) - floatval($this->paymentAmount));

            return;
        }

        $paymentAmount = floatval($this->paymentAmount);

        if ($paymentAmount > $this->dueAmount) {
            // If payment is more than total, show return amount
            $this->returnAmount = $paymentAmount - $this->dueAmount;
            $this->balanceAmount = 0;
        } else {
            // If payment is less than total, show due amount
            $this->returnAmount = 0;
            $this->balanceAmount = $this->dueAmount - $paymentAmount;
        }
    }

    public function setPaymentMethod($method)
    {
        $this->paymentMethod = $method;

        if ($method === 'due') {
            // Nothing collected on a credit/due entry; full payable stays outstanding.
            $this->paymentAmount = 0;
            $this->returnAmount = 0;
            $this->balanceAmount = max(0, $this->dueAmount);
        } else {
            $this->updatedPaymentAmount();
        }
    }

    public function quickAmount($amount)
    {
        $this->paymentAmount = floatval($amount);
        $this->updatedPaymentAmount();
    }

    public function appendNumber($number)
    {
        $currentAmount = (string) $this->paymentAmount;

        // Handle decimal point input
        if ($number === '.') {
            if (str_contains($currentAmount, '.')) {
                return;
            }
            $this->paymentAmount = $currentAmount . $number;
            return;
        }

        $this->paymentAmount = $currentAmount === '0' ? $number : $currentAmount . $number;

        $this->paymentAmount = is_numeric($this->paymentAmount) ? (float) $this->paymentAmount : $this->paymentAmount;

        $this->updatedPaymentAmount();
    }


    public function clearAmount()
    {
        if ($this->paymentMethod === 'due') {
            $this->paymentAmount = 0;
            $this->returnAmount = 0;
            $this->balanceAmount = max(0, $this->dueAmount);

            return;
        }

        $this->paymentAmount = 0;
        $this->returnAmount = 0;
        $this->balanceAmount = $this->dueAmount;
    }

    public function updateSplitPayment($splitId, $method)
    {
        if ($this->splitType === 'items') {
            $split = collect($this->splits)->firstWhere('id', $splitId);
            $split['paymentMethod'] = $method;
        }
    }

    private function submissionIncludesDuePayment(): bool
    {
        if ($this->showSplitOptions && $this->splitType) {
            foreach ($this->splits as $split) {
                if (($split['paymentMethod'] ?? '') !== 'due') {
                    continue;
                }

                $amount = floatval($split['amount'] ?? 0);
                $total = floatval($split['total'] ?? 0);

                if ($amount > 0 || $total > 0 || ! empty($split['items'])) {
                    return true;
                }
            }

            return false;
        }

        return $this->paymentMethod === 'due';
    }

    private function getNonDuePaidAmount(): float
    {
        if ($this->order->split_type === 'items') {
            return floatval($this->order->splitOrders()->where('status', 'paid')->sum('amount'));
        }

        return floatval(Payment::where('order_id', $this->order->id)
            ->where('payment_method', '!=', 'due')
            ->sum('amount'));
    }

    private function getRemainingDueAmount(): float
    {
        return max(0, floatval($this->order->total) - $this->getNonDuePaidAmount());
    }

    private function getBalanceAfterPayment(float $receivedAmount): float
    {
        return max(0, round($this->getRemainingDueAmount() - max(0, $receivedAmount), 2));
    }

    private function syncDuePaymentRecord(): void
    {
        if (! $this->order || $this->order->split_type) {
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
        }
    }

    public function processSplitPayment()
    {
        if ($this->submissionIncludesDuePayment()) {
            Payment::where('order_id', $this->order->id)->where('payment_method', 'due')->delete();
        }

        // Check if order has existing due payments before processing any payments
        $hasDuePayments = Payment::where('order_id', $this->order->id)
            ->where('is_due', true)
            ->exists();

        switch ($this->splitType) {
        case 'equal':
            $this->order->split_type = 'even';
            $this->order->saveQuietly();

            foreach ($this->splits as $i => $split) {
                if ($i > 0 && !empty($split['amount'])) { // Skip index 0 and empty amounts
                    SplitOrder::create([
                        'order_id' => $this->order->id,
                        'amount' => $split['amount'],
                        'payment_method' => $split['paymentMethod'],
                        'status' => 'paid',
                        'payer_name' => $this->payerNames[$i] ?? null,
                    ]);

                    Payment::create([
                        'order_id' => $this->order->id,
                        'payment_method' => $split['paymentMethod'],
                        'amount' => $split['amount'],
                        'is_due' => $split['paymentMethod'] === 'due',
                        'due_amount_received' => ($hasDuePayments && $split['paymentMethod'] !== 'due') ? $split['amount'] : null
                    ]);
                }
            }
                break;

        case 'custom':
            $this->order->split_type = 'custom';
            $this->order->saveQuietly();

            foreach ($this->customSplits as $index => $split) {
                $lastIndex = $index === array_key_last($this->customSplits);
                if (!empty($this->splits[$split]['amount'])) {
                    SplitOrder::create([
                        'order_id' => $this->order->id,
                        'amount' => $this->splits[$split]['amount'],
                        'payment_method' => $this->splits[$split]['paymentMethod'],
                        'status' => 'paid',
                        'payer_name' => $this->payerNames[$split] ?? null,
                    ]);

                    Payment::create([
                        'order_id' => $this->order->id,
                        'payment_method' => $this->splits[$split]['paymentMethod'],
                        'amount' => $this->splits[$split]['amount'],
                        'balance' => $lastIndex && $this->returnAmount ? $this->returnAmount : 0,
                        'is_due' => $this->splits[$split]['paymentMethod'] === 'due',
                        'due_amount_received' => ($hasDuePayments && $this->splits[$split]['paymentMethod'] !== 'due') ? $this->splits[$split]['amount'] : null
                    ]);
                }
            }
                break;

        case 'items':
            $this->order->split_type = 'items';
            $this->order->saveQuietly();

            foreach ($this->splits as $split) {
                if (!empty($split['items'])) {

                    $splitTotal = round(collect($split['items'])->sum(function ($item) {
                        return floatval($item['price']) * intval($item['quantity']);
                    }), 2);

                    if ($splitTotal > 0) {
                        // Create split order record
                        $splitOrder = SplitOrder::create([
                            'order_id' => $this->order->id,
                            'amount' => $splitTotal,
                            'payment_method' => $split['paymentMethod'],
                            'status' => 'paid',
                            'payer_name' => $this->payerNames[$split['id']] ?? null,
                        ]);

                        // Create split payment record
                        Payment::create([
                            'order_id' => $this->order->id,
                            'payment_method' => $split['paymentMethod'],
                            'amount' => $splitTotal,
                            'is_due' => $split['paymentMethod'] === 'due',
                            'due_amount_received' => ($hasDuePayments && $split['paymentMethod'] !== 'due') ? $splitTotal : null
                        ]);

                        // Link items to split order with quantities
                        foreach ($split['items'] as $item) {
                            $splitOrder->items()->create([
                                'order_item_id' => $item['order_item_id'],
                                'quantity' => $item['quantity']
                            ]);
                        }
                    }
                }
            }

            // Refresh available items after processing payment
            $this->refreshItemsAfterPayment();
            break;
        }
    }

    public function submitForm(bool $shouldPrint = false)
    {
        $this->order = $this->order->fresh(['items', 'items.menuItem', 'taxes', 'payments', 'splitOrders.items']);
        if ($this->order->status === 'paid') {
            return;
        }

        $restaurant = $this->order->branch?->restaurant ?? restaurant();
        $availability = RestaurantAvailabilityService::getAvailability($restaurant, $this->order->branch);

        if (!($availability['is_open'] ?? true)) {
            $this->alert('error', RestaurantAvailabilityService::getMessage($availability, $restaurant), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        if ($this->showSplitOptions && $this->splitType) {
            // Validate split by items
            if ($this->splitType === 'items') {
                $hasItemsInSplits = false;
                foreach ($this->splits as $split) {
                    if (!empty($split['items']) && count($split['items']) > 0) {
                        $hasItemsInSplits = true;
                        break;
                    }
                }

                if (!$hasItemsInSplits) {
                    $this->alert('error', __('Please select items for payment'), ['toast' => true]);
                    return;
                }
            }

            $this->processSplitPayment();

        } else {
            if ($this->paymentAmount >= 0) {
                // Check if order has existing due payments
                $hasDuePayments = Payment::where('order_id', $this->order->id)
                    ->where('is_due', true)
                    ->exists();

                $receivedAmount = max(0, floatval($this->paymentAmount) - floatval($this->returnAmount));
                $paymentMethod = $this->paymentMethod;

                // Collecting money against an open due balance is always a real payment (cash/card).
                if ($paymentMethod === 'due' && $receivedAmount > 0) {
                    $paymentMethod = 'cash';
                }

                if ($paymentMethod !== 'due' && $receivedAmount > 0) {
                    $paymentAmount = $receivedAmount;

                    // STRIPE_TERMINAL_POS:BEGIN
                    if ($paymentMethod === 'stripe_terminal') {
                        if (! \Modules\StripeTerminal\Support\PosPaymentBridge::recordFullPayment($this, $paymentAmount, $hasDuePayments)) {
                            return;
                        }
                    } else {
                        // STRIPE_TERMINAL_POS:MID
                        Payment::create([
                            'order_id' => $this->order->id,
                            'payment_method' => $paymentMethod,
                            'amount' => $paymentAmount,
                            'balance' => $this->returnAmount,
                            'is_due' => false,
                            'due_amount_received' => $hasDuePayments ? $paymentAmount : null,
                        ]);
                    // STRIPE_TERMINAL_POS:END
                    }
                }
            }
        }

        // Refresh the order data to get latest payment information
        $this->order = $this->order->fresh(['items', 'items.menuItem', 'taxes', 'payments', 'splitOrders.items']);

        // Calculate total paid amount based on payment type
        if ($this->order->split_type === 'items') {
            $orderPaidAmount = $this->order->splitOrders()
                ->where('status', 'paid')
                ->sum('amount');
        } else {
            $orderPaidAmount = Payment::where('order_id', $this->order->id)
                ->where('payment_method', '!=', 'due')
                ->sum('amount');
        }

        $this->order->amount_paid = $orderPaidAmount;
        $wasPaid = $this->order->status === 'paid';
        $this->order->status = $orderPaidAmount >= $this->order->total ? 'paid' : 'payment_due';
        $this->order->save();

        // Fire event when order becomes paid (for loyalty points)
        if ($this->order->status === 'paid' && !$wasPaid) {
            \App\Events\SendNewOrderReceived::dispatch($this->order);
        }

        // Always rebuild the due row from order total minus collected (non-due) payments.
        $this->syncDuePaymentRecord();

        // Dine-in table + lock: released when order_status is completed (see OrderObserver).

        if ($this->order->customer_id) {
            try {
                SendOrderBillEvent::dispatch($this->order);
            } catch (\Exception $e) {
                Log::error('Error sending notification: ' . $e->getMessage());
            }
        }

        $shouldDispatchPrint = $shouldPrint && $this->order->status === 'paid';

        $this->resetCustomerDisplayCache();

        $this->dispatch('showOrderDetail', id: $this->order->id);
        $this->dispatch('refreshOrders');
        $this->dispatch('resetPos');
        $this->dispatch('refreshPayments');
        $this->showAddPaymentModal = false;

        if ($shouldDispatchPrint) {
            // Let POS JS use its existing printOrder() flow (same behavior as Pos.php / ajax print pipeline).
            $this->dispatch('posPaymentCompletedPrint', id: $this->order->id);
        }
    }

    public function submitFormAndPrint()
    {
        $this->submitForm(true);
    }

    private function resetCustomerDisplayCache(): void
    {
        $userId = auth()->id();
        if (! $userId) {
            return;
        }

        $customerDisplayData = CustomerDisplayPayload::normalize([
            'order_number' => null,
            'formatted_order_number' => null,
            'items' => [],
            'sub_total' => 0,
            'discount' => 0,
            'total' => 0,
            'taxes' => [],
            'extra_charges' => [],
            'tip' => 0,
            'delivery_fee' => 0,
            'order_type' => null,
            'status' => 'idle',
            'cash_due' => null,
            'qr_code_image_url' => null,
        ]);

        $cacheKey = 'customer_display_cart_user_' . $userId;
        Cache::put($cacheKey, $customerDisplayData, now()->addMinutes(30));

        if (pusherSettings()->is_enabled_pusher_broadcast) {
            broadcast(new \App\Events\CustomerDisplayUpdated($customerDisplayData, $userId));
        }
    }

    public function render()
    {
        return view('livewire.order.add-payment');
    }

    public function updateSplitPaymentMethod($splitId, $method)
    {
        if (isset($this->splits[$splitId])) {
            $this->splits[$splitId]['paymentMethod'] = $method;
            $this->splits = $this->splits; // Trigger Livewire update
        }
    }

    public function updateBalanceAmount()
    {
        if ($this->splitType === 'custom') {
            $totalSplitAmount = collect($this->customSplits)->sum(fn($split) => floatval($this->splits[$split]['amount'] ?? 0));
            $this->balanceAmount = max(0, $this->dueAmount - $totalSplitAmount);
            $this->returnAmount = max(0, $totalSplitAmount - $this->dueAmount);
        } elseif ($this->splitType === 'equal') {
            $totalSplitAmount = collect($this->splits)->sum('amount');
            $this->balanceAmount = max(0, $this->dueAmount - $totalSplitAmount);
            $this->returnAmount = max(0, $totalSplitAmount - $this->dueAmount);
        } elseif ($this->splitType === 'items') {
            $totalSplitAmount = collect($this->splits)->sum('total');
            $this->balanceAmount = max(0, $this->dueAmount - $totalSplitAmount);
            $this->returnAmount = 0; // No return amount for item splits
        }
    }

    public function addItemToSplit($itemId, $splitId, $quantity = 1)
    {
        $itemIndex = collect($this->availableItems)->search(fn($i) => $i['id'] === $itemId);
        if ($itemIndex === false) return;

        $item = $this->availableItems[$itemIndex];
        $quantity = max(1, min($quantity, $item['remaining']));

        if ($quantity < 1 || $item['remaining'] < 1) return;

        if (!isset($this->splits[$splitId]['items'])) {
            $this->splits[$splitId]['items'] = [];
        }

        $existingItemIndex = collect($this->splits[$splitId]['items'])->search(fn($i) => $i['order_item_id'] === $item['order_item_id']);

        if ($existingItemIndex !== false) {
            // Update existing item in split
            $currentQtyInSplit = $this->splits[$splitId]['items'][$existingItemIndex]['quantity'];
            $maxAdditional = $item['remaining']; // remaining quantity available

            if ($maxAdditional >= $quantity) {
                $this->splits[$splitId]['items'][$existingItemIndex]['quantity'] += $quantity;
                $this->splits[$splitId]['items'][$existingItemIndex]['total'] = $this->splits[$splitId]['items'][$existingItemIndex]['quantity'] * $this->splits[$splitId]['items'][$existingItemIndex]['price'];
                $this->availableItems[$itemIndex]['remaining'] -= $quantity;
            }
        } else {
            // Add new item to split
            $splitItem = [
                'id' => $item['id'],
                'order_item_id' => $item['order_item_id'],
                'name' => $item['name'],
                'quantity' => $quantity,
                'price' => floatval($item['price']),
                'base_price' => floatval($item['base_price']),
                'extra_charges' => floatval($item['extra_charges']),
                'tax_amount' => floatval($item['tax_amount']),
                'discount' => floatval($item['discount']),
                'tip' => floatval($item['tip']),
                'total' => floatval($item['price']) * $quantity,
                'tax_mode' => $item['tax_mode'] ?? 'order'
            ];
            $this->splits[$splitId]['items'][] = $splitItem;
            $this->availableItems[$itemIndex]['remaining'] -= $quantity;
        }

        $this->calculateSplitTotals();
    }

    // Increment quantity of item in split (if available)
    public function incrementItemInSplit($splitId, $itemIndex)
    {
        if (!isset($this->splits[$splitId]['items'][$itemIndex])) return;
        $item = $this->splits[$splitId]['items'][$itemIndex];
        $availableIndex = collect($this->availableItems)->search(fn($i) => $i['id'] === $item['id']);
        if ($availableIndex !== false && $this->availableItems[$availableIndex]['remaining'] > 0) {
            $this->splits[$splitId]['items'][$itemIndex]['quantity']++;
            $this->splits[$splitId]['items'][$itemIndex]['total'] = $this->splits[$splitId]['items'][$itemIndex]['quantity'] * $this->splits[$splitId]['items'][$itemIndex]['price'];
            $this->availableItems[$availableIndex]['remaining']--;
            $this->calculateSplitTotals();
        }
    }

    // Decrement quantity of item in split (if > 1), or remove if quantity becomes 0
    public function decrementItemInSplit($splitId, $itemIndex)
    {
        if (!isset($this->splits[$splitId]['items'][$itemIndex])) return;
        $item = $this->splits[$splitId]['items'][$itemIndex];
        $availableIndex = collect($this->availableItems)->search(fn($i) => $i['id'] === $item['id']);
        if ($this->splits[$splitId]['items'][$itemIndex]['quantity'] > 1) {
            $this->splits[$splitId]['items'][$itemIndex]['quantity']--;
            $this->splits[$splitId]['items'][$itemIndex]['total'] = $this->splits[$splitId]['items'][$itemIndex]['quantity'] * $this->splits[$splitId]['items'][$itemIndex]['price'];
            if ($availableIndex !== false) {
                $this->availableItems[$availableIndex]['remaining']++;
            }
        } else {
            $this->removeItemFromSplit($splitId, $itemIndex);
            return;
        }
        $this->calculateSplitTotals();
    }

    // Update removeItemFromSplit to return all quantity to availableItems
    public function removeItemFromSplit($splitId, $itemIndex)
    {
        if (isset($this->splits[$splitId]['items'][$itemIndex])) {
            $item = $this->splits[$splitId]['items'][$itemIndex];
            $availableIndex = collect($this->availableItems)->search(fn($i) => $i['id'] === $item['id']);
            if ($availableIndex !== false) {
                $this->availableItems[$availableIndex]['remaining'] += $item['quantity'];
            }
            unset($this->splits[$splitId]['items'][$itemIndex]);
            $this->splits[$splitId]['items'] = array_values($this->splits[$splitId]['items']);
            $this->calculateSplitTotals();
        }
    }

    public function calculateSplitTotals()
    {
        foreach ($this->splits as &$split) {
            if (isset($split['items'])) {
                $split['total'] = collect($split['items'])->sum(function($item) {
                    return floatval($item['price']) * intval($item['quantity']);
                });

                // Calculate tax total separately for display purposes
                $split['tax_total'] = collect($split['items'])->sum(function($item) {
                    return floatval($item['tax_amount'] ?? 0) * intval($item['quantity']);
                });
            } else {
                // Ensure all splits have a numeric total, even if 0
                if (!isset($split['total'])) {
                    $split['total'] = 0;
                }
            }
        }

        // Update balance amount for items split
        if ($this->splitType === 'items') {
            $this->updateBalanceAmount();
        }
    }

    public function updatedSplits($value, $key)
    {
        // Update balance calculations whenever splits change
        $this->updateBalanceAmount();
    }

    public function addNewSplit()
    {
        $this->numberOfSplits++;

        // Initialize the new split with default values
        $this->splits[$this->numberOfSplits] = [
            'id' => $this->numberOfSplits,
            'paymentMethod' => 'cash',
            'amount' => $this->dueAmount / $this->numberOfSplits
        ];

        // Recalculate all split amounts evenly
        $splitAmount = $this->dueAmount / $this->numberOfSplits;
        foreach ($this->splits as $i => $split) {
            if ($i > 0) { // Skip index 0
                $this->splits[$i]['amount'] = $splitAmount;
            }
        }

        $this->updateBalanceAmount();
    }

    public function removeSplit($index)
    {
        if ($this->numberOfSplits > 2) {
            $this->numberOfSplits--;
            unset($this->splits[$index]);

            unset($this->payerNames[$index]);

            // Reindex the splits array
            $this->splits = array_values($this->splits);

            // Recalculate all split amounts evenly
            $splitAmount = $this->dueAmount / $this->numberOfSplits;
            foreach ($this->splits as $i => $split) {
                if ($i > 0) { // Skip index 0
                    $this->splits[$i]['amount'] = $splitAmount;
                }
            }

            $this->updateBalanceAmount();
        }
    }


    public function addNewCustomSplit()
    {
        $nextSplitNumber = max($this->customSplits) + 1;
        $this->customSplits[] = $nextSplitNumber;

        // Initialize the new split with default values
        $this->splits[$nextSplitNumber] = [
            'id' => $nextSplitNumber,
            'paymentMethod' => 'cash',
            'amount' => 0,
            'total' => 0  // Initialize total for consistency
        ];
        $this->updateBalanceAmount();
    }

    public function removeCustomSplit($splitNumber)
    {
        if (count($this->customSplits) > 2) {
            // Remove from customSplits array
            $this->customSplits = array_values(array_filter($this->customSplits, function($split) use ($splitNumber) {
                return $split !== $splitNumber;
            }));

            // Remove from splits array
            unset($this->splits[$splitNumber]);

            // Clean up orphaned payer name
            unset($this->payerNames[$splitNumber]);

            $this->updateBalanceAmount();
        }
    }

    public function addNewItemSplit()
    {
        $nextSplitId = max(array_keys($this->splits)) + 1;

        // Add new split with empty items array and its own payment method
        $this->splits[$nextSplitId] = [
            'id' => $nextSplitId,
            'items' => [],
            'paymentMethod' => 'cash',  // Each new split gets its own payment method
            'amount' => 0,
            'total' => 0
        ];

        $this->activeSplitId = $nextSplitId;
    }

    public function removeItemSplit($splitId)
    {
        if (count($this->splits) > 1) { // Keep at least one split
            // Return items to available pool
            if (isset($this->splits[$splitId]['items'])) {
                foreach ($this->splits[$splitId]['items'] as $item) {
                    $availableIndex = collect($this->availableItems)->search(fn($i) => $i['id'] === $item['id']);
                    if ($availableIndex !== false) {
                        $this->availableItems[$availableIndex]['remaining'] += $item['quantity'];
                    }
                }
            }

            // Remove the split
            unset($this->splits[$splitId]);

            // Clean up orphaned payer name
            unset($this->payerNames[$splitId]);

            // Reset active split to first split if removed split was active
            if ($this->activeSplitId === $splitId) {
                $this->activeSplitId = array_key_first($this->splits);
            }

            // Recalculate totals
            $this->calculateSplitTotals();
        }
    }

    public function addTipModal()
    {
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

        $order = Order::find($this->order->id);

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
        $this->updatedPaymentAmount();
        $this->updateAmountDetails();
    }

    public function setTip($mode, $value)
    {
        if ($mode === 'percentage') {
            $this->tipPercentage = $value;
            $this->tipAmount = ($value / 100) * $this->order->total;
        } else {
            $this->tipAmount = floatval($value);
        }
        $this->tipAmount = number_format($this->tipAmount, 2);
    }

    public function toggleTipMode()
    {
        $this->tipMode = $this->tipMode === 'percentage' ? 'amount' : 'percentage';
    }

    // Method to refresh available items after split payments
    public function refreshItemsAfterPayment()
    {
        if ($this->order && $this->order->split_type === 'items') {
            $this->refreshAvailableItems();
            $this->updateAmountDetails();
            $this->initializeSplits();
        }
    }

    public function updatedSplitType()
    {
        if ($this->splitType) {
            $this->payerNames = [];
            $this->initializeSplits();
        }
    }

    public function showSplitOptions($show = true)
    {
        $this->showSplitOptions = $show;
        if ($show) {
            $this->splitType = null; // Reset split type when showing options
        } else {
            $this->splitType = null;
            $this->splits = [];
            $this->payerNames = []; // Clear payer names when closing split options
        }
    }

    // Handle when split amounts change via wire:model
    public function updatedSplitsAmount()
    {
        $this->updateBalanceAmount();
    }
}
