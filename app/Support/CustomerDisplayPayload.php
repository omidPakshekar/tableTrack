<?php

namespace App\Support;

use App\Models\Restaurant;

class CustomerDisplayPayload
{
    /**
     * Ensure empty carts never keep stale tax/charge rows on the customer display.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $items = $data['items'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $items = array_values(array_filter($items, static function ($item) {
            return is_array($item) && $item !== [];
        }));

        $subTotal = (float) ($data['sub_total'] ?? 0);
        $total = (float) ($data['total'] ?? 0);
        $cashDue = (float) ($data['cash_due'] ?? 0);
        $status = $data['status'] ?? 'idle';
        $isBilledPaymentScreen = $status === 'billed' || $cashDue > 0;

        if ($isBilledPaymentScreen) {
            $data['items'] = [];
            $data['taxes'] = [];
            $data['extra_charges'] = [];
        } elseif (count($items) === 0 || ($subTotal <= 0 && $total <= 0)) {
            $data['items'] = [];
            $data['sub_total'] = 0;
            $data['discount'] = 0;
            $data['total'] = 0;
            $data['taxes'] = [];
            $data['extra_charges'] = [];
            $data['tip'] = 0;
            $data['delivery_fee'] = 0;
            $data['cash_due'] = null;
        }

        $data['qr_code_image_url'] = self::paymentQrCodeImageUrl($data);

        return $data;
    }

    private static function paymentQrCodeImageUrl(array $data): ?string
    {
        $status = $data['status'] ?? 'idle';
        $cashDue = (float) ($data['cash_due'] ?? 0);
        $isBilledPaymentScreen = $status === 'billed' || $cashDue > 0;

        if (! $isBilledPaymentScreen) {
            return null;
        }

        $restaurant = self::freshRestaurant();
        if (! $restaurant) {
            return null;
        }

        if (! (bool) ($restaurant->show_payment_qr_on_customer_display ?? true)) {
            return null;
        }

        $cachedUrl = $data['qr_code_image_url'] ?? null;
        if (is_string($cachedUrl) && $cachedUrl !== '') {
            return $cachedUrl;
        }

        return $restaurant->customerDisplayQrCodeImageUrl();
    }

    private static function freshRestaurant(): ?Restaurant
    {
        $restaurant = restaurant();
        if (! is_object($restaurant) || empty($restaurant->id)) {
            return null;
        }

        return Restaurant::with('paymentGateways')->find($restaurant->id);
    }
}
