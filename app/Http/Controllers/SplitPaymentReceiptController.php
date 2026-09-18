<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\RestaurantTax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SplitPaymentReceiptController extends Controller
{
    /**
     * Print split payment receipts for an order
     * Supports both splitOrders relationship (new) and payments table (legacy)
     *
     * @param int $orderId
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function printSplitReceipts($orderId, Request $request)
    {
        // Load order with all necessary relationships
        $order = Order::with([
            'items.menuItem',
            'items.menuItemVariation',
            'items.modifierOptions',
            'splitOrders.items.orderItem.menuItem',
            'splitOrders.items.orderItem.menuItemVariation',
            'splitOrders.items.orderItem.modifierOptions',
            'splitOrders.order', // For accessing parent order in splits
            'payments',
            'taxes',
            'charges.charge',
            'kot', // For token number
            'table', // For table info
            'waiter', // For waiter name
            'customer' // For customer details
        ])->findOrFail($orderId);

        // Get configuration
        $width = $request->get('width', 80);
        $thermal = $request->get('thermal', true);
        $splitType = $order->split_type ?? 'equal';
        $specificSplitId = $request->get('splitId'); // Optional: filter to specific split
        $includeSummary = $request->get('includeSummary', false); // New: include summary receipt

        // Get restaurant settings once
        $restaurant = restaurant();

        if (!$restaurant) {
            abort(500, 'Restaurant configuration not found');
        }

        $receiptSettings = $restaurant->receiptSetting;

        if (!$receiptSettings) {
            abort(500, 'Receipt settings not configured');
        }

        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $taxMode = $order->tax_mode ?? ($restaurant->tax_mode ?? 'order');

        // Determine which system to use and build receipts
        $paidSplitOrdersQuery = $order->splitOrders()->where('status', 'paid');

        // Filter to specific split if requested
        if ($specificSplitId) {
            $paidSplitOrdersQuery->where('id', $specificSplitId);
        }

        $paidSplitOrders = $paidSplitOrdersQuery->get();

        if ($paidSplitOrders->isNotEmpty()) {
            // Use new splitOrders relationship
            $splitReceipts = $this->buildSplitOrderReceipts($order, $paidSplitOrders);
        } else {
            // Fallback to legacy payments table
            $payments = $order->payments()->where('payment_method', '!=', 'due')->get();

            if ($payments->isEmpty()) {
                abort(404, 'No payments found for this order');
            }

            $splitReceipts = $this->buildLegacyPaymentReceipts($order, $payments);
        }

        if (empty($splitReceipts)) {
            abort(404, 'No split receipts to print');
        }

        // Validate totals (optional - can be removed in production)
        $this->validateSplitTotals($order, $splitReceipts);

        return view('order.print-split', compact(
            'order',
            'splitReceipts',
            'receiptSettings',
            'taxDetails',
            'taxMode',
            'splitType',
            'width',
            'thermal',
            'includeSummary'
        ));
    }

    /**
     * Validate that split receipts sum up to order total
     * Helps catch calculation errors
     */
    private function validateSplitTotals(Order $order, array $splitReceipts): void
    {
        $totalFromSplits = 0;
        $subtotalFromSplits = 0;
        $taxFromSplits = 0;
        $chargesFromSplits = 0;
        $discountFromSplits = 0;
        $loyaltyDiscountFromSplits = 0;
        $stampDiscountFromSplits = 0;
        $tipFromSplits = 0;
        $deliveryFeeFromSplits = 0;

        foreach ($splitReceipts as $split) {
            $totalFromSplits += $split['allocated_amounts']['total'];
            $subtotalFromSplits += $split['allocated_amounts']['subtotal'];
            $taxFromSplits += $split['allocated_amounts']['tax_amount'];
            $chargesFromSplits += $split['allocated_amounts']['charges_amount'];
            $discountFromSplits += $split['allocated_amounts']['discount_amount'];
            $loyaltyDiscountFromSplits += $split['allocated_amounts']['loyalty_discount_amount'] ?? 0;
            $stampDiscountFromSplits += $split['allocated_amounts']['stamp_discount_amount'] ?? 0;
            $tipFromSplits += $split['allocated_amounts']['tip_amount'];
            $deliveryFeeFromSplits += $split['allocated_amounts']['delivery_fee'];
        }

        $totalDifference = abs($order->total - $totalFromSplits);
        $subtotalDifference = abs($order->sub_total - $subtotalFromSplits);
        $taxDifference = abs(($order->total_tax_amount ?? 0) - $taxFromSplits);
        $loyaltyDiscountDifference = abs(($order->loyalty_discount_amount ?? 0) - $loyaltyDiscountFromSplits);
        $stampDiscountDifference = abs(($order->stamp_discount_amount ?? 0) - $stampDiscountFromSplits);

        // Allow small rounding differences
        $tolerance = count($splitReceipts) * 0.05;

        if ($totalDifference > $tolerance) {
            Log::error("Split totals don't match order total", [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'splits_total' => $totalFromSplits,
                'difference' => $totalDifference,
                'tolerance' => $tolerance,
                'splits_count' => count($splitReceipts)
            ]);
        }

        if ($subtotalDifference > $tolerance) {
            Log::error("Split subtotals don't match order subtotal", [
                'order_id' => $order->id,
                'order_subtotal' => $order->sub_total,
                'splits_subtotal' => $subtotalFromSplits,
                'difference' => $subtotalDifference
            ]);
        }

        if ($taxDifference > $tolerance) {
            Log::error("Split taxes don't match order tax", [
                'order_id' => $order->id,
                'order_tax' => $order->total_tax_amount,
                'splits_tax' => $taxFromSplits,
                'difference' => $taxDifference
            ]);
        }

        if ($loyaltyDiscountDifference > $tolerance) {
            Log::error("Split loyalty discounts don't match order loyalty discount", [
                'order_id' => $order->id,
                'order_loyalty_discount' => $order->loyalty_discount_amount,
                'splits_loyalty_discount' => $loyaltyDiscountFromSplits,
                'difference' => $loyaltyDiscountDifference
            ]);
        }

        if ($stampDiscountDifference > $tolerance) {
            Log::error("Split stamp discounts don't match order stamp discount", [
                'order_id' => $order->id,
                'order_stamp_discount' => $order->stamp_discount_amount,
                'splits_stamp_discount' => $stampDiscountFromSplits,
                'difference' => $stampDiscountDifference
            ]);
        }

        // Log success when validation passes
        if ($totalDifference <= $tolerance && $subtotalDifference <= $tolerance && $taxDifference <= $tolerance) {
            Log::info("Split payment validation passed", [
                'order_id' => $order->id,
                'splits_count' => count($splitReceipts),
                'order_total' => $order->total,
                'splits_total' => $totalFromSplits,
                'difference' => $totalDifference
            ]);
        }
    }

    /**
     * Build receipts from new SplitOrder relationship
     * This is the preferred method for new implementations
     */
    private function buildSplitOrderReceipts(Order $order, $paidSplitOrders): array
    {
        $splitReceipts = [];

        // Get ACTUAL total number of paid splits (not filtered count)
        $actualTotalSplits = $order->splitOrders()->where('status', 'paid')->count();
        $splitType = $order->split_type;

        foreach ($paidSplitOrders as $index => $splitOrder) {
            // Ensure split order has required data
            if (!$splitOrder->amount || $splitOrder->amount <= 0) {
                Log::warning("Skipping split order with invalid amount", [
                    'split_order_id' => $splitOrder->id,
                    'amount' => $splitOrder->amount
                ]);
                continue;
            }

            // Find the actual split number based on all paid splits, not just filtered ones
            $actualSplitNumber = $order->splitOrders()
                ->where('status', 'paid')
                ->where('id', '<=', $splitOrder->id)
                ->count();

            // Create payment object for view compatibility
            $payment = (object)[
                'id' => $splitOrder->id,
                'amount' => $splitOrder->amount,
                'payment_method' => $splitOrder->payment_method ?? 'cash',
                'created_at' => $splitOrder->created_at,
            ];

            $splitData = [
                'payment' => $payment,
                'split_number' => $actualSplitNumber,
                'total_splits' => $actualTotalSplits,
                'payment_ratio' => ($order->total > 0 && $splitOrder->amount > 0) ? ($splitOrder->amount / $order->total) : 0,
                'payer_name' => $splitOrder->payer_name ?? "Guest {$actualSplitNumber}",
                'split_type' => $splitType,
            ];

            // Generate receipt data based on split type - following React example methodology
            if ($splitType === 'items') {
                // Item-based: Show ONLY items assigned to this specific guest
                $splitData['items'] = $this->getSplitOrderItems($splitOrder);
                $splitData['allocated_amounts'] = $this->calculateSplitOrderAmounts($splitOrder);
            } else {
                // Equal/Custom: Show ALL items with proportional allocation
                $splitData['items'] = $this->getProportionalItems($order, $splitData['payment_ratio']);
                $splitData['allocated_amounts'] = $this->calculateProportionalAmounts($order, $splitData['payment_ratio']);
            }

            // Debug: Log if items are empty
            if (empty($splitData['items'])) {
                Log::warning("Split {$actualSplitNumber} has no items", [
                    'split_id' => $splitOrder->id,
                    'split_type' => $splitType,
                    'split_order_items_count' => $splitOrder->items->count(),
                    'order_items_count' => $order->items->count()
                ]);
            }

            $splitReceipts[] = $splitData;
        }

        return $splitReceipts;
    }

    /**
     * Build receipts from legacy payments table
     * Fallback for older orders
     */
    private function buildLegacyPaymentReceipts(Order $order, $payments): array
    {
        $splitReceipts = [];
        $totalSplits = $payments->count();
        $splitType = $order->split_type ?? 'equal';

        foreach ($payments as $index => $payment) {
            if ($payment->amount <= 0) {
                continue;
            }

            $splitNumber = $payment->split_number ?? ($index + 1);
            $paymentRatio = ($order->total > 0 && $payment->amount > 0) ? ($payment->amount / $order->total) : 0;

            $splitData = [
                'payment' => $payment,
                'split_number' => $splitNumber,
                'total_splits' => $totalSplits,
                'payment_ratio' => $paymentRatio,
                'payer_name' => $payment->payer_name ?? "Guest {$splitNumber}",
                'split_type' => $splitType,
            ];

            // Legacy payments always use proportional allocation
            // (item-based data is not stored in old payment records)
            $splitData['items'] = $this->getProportionalItems($order, $paymentRatio);
            $splitData['allocated_amounts'] = $this->calculateProportionalAmounts($order, $paymentRatio);

            $splitReceipts[] = $splitData;
        }

        return $splitReceipts;
    }

    /**
     * Get ONLY items assigned to this specific split order
     * Used for item-based splits with new splitOrders relationship
     *
     * Following React example: Show only the items this guest actually ordered
     */
    private function getSplitOrderItems($splitOrder): array
    {
        $items = [];

        // Ensure items are loaded with all necessary relationships
        if (!$splitOrder->relationLoaded('items')) {
            $splitOrder->load([
                'items.orderItem.menuItem',
                'items.orderItem.menuItemVariation',
                'items.orderItem.modifierOptions.modifier'
            ]);
        }

        foreach ($splitOrder->items as $splitItem) {
            $orderItem = $splitItem->orderItem;

            // Skip if no order item or zero quantity
            if (!$orderItem || $splitItem->quantity <= 0) {
                continue;
            }

            // Calculate the allocated amount for this split quantity
            // price is the per-unit price including modifiers
            $unitPrice = $orderItem->price ?? ($orderItem->amount / max($orderItem->quantity, 1));
            $allocatedAmount = round($splitItem->quantity * $unitPrice, 2);

            $items[] = [
                'item' => $orderItem,
                'allocated_quantity' => $splitItem->quantity,
                'allocated_amount' => $allocatedAmount,
                'allocated_price' => $unitPrice,
                'tax_amount' => $splitItem->tax_amount ?? 0,
                'tax_breakup' => $splitItem->tax_breakup ?? $orderItem->tax_breakup,
            ];
        }

        return $items;
    }

    /**
     * Calculate amounts from split order (item-based)
     * Uses the stored split order amount and calculates component breakdown
     */
    private function calculateSplitOrderAmounts($splitOrder): array
    {
        $order = $splitOrder->order;

        // Step 1: Calculate subtotal from split order items
        $subtotal = 0;
        $itemTaxTotal = 0;
        $itemTaxBreakdown = [];

        if (!$splitOrder->relationLoaded('items')) {
            $splitOrder->load([
                'items.orderItem.menuItem',
                'items.orderItem.menuItemVariation',
                'items.orderItem.modifierOptions'
            ]);
        }

        foreach ($splitOrder->items as $splitItem) {
            $orderItem = $splitItem->orderItem;
            if (!$orderItem || $splitItem->quantity <= 0) {
                continue;
            }

            // Calculate item subtotal
            $unitPrice = $orderItem->price ?? ($orderItem->amount / max($orderItem->quantity, 1));
            $itemSubtotal = round($splitItem->quantity * $unitPrice, 2);
            $subtotal += $itemSubtotal;

            // Calculate item-level tax if tax_mode is 'item'
            $taxMode = $order->tax_mode ?? 'order';
            if ($taxMode === 'item' && isset($orderItem->tax_amount) && $orderItem->tax_amount > 0) {
                if ($orderItem->quantity > 0) {
                    $itemTaxAmount = ($orderItem->tax_amount / $orderItem->quantity) * $splitItem->quantity;
                    $itemTaxTotal += $itemTaxAmount;

                    if (isset($orderItem->tax_breakup)) {
                        $taxBreakup = is_string($orderItem->tax_breakup)
                            ? json_decode($orderItem->tax_breakup, true)
                            : $orderItem->tax_breakup;

                        if (is_array($taxBreakup)) {
                            foreach ($taxBreakup as $taxName => $taxInfo) {
                                $taxPercent = $taxInfo['percent'] ?? 0;
                                $taxItemAmount = $taxInfo['amount'] ?? 0;
                                $allocatedTaxAmount = ($taxItemAmount / $orderItem->quantity) * $splitItem->quantity;

                                if (!isset($itemTaxBreakdown[$taxName])) {
                                    $itemTaxBreakdown[$taxName] = [
                                        'percent' => $taxPercent,
                                        'amount' => 0
                                    ];
                                }
                                $itemTaxBreakdown[$taxName]['amount'] += $allocatedTaxAmount;
                            }
                        }
                    }
                }
            }
        }

        $subtotal = round($subtotal, 2);

        // Step 2: Calculate payment ratio for proportional allocation of order-level amounts
        $paymentRatio = ($order->sub_total > 0) ? ($subtotal / $order->sub_total) : 0;

        // Step 3: Proportionally allocate discounts
        $discountAmount = round(($order->discount_amount ?? 0) * $paymentRatio, 2);
        $loyaltyDiscountAmount = round(($order->loyalty_discount_amount ?? 0) * $paymentRatio, 2);
        $stampDiscountAmount = round(($order->stamp_discount_amount ?? 0) * $paymentRatio, 2);
        $subtotalAfterDiscount = $subtotal - $discountAmount - $loyaltyDiscountAmount - $stampDiscountAmount;

        // Step 4: Proportionally allocate tip and delivery fee
        $tipAmount = round(($order->tip_amount ?? 0) * $paymentRatio, 2);
        $deliveryFee = round(($order->delivery_fee ?? 0) * $paymentRatio, 2);

        // Step 5: Calculate charges proportionally
        $chargesAmount = 0;
        $chargesBreakdown = [];

        if ($order->charges && $order->charges->count() > 0) {
            $orderBaseForCharges = $order->sub_total - ($order->discount_amount ?? 0) - ($order->loyalty_discount_amount ?? 0) - ($order->stamp_discount_amount ?? 0);

            foreach ($order->charges as $chargeItem) {
                $orderChargeAmount = $chargeItem->charge->getAmount($orderBaseForCharges);
                $allocatedChargeAmount = round($orderChargeAmount * $paymentRatio, 2);

                if ($allocatedChargeAmount > 0) {
                    $chargesAmount += $allocatedChargeAmount;
                    $chargesBreakdown[] = [
                        'charge' => $chargeItem,
                        'amount' => $allocatedChargeAmount,
                    ];
                }
            }
        }

        // Step 6: Calculate tax respecting the tax calculation base setting
        $taxMode = $order->tax_mode ?? 'order';
        $taxAmount = 0;
        $taxBreakdown = [];

        if ($taxMode === 'item') {
            // Item-level tax: use proportionally allocated tax from items
            $taxAmount = round($itemTaxTotal, 2);
            foreach ($itemTaxBreakdown as $taxName => $taxInfo) {
                $taxBreakdown[] = [
                    'name' => $taxName,
                    'percent' => $taxInfo['percent'],
                    'amount' => round($taxInfo['amount'], 2),
                ];
            }
        } else {
            // Order-level tax: Calculate based on split's tax base
            // Respect the "Include service charges in tax calculation" setting
            $restaurant = restaurant();
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;

            // Tax base = (subtotal - discounts) + charges (if setting enabled)
            $splitTaxBase = $subtotalAfterDiscount;
            if ($includeChargesInTaxBase) {
                $splitTaxBase += $chargesAmount;
            }

            if ($order->taxes && $order->taxes->count() > 0) {
                foreach ($order->taxes as $taxItem) {
                    if ($taxItem->tax && $taxItem->tax->tax_percent > 0) {
                        $taxItemAmount = round(($taxItem->tax->tax_percent / 100) * $splitTaxBase, 2);
                        $taxAmount += $taxItemAmount;
                        $taxBreakdown[] = [
                            'name' => $taxItem->tax->tax_name,
                            'percent' => $taxItem->tax->tax_percent,
                            'amount' => $taxItemAmount,
                        ];
                    }
                }
            }
        }

        // Step 7: Calculate final total
        $calculatedTotal = $subtotal - $discountAmount - $loyaltyDiscountAmount - $stampDiscountAmount + $taxAmount + $chargesAmount + $tipAmount + $deliveryFee;
        $calculatedTotal = round($calculatedTotal, 2);

        // Use the stored split order amount as the authoritative total
        $storedTotal = $splitOrder->amount ?? 0;

        // Use the stored split order amount as the authoritative total
        $storedTotal = $splitOrder->amount ?? 0;

        // Log if there's a significant difference for debugging
        $difference = abs($calculatedTotal - $storedTotal);
        if ($difference > 0.10) {
            Log::info("Split order amount difference", [
                'split_order_id' => $splitOrder->id,
                'stored_total' => $storedTotal,
                'calculated_total' => $calculatedTotal,
                'difference' => $difference,
                'subtotal' => $subtotal,
                'charges' => $chargesAmount,
                'tax' => $taxAmount,
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'loyalty_discount_amount' => $loyaltyDiscountAmount,
            'stamp_discount_amount' => $stampDiscountAmount,
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
            'tax_amount' => $taxAmount,
            'tax_breakdown' => $taxBreakdown,
            'charges_amount' => $chargesAmount,
            'charges_breakdown' => $chargesBreakdown,
            'tip_amount' => $tipAmount,
            'delivery_fee' => $deliveryFee,
            'total' => $storedTotal, // Use stored amount - this is what was actually paid
        ];
    }

    /**
     * Get ALL items with proportional allocation
     * Used for equal/custom splits
     *
     * Following React example: items.map(item => ({ ...item, qty: item.qty * ratio, amount: item.amount * ratio }))
     */
    private function getProportionalItems(Order $order, float $paymentRatio): array
    {
        $items = [];

        if (!$order->items || $order->items->count() === 0) {
            Log::warning("Order has no items", ['order_id' => $order->id]);
            return $items;
        }

        foreach ($order->items as $item) {
            // Validate item has required data
            if (!$item->quantity || $item->quantity <= 0) {
                Log::warning("Skipping item with zero quantity", [
                    'order_id' => $order->id,
                    'item_id' => $item->id
                ]);
                continue;
            }

            // Calculate proportional quantity and amount
            $proportionalQty = $item->quantity * $paymentRatio;
            $allocatedAmount = round($item->amount * $paymentRatio, 2);

            // Only include items with non-zero allocation
            // Small amounts (< 0.01) get rounded to 0 and excluded
            if ($allocatedAmount > 0) {
                $items[] = [
                    'item' => $item,
                    'allocated_quantity' => round($proportionalQty, 2),
                    'allocated_amount' => $allocatedAmount,
                    'allocated_price' => $item->price,
                    'tax_amount' => round(($item->tax_amount ?? 0) * $paymentRatio, 2),
                    'tax_breakup' => $item->tax_breakup,
                ];
            }
        }

        return $items;
    }

    /**
     * Calculate proportional amounts for equal/custom splits
     * Following React example: All order components multiplied by payment ratio
     */
    private function calculateProportionalAmounts(Order $order, float $paymentRatio): array
    {
        // Validate payment ratio
        if ($paymentRatio < 0 || $paymentRatio > 1) {
            Log::warning("Invalid payment ratio", [
                'order_id' => $order->id,
                'payment_ratio' => $paymentRatio
            ]);
            $paymentRatio = max(0, min(1, $paymentRatio)); // Clamp between 0 and 1
        }

        $subtotal = round($order->sub_total * $paymentRatio, 2);
        $discountAmount = round(($order->discount_amount ?? 0) * $paymentRatio, 2);
        $loyaltyDiscountAmount = round(($order->loyalty_discount_amount ?? 0) * $paymentRatio, 2);
        $stampDiscountAmount = round(($order->stamp_discount_amount ?? 0) * $paymentRatio, 2);
        $subtotalAfterDiscount = $subtotal - $discountAmount - $loyaltyDiscountAmount - $stampDiscountAmount;
        $tipAmount = round(($order->tip_amount ?? 0) * $paymentRatio, 2);
        $deliveryFee = round(($order->delivery_fee ?? 0) * $paymentRatio, 2);

        // Calculate charges proportionally
        $chargesAmount = 0;
        $chargesBreakdown = [];

        if ($order->charges && $order->charges->count() > 0) {
            // Base for charges should exclude all discounts (regular + loyalty + stamp)
            $baseForCharges = $order->sub_total - ($order->discount_amount ?? 0) - ($order->loyalty_discount_amount ?? 0) - ($order->stamp_discount_amount ?? 0);

            foreach ($order->charges as $charge) {
                try {
                    $originalAmount = $charge->charge->getAmount($baseForCharges);
                    $chargeAmount = round($originalAmount * $paymentRatio, 2);

                    // Only add non-zero charges
                    if ($chargeAmount > 0) {
                        $chargesAmount += $chargeAmount;

                        $chargesBreakdown[] = [
                            'charge' => $charge,
                            'amount' => $chargeAmount,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error("Error calculating charge", [
                        'order_id' => $order->id,
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Calculate tax with breakdown
        $taxAmount = 0;
        $taxBreakdown = [];
        $taxMode = $order->tax_mode ?? 'order';

        if ($taxMode === 'order' && $order->taxes && $order->taxes->count() > 0) {
            // For order-level tax, calculate each tax on the split's tax base
            $restaurant = restaurant();
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;

            $splitTaxBase = $subtotalAfterDiscount;
            if ($includeChargesInTaxBase) {
                $splitTaxBase += $chargesAmount;
            }

            foreach ($order->taxes as $taxItem) {
                if ($taxItem->tax && $taxItem->tax->tax_percent > 0) {
                    $taxItemAmount = round(($taxItem->tax->tax_percent / 100) * $splitTaxBase, 2);
                    $taxAmount += $taxItemAmount;
                    $taxBreakdown[] = [
                        'name' => $taxItem->tax->tax_name,
                        'percent' => $taxItem->tax->tax_percent,
                        'amount' => $taxItemAmount,
                    ];
                }
            }
        } else {
            // For item-level tax, aggregate breakdown from order items proportionally
            $taxAmount = round(($order->total_tax_amount ?? 0) * $paymentRatio, 2);

            // Aggregate tax breakdown from all order items
            $itemTaxBreakdown = [];
            if ($order->items && $order->items->count() > 0) {
                foreach ($order->items as $orderItem) {
                    if (isset($orderItem->tax_amount) && $orderItem->tax_amount > 0 && isset($orderItem->tax_breakup)) {
                        $taxBreakup = is_string($orderItem->tax_breakup)
                            ? json_decode($orderItem->tax_breakup, true)
                            : $orderItem->tax_breakup;

                        if (is_array($taxBreakup)) {
                            foreach ($taxBreakup as $taxName => $taxInfo) {
                                $taxPercent = $taxInfo['percent'] ?? 0;
                                $taxItemAmount = $taxInfo['amount'] ?? 0;

                                // Proportionally allocate this tax component
                                $allocatedTaxAmount = $taxItemAmount * $paymentRatio;

                                if (!isset($itemTaxBreakdown[$taxName])) {
                                    $itemTaxBreakdown[$taxName] = [
                                        'percent' => $taxPercent,
                                        'amount' => 0
                                    ];
                                }
                                $itemTaxBreakdown[$taxName]['amount'] += $allocatedTaxAmount;
                            }
                        }
                    }
                }
            }

            // Convert aggregated breakdown to array format
            foreach ($itemTaxBreakdown as $taxName => $taxInfo) {
                $taxBreakdown[] = [
                    'name' => $taxName,
                    'percent' => $taxInfo['percent'],
                    'amount' => round($taxInfo['amount'], 2),
                ];
            }
        }

        // Calculate final total
        $total = $subtotal - $discountAmount - $loyaltyDiscountAmount - $stampDiscountAmount + $taxAmount + $chargesAmount + $tipAmount + $deliveryFee;

        // Round to 2 decimals to avoid floating point precision issues
        $total = round($total, 2);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'loyalty_discount_amount' => $loyaltyDiscountAmount,
            'stamp_discount_amount' => $stampDiscountAmount,
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
            'tax_amount' => $taxAmount,
            'tax_breakdown' => $taxBreakdown,
            'charges_amount' => $chargesAmount,
            'charges_breakdown' => $chargesBreakdown,
            'tip_amount' => $tipAmount,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
        ];
    }
}
