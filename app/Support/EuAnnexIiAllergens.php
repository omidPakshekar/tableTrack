<?php

namespace App\Support;

final class EuAnnexIiAllergens
{
    /**
     * Annex II allergen keys (Regulation (EU) No 1169/2011). Same 14 categories as asset folders under public/img/allergens/.
     * Sulphites are listed last so the long label does not stretch a middle grid row in forms.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'wheat',
            'crustacens',
            'eggs',
            'fish',
            'peanut',
            'soya',
            'milk',
            'treenut',
            'celery',
            'mustard',
            'sesame',
            'lupin',
            'molluscs',
            'sulphurdioxide',
        ];
    }

    public static function langKey(string $key): string
    {
        return 'modules.settings.euAllergen_' . $key;
    }

    /**
     * Default pictogram URL (red variant) under public/img/allergens/{key}/.
     */
    public static function defaultIconUrl(string $key): string
    {
        if (!in_array($key, self::keys(), true)) {
            return asset('img/food.svg');
        }

        return asset('img/allergens/' . $key . '/' . $key . '_red.svg');
    }

    /**
     * @param  array<int, string>|null  $stored
     * @return list<string>
     */
    public static function normalizedSelection(?array $stored): array
    {
        $allowed = self::keys();
        if (!is_array($stored) || $stored === []) {
            return $allowed;
        }

        $filtered = array_values(array_unique(array_intersect($stored, $allowed)));

        if ($filtered === []) {
            return $allowed;
        }

        $order = self::keys();

        return array_values(array_filter($order, static fn (string $k): bool => in_array($k, $filtered, true)));
    }
}
