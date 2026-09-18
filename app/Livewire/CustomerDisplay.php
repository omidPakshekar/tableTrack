<?php

namespace App\Livewire;

use App\Support\CustomerDisplayPayload;
use Livewire\Component;
use Illuminate\Support\Facades\Cache;

class CustomerDisplay extends Component
{
    public $orderItems = [];
    public $subTotal = 0;
    public $total = 0;
    public $discount = 0;
    public $orderNumber = null;
    public $taxes = [];
    public $extraCharges = [];
    public $tip = 0;
    public $deliveryFee = 0;
    public $orderType = null;
    public $status = 'idle';
    public $cashDue = null;
    public $qrCodeImageUrl = null;
    public $formattedOrderNumber = null;
    public $refreshKey = 0; // Used to force component refresh

    public function render()
    {
        $this->loadFromCache();

        return view('livewire.customer-display');
    }

    public function refreshCustomerDisplay($orderData = null)
    {
        if (is_array($orderData) && isset($orderData['order_data']) && is_array($orderData['order_data'])) {
            $orderData = $orderData['order_data'];
        }

        if (is_array($orderData) && $orderData !== []) {
            $this->applyCart(CustomerDisplayPayload::normalize($orderData));
        } else {
            $this->loadFromCache();
        }

        $this->refreshKey++;
    }

    private function loadFromCache(): void
    {
        $userId = auth()->id();
        $cacheKey = 'customer_display_cart_user_' . $userId;
        $cart = Cache::get($cacheKey);

        $this->applyCart($cart ? CustomerDisplayPayload::normalize($cart) : null);
    }

    private function applyCart(?array $cart): void
    {
        if ($cart) {
            $this->orderNumber = $cart['order_number'] ?? null;
            $this->formattedOrderNumber = $cart['formatted_order_number'] ?? null;
            $this->subTotal = $cart['sub_total'] ?? 0;
            $this->total = $cart['total'] ?? 0;
            $this->discount = $cart['discount'] ?? 0;
            $this->orderItems = $cart['items'] ?? [];
            $this->taxes = $cart['taxes'] ?? [];
            $this->extraCharges = $cart['extra_charges'] ?? [];
            $this->tip = $cart['tip'] ?? 0;
            $this->deliveryFee = $cart['delivery_fee'] ?? 0;
            $this->orderType = $cart['order_type'] ?? null;
            $this->status = $cart['status'] ?? 'idle';
            $this->cashDue = $cart['cash_due'] ?? null;
            $this->qrCodeImageUrl = $cart['qr_code_image_url'] ?? null;

            return;
        }

        $this->orderNumber = null;
        $this->formattedOrderNumber = null;
        $this->subTotal = 0;
        $this->total = 0;
        $this->discount = 0;
        $this->orderItems = [];
        $this->taxes = [];
        $this->extraCharges = [];
        $this->tip = 0;
        $this->deliveryFee = 0;
        $this->orderType = null;
        $this->status = 'idle';
        $this->cashDue = null;
        $this->qrCodeImageUrl = null;
    }
}
