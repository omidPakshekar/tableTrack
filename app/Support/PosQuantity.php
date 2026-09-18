<?php

namespace App\Support;

class PosQuantity
{
    public const PRECISION = 3;

    public const MIN = 0.001;

    /**
     * Normalize a POS / order line quantity (supports fractional kg for weight sales).
     */
    public static function normalize(mixed $quantity, float $min = self::MIN): float
    {
        $qty = (float) $quantity;

        if (! is_finite($qty) || $qty < $min) {
            return $min;
        }

        return round($qty, self::PRECISION);
    }

    /**
     * Convert grams to kilograms for storage / pricing.
     */
    public static function gramsToKg(float $grams): float
    {
        return self::normalize($grams / 1000, self::MIN);
    }

    /**
     * Convert kilograms to grams for display / input.
     */
    public static function kgToGrams(float $kg): float
    {
        return round($kg * 1000, self::PRECISION);
    }

    /**
     * Human-readable qty for POS / bills (e.g. "100 g", "2 kg", or plain "3").
     */
    public static function formatDisplay(mixed $quantity, bool $sellByWeight = false): string
    {
        $qty = (float) $quantity;

        if (! is_finite($qty) || $qty <= 0) {
            return $sellByWeight ? '0 g' : '0';
        }

        if (! $sellByWeight) {
            if (abs($qty - round($qty)) < 0.0005) {
                return (string) (int) round($qty);
            }

            return self::trimTrailingZeros(number_format($qty, self::PRECISION, '.', ''));
        }

        if ($qty < 1) {
            $grams = round($qty * 1000, self::PRECISION);

            if (abs($grams - round($grams)) < 0.0005) {
                return ((int) round($grams)).' g';
            }

            return self::trimTrailingZeros(number_format($grams, 1, '.', '')).' g';
        }

        if (abs($qty - round($qty)) < 0.0005) {
            return ((int) round($qty)).' kg';
        }

        return self::trimTrailingZeros(number_format($qty, self::PRECISION, '.', '')).' kg';
    }

    private static function trimTrailingZeros(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
