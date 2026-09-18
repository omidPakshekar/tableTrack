<?php

namespace App\Services\Pos;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Cache;

/**
 * Caches branch inventory levels and recipe mappings for client-side POS stock checks.
 * Safe when Inventory is disabled or not installed — returns an empty disabled payload.
 */
class PosIngredientStockCache
{
    public const CACHE_VERSION = 1;

    public const TTL_SECONDS = 86400;

    public static function cacheKey(int $branchId): string
    {
        return sprintf('pos.ingredient_stock.v%d.%d', self::CACHE_VERSION, $branchId);
    }

    public static function forgetForBranch(?int $branchId): void
    {
        if (! $branchId) {
            return;
        }

        Cache::forget(self::cacheKey((int) $branchId));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     stock: array<int, float>|object,
     *     base: array<int, list<array{0: int, 1: float}>>|object,
     *     variations: array<int, list<array{0: int, 1: float}>>|object,
     *     modifiers: array<int, list<array{0: int, 1: float}>>|object
     * }
     */
    public static function getPayload(int $branchId): array
    {
        $disabled = [
            'enabled' => false,
            'stock' => (object) [],
            'base' => (object) [],
            'variations' => (object) [],
            'modifiers' => (object) [],
        ];

        // Never serve a cached "enabled" payload when Inventory is off for this restaurant.
        if (! IngredientStockGuard::isEnabled()) {
            return $disabled;
        }

        return Cache::remember(
            self::cacheKey($branchId),
            self::TTL_SECONDS,
            fn () => self::buildPayload($branchId)
        );
    }

    /**
     * @return array{
     *     enabled: bool,
     *     stock: array<int, float>|object,
     *     base: array<int, list<array{0: int, 1: float}>>|object,
     *     variations: array<int, list<array{0: int, 1: float}>>|object,
     *     modifiers: array<int, list<array{0: int, 1: float}>>|object
     * }
     */
    private static function buildPayload(int $branchId): array
    {
        $disabled = [
            'enabled' => false,
            'stock' => (object) [],
            'base' => (object) [],
            'variations' => (object) [],
            'modifiers' => (object) [],
        ];

        if (! IngredientStockGuard::isEnabled()) {
            return $disabled;
        }

        $recipeClass = \Modules\Inventory\Entities\Recipe::class;
        $stockClass = \Modules\Inventory\Entities\InventoryStock::class;

        // Avoid autoload failures when module files are missing despite a stale enable flag.
        if (! class_exists($recipeClass) || ! class_exists($stockClass)) {
            return $disabled;
        }

        $menuItemIds = MenuItem::query()
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $base = [];
        $variations = [];
        $modifiers = [];

        if ($menuItemIds !== []) {
            $recipes = $recipeClass::query()
                ->without('menuItemData')
                ->whereNotNull('inventory_item_id')
                ->where(function ($query) use ($menuItemIds) {
                    $query->whereIn('menu_item_id', $menuItemIds)
                        ->orWhereNotNull('modifier_option_id');
                })
                ->get([
                    'menu_item_id',
                    'menu_item_variation_id',
                    'modifier_option_id',
                    'inventory_item_id',
                    'quantity',
                ]);

            foreach ($recipes as $recipe) {
                $inventoryItemId = (int) $recipe->inventory_item_id;
                if ($inventoryItemId <= 0) {
                    continue;
                }

                $pair = [$inventoryItemId, (float) $recipe->quantity];

                if ($recipe->modifier_option_id) {
                    $modifiers[(int) $recipe->modifier_option_id][] = $pair;

                    continue;
                }

                if ($recipe->menu_item_variation_id) {
                    $variations[(int) $recipe->menu_item_variation_id][] = $pair;

                    continue;
                }

                if ($recipe->menu_item_id) {
                    $base[(int) $recipe->menu_item_id][] = $pair;
                }
            }
        }

        return [
            'enabled' => true,
            'stock' => self::loadStock($branchId, $stockClass),
            'base' => $base === [] ? (object) [] : $base,
            'variations' => $variations === [] ? (object) [] : $variations,
            'modifiers' => $modifiers === [] ? (object) [] : $modifiers,
        ];
    }

    /**
     * @param  class-string  $stockClass
     * @return array<int, float>
     */
    private static function loadStock(int $branchId, string $stockClass): array
    {
        return $stockClass::query()
            ->where('branch_id', $branchId)
            ->pluck('quantity', 'inventory_item_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }
}
