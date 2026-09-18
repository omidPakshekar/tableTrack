<?php

namespace App\Livewire\Forms;

use Livewire\Component;
use App\Models\Refund;
use App\Models\RefundReason;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class ProcessRefund extends Component
{
    use LivewireAlert;

    public $payment;
    public $refundReasons;
    public $refundReasonId;
    public $refundType = 'full';
    public $partialRefundType = null;
    public $amount;
    public $notes;

    public function mount($payment)
    {
        $this->payment = $payment;
        $this->refundReasons = RefundReason::where('branch_id', branch()->id)->get();
        
        // Set default amount to full payment amount
        $this->amount = $this->payment->amount;
    }

    public function updatedRefundType()
    {
        if ($this->refundType === 'full') {
            $this->amount = $this->payment->amount;
            $this->partialRefundType = null;
        } elseif ($this->refundType === 'waste') {
            $this->amount = $this->payment->amount; // Show full amount but it's not editable
            $this->partialRefundType = null;
        } elseif ($this->refundType === 'partial') {
            // Default to half the price
            $this->partialRefundType = 'half';
            $this->amount = $this->payment->amount / 2; // Default amount to half for display
        }
    }

    public function updatedPartialRefundType()
    {
        if ($this->refundType === 'partial') {
            if ($this->partialRefundType === 'half') {
                $this->amount = $this->payment->amount / 2;
            } elseif ($this->partialRefundType === 'fixed') {
                // Keep current amount for fixed
            } elseif ($this->partialRefundType === 'custom') {
                // Keep current amount for custom
            }
        }
    }

    public function submitForm()
    {
        $this->validate([
            'refundReasonId' => 'required|exists:refund_reasons,id',
            'refundType' => 'required|in:full,partial,waste',
            'amount' => 'required|numeric|min:0|max:' . $this->payment->amount,
            'notes' => 'nullable|string|max:1000',
        ], [
            'refundReasonId.required' => 'Please select a refund reason.',
            'refundReasonId.exists' => 'Selected refund reason is invalid.',
            'refundType.required' => 'Please select a refund type.',
            'refundType.in' => 'Invalid refund type selected.',
            'partialRefundType.required_if' => 'Please select a partial refund type.',
            'partialRefundType.in' => 'Invalid partial refund type selected.',
            'amount.required' => 'Refund amount is required.',
            'amount.numeric' => 'Refund amount must be a number.',
            'amount.min' => 'Refund amount cannot be negative.',
            'amount.max' => 'Refund amount cannot exceed the payment amount.',
        ]);

        // For waste type, amount should equal payment amount (but it's written off, not refunded)
        if ($this->refundType === 'waste' && $this->amount != $this->payment->amount) {
            $this->addError('amount', 'Waste refund amount must equal the payment amount.');
            return;
        }

        // For full refund, amount should equal payment amount
        if ($this->refundType === 'full' && $this->amount != $this->payment->amount) {
            $this->addError('amount', 'Full refund amount must equal the payment amount.');
            return;
        }

        // For partial refund with half type, amount should be exactly half
        if ($this->refundType === 'partial' && $this->partialRefundType === 'half') {
            $halfAmount = $this->payment->amount / 2;
            if (abs($this->amount - $halfAmount) > 0.01) {
                $this->addError('amount', 'Half refund amount must be exactly half of the payment amount.');
                return;
            }
        }

        if (!branch()) {
            $this->addError('refundReasonId', 'No branch found. Please select a branch.');
            return;
        }

        try {
            // Load order with deliveryPlatform and items if not already loaded
            if (!$this->payment->relationLoaded('order')) {
                $this->payment->load('order.deliveryPlatform', 'order.items');
            } elseif (!$this->payment->order->relationLoaded('items')) {
                $this->payment->order->load('items');
            }
            
            // Calculate commission adjustment if delivery app is involved
            // Sum commission from all order items
            $commissionAdjustment = null;
            $deliveryAppId = null;
            
            if ($this->payment->order && $this->payment->order->delivery_app_id && $this->payment->order->deliveryPlatform) {
                $platform = $this->payment->order->deliveryPlatform;
                $deliveryAppId = $this->payment->order->delivery_app_id;
                
                // Calculate total commission from all order items
                $totalCommissionFromItems = 0;
                
                if ($this->payment->order->items && $this->payment->order->items->count() > 0) {
                    foreach ($this->payment->order->items as $orderItem) {
                        // Calculate commission portion from each order item's amount
                        if ($platform->commission_type === 'percent' && $platform->commission_value > 0) {
                            // For percentage: commission = item_amount * (commission_rate / (100 + commission_rate))
                            // This extracts the commission markup portion from the item amount
                            $itemCommission = $orderItem->amount * ($platform->commission_value / (100 + $platform->commission_value));
                            $totalCommissionFromItems += $itemCommission;
                        } elseif ($platform->commission_type === 'fixed' && $platform->commission_value > 0) {
                            // For fixed commission, calculate proportionally per item
                            // This is a simplified approach - you may need to adjust based on your business logic
                            $totalOrderItemsAmount = $this->payment->order->items->sum('amount');
                            if ($totalOrderItemsAmount > 0) {
                                $itemRatio = $orderItem->amount / $totalOrderItemsAmount;
                                $itemCommission = $platform->commission_value * $itemRatio;
                                $totalCommissionFromItems += $itemCommission;
                            }
                        }
                    }
                }
                
                $commissionAdjustment = $totalCommissionFromItems;
                
            }

            $refund = new Refund();
            $refund->branch_id = branch()->id;
            $refund->payment_id = $this->payment->id;
            $refund->order_id = $this->payment->order_id;
            $refund->delivery_app_id = $deliveryAppId;
            $refund->refund_reason_id = $this->refundReasonId;
            $refund->refund_type = $this->refundType;
            $refund->partial_refund_type = $this->refundType === 'partial' ? $this->partialRefundType : null;
            // Store the actual amount for all refund types (waste shows full amount but it's a write-off)
            $refund->amount = $this->amount;
            $refund->commission_adjustment = $commissionAdjustment;
            $refund->notes = $this->notes;
            $refund->status = 'processed';
            $refund->processed_by = user()->id;
            $refund->processed_at = now();
            $refund->save();

            $this->dispatch('refundProcessed');
            $this->alert('success', __('messages.refundProcessed'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            // Reset form
            $this->reset(['refundReasonId', 'refundType', 'partialRefundType', 'amount', 'notes']);
            $this->amount = $this->payment->amount;
            $this->refundType = 'full';
            $this->partialRefundType = null;
        } catch (\Exception $e) {
            $this->alert('error', __('messages.refundFailed') . ': ' . $e->getMessage(), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    public function render()
    {
        return view('livewire.forms.process-refund');
    }
}

