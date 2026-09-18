<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\ItemModifier;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Restaurant;
use App\Support\DietaryLabels;
use App\Support\EuAnnexIiAllergens;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use App\Services\Pos\IngredientStockGuard;

/**
 * Caches the heavy full-branch POS menu item list (used with load_all) per branch + locale.
 * Invalidated from MenuItemObserver when items are created, updated, or deleted.
 */
class MenuItemsCatalogCache
{
    public const CACHE_VERSION = 6;

    public const TTL_SECONDS = 86400;

    public const CATALOG_LIMIT = 20000;

    public static function cacheKey(int $branchId, string $locale): string
    {
        return sprintf(
            'pos.menu_items.catalog.v%d.%d.%s',
            self::CACHE_VERSION,
            $branchId,
            $locale
        );
    }

    /**
     * Drop cached catalog rows for this branch across known locales (lang folders + app locales).
     */
    public static function forgetForBranch(?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        foreach (self::localesForInvalidation() as $locale) {
            Cache::forget(self::cacheKey($branchId, $locale));
        }
    }

    /**
     * @return array{total_count: int, items: array<int, array<string, mixed>>}
     */
    public static function getCatalogPayload(int $branchId): array
    {
        $locale = app()->getLocale();

        return Cache::remember(
            self::cacheKey($branchId, $locale),
            self::TTL_SECONDS,
            function () use ($branchId) {
                return self::buildCatalogPayload($branchId);
            }
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function applyOrderContextToRows(
        array $rows,
        int $branchId,
        int $orderTypeId,
        ?int $normalizedDeliveryAppId
    ): array {
        if ($rows === []) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $models = MenuItem::where('branch_id', $branchId)
            ->whereIn('id', $ids)
            ->with(['prices' => function ($query) {
                $query->select([
                    'id',
                    'menu_item_id',
                    'order_type_id',
                    'delivery_app_id',
                    'menu_item_variation_id',
                    'final_price',
                    'status',
                ])
                    ->where('status', true);
            }])
            ->get()
            ->keyBy('id');

        $optionIds = [];
        foreach ($rows as $row) {
            self::collectModifierCatalogOptionIds($row['modifier_catalog'] ?? null, $optionIds);
        }
        $optionIds = array_values(array_unique(array_filter($optionIds)));

        $optionsById = collect();
        if ($orderTypeId && $optionIds !== []) {
            $optionsById = ModifierOption::whereIn('id', $optionIds)
                ->with(['prices' => function ($query) {
                    $query->where('status', true);
                }])
                ->get()
                ->keyBy('id');
        }

        $out = [];
        foreach ($rows as $row) {
            $copy = $row;
            $model = $models->get($row['id']);
            if ($model) {
                $model->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
                $copy['price'] = (float) $model->price;
            }

            if (!empty($copy['variations']) && is_array($copy['variations']) && $model) {
                $copy['variations'] = self::applyVariationCatalogContextPrices(
                    $copy['variations'],
                    $model,
                    $orderTypeId,
                    $normalizedDeliveryAppId
                );
            }

            if (!empty($copy['modifier_catalog'])) {
                $mc = json_decode(json_encode($copy['modifier_catalog']), true);
                if ($orderTypeId && $optionIds !== []) {
                    $copy['modifier_catalog'] = self::applyModifierCatalogContextPrices(
                        $mc,
                        $optionsById,
                        $orderTypeId,
                        $normalizedDeliveryAppId
                    );
                } else {
                    $copy['modifier_catalog'] = $mc;
                }
            }

            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @return array{total_count: int, items: array<int, array<string, mixed>>}
     */
    private static function buildCatalogPayload(int $branchId): array
    {
        $query = MenuItem::where('branch_id', $branchId);
        $totalCount = (int) (clone $query)->count();

        $menuItems = $query->with([
                'taxes:id,tax_name,tax_percent',
                'variations' => function ($q) {
                    $q->select(['id', 'menu_item_id', 'variation', 'price'])
                        ->orderBy('id');
                },
            ])
            ->withCount(['variations', 'modifierGroups'])
            ->limit(self::CATALOG_LIMIT)
            ->get();

        $modifierCatalogByItemId = self::buildModifierCatalogForMenuItems($menuItems);

        $restaurantSelectable = [];
        $branch = Branch::query()->select(['id', 'restaurant_id'])->find($branchId);
        if ($branch && $branch->restaurant_id) {
            $restaurant = Restaurant::query()->find((int) $branch->restaurant_id);
            if ($restaurant) {
                $restaurantSelectable = $restaurant->selectableEuAllergenKeys();
            }
        }
        $appendEuAllergens = $restaurantSelectable !== [];

        $items = $menuItems->map(function (MenuItem $menuItem) use ($branchId, $modifierCatalogByItemId, $appendEuAllergens, $restaurantSelectable) {
            $mid = $menuItem->id;

            $euAllergenKeys = [];
            if ($appendEuAllergens) {
                $euAllergenKeys = array_values(array_unique(array_intersect(
                    EuAnnexIiAllergens::keys(),
                    $restaurantSelectable,
                    array_filter((array) ($menuItem->eu_allergen_keys ?? []), 'is_string')
                )));
            }

            $dietaryLabels = DietaryLabels::normalize(
                is_array($menuItem->dietary_labels ?? null) ? $menuItem->dietary_labels : []
            );

            return [
                'id' => $mid,
                'menu_id' => $menuItem->menu_id,
                'item_category_id' => $menuItem->item_category_id,
                'item_name' => $menuItem->item_name,
                'description' => (string) ($menuItem->description ?? ''),
                'price' => (float) ($menuItem->getAttributes()['price'] ?? 0),
                'item_photo_url' => $menuItem->item_photo_url,
                'type' => $menuItem->type,
                'in_stock' => IngredientStockGuard::resolveInStockForCatalog(
                    $branchId,
                    $mid,
                    (bool) $menuItem->in_stock,
                ),
                'sell_by_weight' => (bool) ($menuItem->sell_by_weight ?? false),
                'variations_count' => (int) ($menuItem->variations_count ?? 0),
                'variations' => collect($menuItem->variations ?? [])->map(function ($variation) {
                    return [
                        'id' => (int) $variation->id,
                        'variation' => (string) ($variation->variation ?? ''),
                        'price' => (float) ($variation->getAttributes()['price'] ?? 0),
                    ];
                })->values()->all(),
                'modifier_groups_count' => (int) ($menuItem->modifier_groups_count ?? 0),
                'eu_allergen_keys' => $euAllergenKeys,
                'dietary_labels' => $dietaryLabels,
                'modifier_catalog' => $modifierCatalogByItemId[$mid] ?? ['base' => [], 'by_variation' => []],
                'taxes' => collect($menuItem->taxes ?? [])->map(function ($tax) {
                    return [
                        'id' => $tax->id,
                        'tax_name' => $tax->tax_name,
                        'tax_percent' => (float) $tax->tax_percent,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return [
            'total_count' => $totalCount,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array{base: array<int, array<string, mixed>>, by_variation: array<int, array<int, array<string, mixed>>>}>
     */
    public static function modifierCatalogForMenuItems(Collection $menuItems): array
    {
        return self::buildModifierCatalogForMenuItems($menuItems);
    }

    /**
     * @return array<int, array{base: array<int, array<string, mixed>>, by_variation: array<int, array<int, array<string, mixed>>>}>
     */
    private static function buildModifierCatalogForMenuItems(Collection $menuItems): array
    {
        $itemIds = $menuItems->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($itemIds === []) {
            return [];
        }

        $itemModifiers = ItemModifier::whereIn('menu_item_id', $itemIds)
            ->orderBy('id')
            ->get()
            ->groupBy('menu_item_id');

        $groupIds = $itemModifiers->flatten(1)->pluck('modifier_group_id')->unique()->filter()->values()->all();

        $empty = ['base' => [], 'by_variation' => []];
        $out = [];
        foreach ($itemIds as $mid) {
            $out[$mid] = $empty;
        }

        if ($groupIds === []) {
            return $out;
        }

        $groups = ModifierGroup::whereIn('id', $groupIds)
            ->with(['options' => function ($q) {
                $q->orderBy('sort_order')->orderBy('id');
            }])
            ->get()
            ->keyBy('id');

        foreach ($itemModifiers as $mid => $rows) {
            $mid = (int) $mid;
            foreach ($rows->whereNull('menu_item_variation_id')->sortBy('id')->values() as $im) {
                $g = $groups->get($im->modifier_group_id);
                if ($g) {
                    $out[$mid]['base'][] = self::serializeModifierGroupForCatalog($g, $im);
                }
            }

            foreach ($rows->whereNotNull('menu_item_variation_id')->groupBy('menu_item_variation_id') as $vid => $vrows) {
                $list = [];
                foreach ($vrows->sortBy('id')->values() as $im) {
                    $g = $groups->get($im->modifier_group_id);
                    if ($g) {
                        $list[] = self::serializeModifierGroupForCatalog($g, $im);
                    }
                }
                if ($list !== []) {
                    $out[$mid]['by_variation'][(int) $vid] = $list;
                }
            }
        }

        return $out;
    }

    private static function serializeModifierGroupForCatalog(ModifierGroup $group, ItemModifier $im): array
    {
        return [
            'id' => $group->id,
            'name' => $group->getTranslatedValue('name'),
            'description' => (string) $group->getTranslatedValue('description'),
            'is_required' => (bool) $im->is_required,
            'allow_multiple_selection' => (bool) $im->allow_multiple_selection,
            'options' => $group->options->map(function (ModifierOption $opt) {
                return [
                    'id' => $opt->id,
                    'name' => $opt->name,
                    'is_available' => (bool) $opt->is_available,
                    'price' => (float) ($opt->getAttributes()['price'] ?? 0),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<int>  $into
     */
    private static function collectModifierCatalogOptionIds(?array $catalog, array &$into): void
    {
        if (!$catalog) {
            return;
        }
        foreach ($catalog['base'] ?? [] as $g) {
            foreach ($g['options'] ?? [] as $o) {
                if (!empty($o['id'])) {
                    $into[] = (int) $o['id'];
                }
            }
        }
        foreach ($catalog['by_variation'] ?? [] as $groups) {
            foreach ($groups as $g) {
                foreach ($g['options'] ?? [] as $o) {
                    if (!empty($o['id'])) {
                        $into[] = (int) $o['id'];
                    }
                }
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ModifierOption>  $optionsById
     */
    private static function applyModifierCatalogContextPrices(
        array $catalog,
        Collection $optionsById,
        int $orderTypeId,
        ?int $normalizedDeliveryAppId
    ): array {
        $out = ['base' => [], 'by_variation' => []];
        foreach ($catalog['base'] ?? [] as $g) {
            $out['base'][] = self::applyModifierGroupOptionPrices($g, $optionsById, $orderTypeId, $normalizedDeliveryAppId);
        }
        foreach ($catalog['by_variation'] ?? [] as $vid => $groups) {
            $vid = (int) $vid;
            $list = [];
            foreach ($groups as $g) {
                $list[] = self::applyModifierGroupOptionPrices($g, $optionsById, $orderTypeId, $normalizedDeliveryAppId);
            }
            $out['by_variation'][$vid] = $list;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $variations
     * @return array<int, array<string, mixed>>
     */
    private static function applyVariationCatalogContextPrices(
        array $variations,
        MenuItem $model,
        int $orderTypeId,
        ?int $normalizedDeliveryAppId
    ): array {
        $out = [];
        foreach ($variations as $variation) {
            $copy = $variation;
            $variationId = (int) ($variation['id'] ?? 0);
            $fallback = (float) ($variation['price'] ?? 0);
            if ($variationId > 0 && $model->relationLoaded('prices')) {
                $copy['price'] = self::resolveVariationPriceFromLoadedPrices(
                    $model,
                    $variationId,
                    $fallback,
                    $orderTypeId,
                    $normalizedDeliveryAppId
                );
            }
            $out[] = $copy;
        }

        return $out;
    }

    private static function resolveVariationPriceFromLoadedPrices(
        MenuItem $model,
        int $variationId,
        float $fallbackPrice,
        int $orderTypeId,
        ?int $deliveryAppId
    ): float {
        $prices = $model->prices;

        $exact = $prices
            ->where('status', true)
            ->where('order_type_id', $orderTypeId)
            ->where('menu_item_variation_id', $variationId)
            ->when(
                $deliveryAppId,
                fn ($collection) => $collection->where('delivery_app_id', $deliveryAppId),
                fn ($collection) => $collection->whereNull('delivery_app_id')
            )
            ->first();

        if ($exact) {
            return (float) $exact->final_price;
        }

        if ($deliveryAppId) {
            $relaxed = $prices
                ->where('status', true)
                ->where('order_type_id', $orderTypeId)
                ->where('menu_item_variation_id', $variationId)
                ->whereNull('delivery_app_id')
                ->first();

            if ($relaxed) {
                $basePrice = (float) $relaxed->final_price;
                $deliveryPlatform = \App\Models\DeliveryPlatform::find($deliveryAppId);
                if ($deliveryPlatform && $deliveryPlatform->commission_value > 0) {
                    return (float) $deliveryPlatform->getPriceWithCommission($basePrice);
                }

                return $basePrice;
            }
        }

        return $fallbackPrice;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ModifierOption>  $optionsById
     */
    private static function applyModifierGroupOptionPrices(
        array $group,
        Collection $optionsById,
        int $orderTypeId,
        ?int $normalizedDeliveryAppId
    ): array {
        $g = $group;
        $g['options'] = [];
        foreach ($group['options'] ?? [] as $opt) {
            $o = $opt;
            $m = $optionsById->get($opt['id']);
            if ($m) {
                $m->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
                $o['price'] = (float) $m->price;
            }
            $g['options'][] = $o;
        }

        return $g;
    }

    /**
     * @return list<string>
     */
    private static function localesForInvalidation(): array
    {
        $fromLangDirs = [];
        $langPath = lang_path();
        if (is_dir($langPath)) {
            $fromLangDirs = collect(File::directories($langPath))
                ->map(fn (string $dir) => basename($dir))
                ->all();
        }

        return array_values(array_unique(array_filter(array_merge(
            $fromLangDirs,
            [
                app()->getLocale(),
                (string) config('app.locale'),
                (string) config('app.fallback_locale'),
            ]
        ))));
    }
}
