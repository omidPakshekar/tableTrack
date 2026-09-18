<?php

namespace App\Support;

/**
 * Optional additional option labels stored on menu_items.dietary_labels (JSON array of keys).
 */
final class DietaryLabels
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'gluten_free',
            'sugar_free',
            'kosher',
            'vegan',
        ];
    }

    public static function langKey(string $key): string
    {
        return 'modules.menu.dietaryLabel_' . $key;
    }

    /**
     * Pictogram URL: public/img/{key}.svg (e.g. gluten_free.svg).
     */
    public static function defaultIconUrl(string $key): string
    {
        if (!in_array($key, self::keys(), true)) {
            return asset('img/food.svg');
        }

        
        return asset('img/icons/' . $key . '.svg');
    }

    /**
     * @param  array<int, string>|null  $stored
     * @return list<string>
     */
    public static function normalize(?array $stored): array
    {
        $allowed = self::keys();

        return array_values(array_unique(array_intersect(
            $allowed,
            array_filter($stored ?? [], 'is_string')
        )));
    }
}
