<?php

namespace App\Models;

use App\Traits\HasRestaurant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;

class RestaurantCharge extends BaseModel
{
    use HasFactory;
    use HasRestaurant;

    protected $guarded = ['id'];

    protected $casts = [
        'order_types' => 'array',
        'is_taxable' => 'boolean',
    ];

    public function getAmount($amount)
    {
        return $this->charge_type === 'percent'
            ? ($amount * $this->charge_value) / 100
            : $this->charge_value;
    }

    /**
     * Calculate total and taxable charge amounts for a collection of charges.
     */
    public static function calculateTotals(iterable $charges, float $baseAmount): array
    {
        $total = 0;
        $taxableTotal = 0;

        foreach ($charges as $charge) {
            if (is_array($charge)) {
                $amount = (float) ($charge['amount'] ?? 0);
                $isTaxable = $charge['is_taxable'] ?? true;
            } elseif (is_object($charge) && method_exists($charge, 'getAmount')) {
                $amount = $charge->getAmount($baseAmount);
                $isTaxable = $charge->is_taxable ?? true;
            } else {
                continue;
            }

            $total += $amount;

            if ($isTaxable) {
                $taxableTotal += $amount;
            }
        }

        return [
            'total' => $total,
            'taxable_total' => $taxableTotal,
        ];
    }

    /**
     * Resolve tax base from discounted amount and applicable charges.
     */
    public static function resolveTaxBase(float $discountedAmount, iterable $charges, bool $includeChargesInTaxBase): float
    {
        if (!$includeChargesInTaxBase) {
            return $discountedAmount;
        }

        $totals = static::calculateTotals($charges, $discountedAmount);

        return $discountedAmount + $totals['taxable_total'];
    }

    /**
     * Reverse-solve inclusive breakdown: total = subtotal + service charges + GST.
     * GST tax base = subtotal + taxable service charges (when includeChargesInTaxBase).
     */
    public static function reverseInclusiveBreakdown(
        float $inclusiveTotal,
        iterable $charges,
        float $taxPercentTotal,
        bool $includeChargesInTaxBase = true
    ): array {
        $f = 0.0;
        $fTaxable = 0.0;
        $fixedTotal = 0.0;
        $fixedTaxable = 0.0;
        $chargeList = collect($charges);

        foreach ($chargeList as $charge) {
            if (is_array($charge)) {
                $type = $charge['charge_type'] ?? 'fixed';
                $value = (float) ($charge['charge_value'] ?? $charge['amount'] ?? 0);
                $isTaxable = $charge['is_taxable'] ?? true;
            } else {
                $type = $charge->charge_type ?? 'fixed';
                $value = (float) ($charge->charge_value ?? 0);
                $isTaxable = $charge->is_taxable ?? true;
            }

            if ($type === 'percent') {
                $f += $value / 100;
                if ($includeChargesInTaxBase && $isTaxable) {
                    $fTaxable += $value / 100;
                }
            } else {
                $fixedTotal += $value;
                if ($includeChargesInTaxBase && $isTaxable) {
                    $fixedTaxable += $value;
                }
            }
        }

        $t = $taxPercentTotal / 100;
        $chargeAmountById = [];

        if ($chargeList->isEmpty()) {
            $divisor = 1 + $t;
            $x = $divisor > 0 ? $inclusiveTotal / $divisor : $inclusiveTotal;

            return [
                'sub_total' => $x,
                'service_total' => 0.0,
                'tax_base' => $x,
                'total_tax_amount' => $x * $t,
                'charge_amounts' => [],
            ];
        }

        if ($includeChargesInTaxBase) {
            $xFactor = 1 + $f + $t * (1 + $fTaxable);
            $constant = $fixedTotal + $t * $fixedTaxable;
            $x = $xFactor > 0 ? max(0, ($inclusiveTotal - $constant) / $xFactor) : 0.0;
            $taxBase = $x + ($x * $fTaxable) + $fixedTaxable;
        } else {
            $xFactor = 1 + $f + $t;
            $constant = $fixedTotal;
            $x = $xFactor > 0 ? max(0, ($inclusiveTotal - $constant) / $xFactor) : 0.0;
            $taxBase = $x;
        }

        $serviceTotal = 0.0;
        foreach ($chargeList as $charge) {
            if (is_array($charge)) {
                $id = $charge['id'] ?? null;
                $type = $charge['charge_type'] ?? 'fixed';
                $value = (float) ($charge['charge_value'] ?? $charge['amount'] ?? 0);
            } else {
                $id = $charge->id ?? null;
                $type = $charge->charge_type ?? 'fixed';
                $value = (float) ($charge->charge_value ?? 0);
            }

            $amount = $type === 'percent' ? ($value / 100) * $x : $value;
            $serviceTotal += $amount;
            if ($id !== null) {
                $chargeAmountById[$id] = $amount;
            }
        }

        return [
            'sub_total' => $x,
            'service_total' => $serviceTotal,
            'tax_base' => $taxBase,
            'total_tax_amount' => $taxBase * $t,
            'charge_amounts' => $chargeAmountById,
        ];
    }
}
