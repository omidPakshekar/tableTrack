<?php

namespace App\Services;

use App\Models\RestaurantCharge;
use App\Models\Tax;
use Illuminate\Support\Collection;

/**
 * Shared tax/total math so web POS and Waiter API stay aligned.
 */
class OrderTaxCalculator
{
    /**
     * @param  iterable<int, mixed>  $taxes  Tax models or arrays with tax_percent
     * @return array{total_tax_amount: float, tax_amounts: array<int, float>}
     */
    public static function calculateOrderLevelTaxes(float $taxBase, iterable $taxes, bool $isInclusive): array
    {
        $taxes = collect($taxes)->filter();
        $totalTaxPercent = $taxes->sum(function ($tax) {
            return (float) (is_array($tax) ? ($tax['tax_percent'] ?? 0) : ($tax->tax_percent ?? 0));
        });

        $taxAmounts = [];
        $totalTaxAmount = 0.0;

        foreach ($taxes as $index => $tax) {
            $taxPercent = (float) (is_array($tax) ? ($tax['tax_percent'] ?? 0) : ($tax->tax_percent ?? 0));
            if ($isInclusive) {
                $taxAmount = $totalTaxPercent > 0
                    ? (($taxBase * $taxPercent) / (100 + $totalTaxPercent))
                    : 0.0;
            } else {
                $taxAmount = ($taxPercent / 100) * $taxBase;
            }

            $taxAmounts[$index] = $taxAmount;
            $totalTaxAmount += $taxAmount;
        }

        return [
            'total_tax_amount' => $totalTaxAmount,
            'tax_amounts' => $taxAmounts,
        ];
    }

    /**
     * Resolve branch taxes (same source Livewire / Ajax POS use).
     */
    public static function restaurantTaxes(): Collection
    {
        return Tax::all();
    }

    /**
     * Build totals from a gross item subtotal (menu prices as entered).
     *
     * Inclusive (matches web POS JS): tax/charges are extracted for display and
     * are NOT added on top of menu prices. Exclusive: tax and charges add on top.
     *
     * @param  iterable<int, mixed>  $charges  RestaurantCharge models or amount arrays
     * @param  iterable<int, mixed>|null  $taxes
     * @return array{
     *     sub_total: float,
     *     discount_amount: float,
     *     discounted_total: float,
     *     service_total: float,
     *     tax_base: float,
     *     total_tax_amount: float,
     *     total: float
     * }
     */
    public static function totalsFromSubTotal(
        float $subTotal,
        ?string $discountType,
        float $discountValue,
        iterable $charges,
        float $tipAmount,
        float $deliveryFee,
        bool $isInclusive,
        bool $includeChargesInTaxBase = true,
        ?iterable $taxes = null
    ): array {
        $taxes = collect($taxes ?? static::restaurantTaxes());

        $discountAmount = 0.0;
        if ($discountType === 'percent' && $discountValue > 0) {
            $discountAmount = round(($subTotal * $discountValue) / 100, 2);
        } elseif ($discountType === 'fixed' && $discountValue > 0) {
            $discountAmount = min($discountValue, $subTotal);
        }

        $discountedTotal = max(0.0, $subTotal - $discountAmount);
        $chargeList = collect($charges)->filter();

        if ($isInclusive) {
            $taxPercentTotal = (float) $taxes->sum(function ($tax) {
                return (float) (is_array($tax) ? ($tax['tax_percent'] ?? 0) : ($tax->tax_percent ?? 0));
            });

            $reversed = RestaurantCharge::reverseInclusiveBreakdown(
                $discountedTotal,
                $chargeList,
                $taxPercentTotal,
                $includeChargesInTaxBase
            );

            return [
                'sub_total' => round((float) $reversed['sub_total'], 3),
                'discount_amount' => $discountAmount,
                'discounted_total' => $discountedTotal,
                'service_total' => (float) $reversed['service_total'],
                'tax_base' => round((float) $reversed['tax_base'], 3),
                'total_tax_amount' => round((float) $reversed['total_tax_amount'], 3),
                // Inclusive menu prices already embed tax; do not add tax/charges again.
                'total' => max(0.0, round($discountedTotal + $tipAmount + $deliveryFee, 2)),
            ];
        }

        $chargeTotals = RestaurantCharge::calculateTotals($chargeList, $discountedTotal);
        $serviceTotal = (float) ($chargeTotals['total'] ?? 0);
        $taxBase = RestaurantCharge::resolveTaxBase($discountedTotal, $chargeList, $includeChargesInTaxBase);
        $taxResult = static::calculateOrderLevelTaxes($taxBase, $taxes, false);
        $totalTaxAmount = $taxResult['total_tax_amount'];

        $total = $discountedTotal + $serviceTotal + $totalTaxAmount + $tipAmount + $deliveryFee;

        return [
            'sub_total' => $subTotal,
            'discount_amount' => $discountAmount,
            'discounted_total' => $discountedTotal,
            'service_total' => $serviceTotal,
            'tax_base' => $taxBase,
            'total_tax_amount' => $totalTaxAmount,
            'total' => max(0.0, round($total, 2)),
        ];
    }
}
