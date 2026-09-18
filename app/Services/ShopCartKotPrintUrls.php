<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;

/**
 * Shop KOT printing after redirect payments, plus when to print relative to customer-site
 * "Auto-confirm orders" (before vs after payment).
 */
class ShopCartKotPrintUrls
{
    /**
     * @return array{before: bool, after: bool, tie: bool}
     */
    public static function shopAutoConfirmFlags(Order $order, ?Restaurant $restaurantFallback = null): array
    {
        $before = (bool) ($order->getAttribute('auto_confirm_orders_before_payment')
            ?? $restaurantFallback?->auto_confirm_orders_before_payment
            ?? false);
        $after = (bool) ($order->getAttribute('auto_confirm_orders_after_payment')
            ?? $restaurantFallback?->auto_confirm_orders_after_payment
            ?? false);

        return [
            'before' => $before,
            'after' => $after,
            'tie' => $before || $after,
        ];
    }

    /**
     * After online payment (Razorpay, redirect gateways, deferred success page).
     * Auto-confirm off: print when payment completes. Auto-confirm on + before payment: already printed at confirmation.
     * Auto-confirm on + after payment: print when payment completes.
     */
    public static function shouldPrintKotAfterShopOnlinePayment(Order $order, ?Restaurant $restaurantFallback = null): bool
    {
        $f = self::shopAutoConfirmFlags($order, $restaurantFallback);

        if (!$f['tie']) {
            return true;
        }

        return $f['after'];
    }

    /**
     * Staff marks payment received (e.g. QR / offline). Same rule as online payment completion.
     */
    public static function shouldPrintKotOnShopPaymentVerified(Order $order, ?Restaurant $restaurantFallback = null): bool
    {
        return self::shouldPrintKotAfterShopOnlinePayment($order, $restaurantFallback);
    }

    /**
     * After redirect-based payment the customer lands on order success without the cart Livewire
     * component. Flash the order id so DeferredKotPrint can run printKot.
     */
    public static function flashDeferredKotPrintForShopOrder(?Order $order): void
    {
        if (!$order || $order->placed_via !== 'shop') {
            return;
        }

        $order->loadMissing('branch.restaurant');
        if (!self::shouldPrintKotAfterShopOnlinePayment($order, $order->branch?->restaurant)) {
            return;
        }

        session()->flash('shop_print_kot_order_id', $order->id);
    }
}
