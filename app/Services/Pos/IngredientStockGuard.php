<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\Restaurant;
use Nwidart\Modules\Facades\Module;

/**
 * Optional Inventory bridge for POS/shop.
 * When Inventory is not installed or not enabled for the restaurant,
 * callers fall back to the menu item's own in_stock flag / allow orders.
 */
class IngredientStockGuard
{
    /** @var array<int, bool> */
    private static array $enabledByRestaurant = [];

    /** @var class-string|false|null */
    private static $validatorClass = null;

    public static function isEnabled(?int $restaurantId = null): bool
    {
        if (! self::validatorClass()) {
            return false;
        }

        if ($restaurantId) {
            if (array_key_exists($restaurantId, self::$enabledByRestaurant)) {
                return self::$enabledByRestaurant[$restaurantId];
            }

            $restaurant = Restaurant::with('package.modules')->find($restaurantId);
            $enabled = $restaurant
                && function_exists('restaurant_modules')
                && in_array('Inventory', restaurant_modules($restaurant), true);

            return self::$enabledByRestaurant[$restaurantId] = (bool) $enabled;
        }

        return function_exists('restaurant_modules')
            && in_array('Inventory', restaurant_modules(), true);
    }

    public static function resolveInStockForCatalog(int $branchId, int $menuItemId, bool $manualInStock): bool
    {
        if (! self::isEnabledForBranch($branchId)) {
            return $manualInStock;
        }

        return self::validatorClass()::resolveInStockForCatalog(
            $branchId,
            $menuItemId,
            $manualInStock,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{ok: bool, error?: string, unavailable_items?: array<int, string>}
     */
    public static function validateCartFromPosItems(int $branchId, array $items): array
    {
        if (! self::isEnabledForBranch($branchId)) {
            return ['ok' => true];
        }

        $validator = self::validatorClass();

        return $validator::validateCartLines(
            $branchId,
            $validator::buildCartLinesFromPosItems($items),
        );
    }

    /**
     * @param  array<int, mixed>  $orderItemList
     * @param  array<int|numeric-string, int>  $orderItemQty
     * @param  array<int|numeric-string, mixed>  $orderItemVariation
     * @param  array<int|numeric-string, array<int>>  $itemModifiersSelected
     * @return array{ok: bool, error?: string, unavailable_items?: array<int, string>}
     */
    public static function validateCartFromOrderState(
        int $branchId,
        array $orderItemList,
        array $orderItemQty,
        array $orderItemVariation = [],
        array $itemModifiersSelected = [],
    ): array {
        if (! self::isEnabledForBranch($branchId)) {
            return ['ok' => true];
        }

        $validator = self::validatorClass();

        return $validator::validateCartLines(
            $branchId,
            $validator::buildCartLines(
                $orderItemList,
                $orderItemQty,
                $orderItemVariation,
                $itemModifiersSelected,
            ),
        );
    }

    /**
     * @param  array<int>  $modifierOptionIds
     * @return array{ok: bool, error?: string, unavailable_items?: array<int, string>}
     */
    public static function validateOrderLine(
        int $branchId,
        int $menuItemId,
        ?int $menuItemVariationId,
        array $modifierOptionIds,
        float|int $quantity,
    ): array {
        if (! self::isEnabledForBranch($branchId)) {
            return ['ok' => true];
        }

        return self::validatorClass()::validateOrderLine(
            $branchId,
            $menuItemId,
            $menuItemVariationId,
            $modifierOptionIds,
            (float) $quantity,
        );
    }

    public static function insufficientStockMessage(): string
    {
        if (self::isEnabled()) {
            return __('inventory::modules.stock.insufficientIngredientStock');
        }

        return 'Item is out of stock.';
    }

    private static function isEnabledForBranch(int $branchId): bool
    {
        $restaurantId = Branch::query()->whereKey($branchId)->value('restaurant_id');

        return self::isEnabled($restaurantId ? (int) $restaurantId : null);
    }

    /**
     * Resolve Inventory validator only when the module files are present.
     * Avoids class_exists() autoload on missing files — Laravel turns that include warning into a 500.
     *
     * @return class-string<\Modules\Inventory\Services\RecipeStockValidator>|null
     */
    private static function validatorClass(): ?string
    {
        if (self::$validatorClass === false) {
            return null;
        }

        if (is_string(self::$validatorClass)) {
            return self::$validatorClass;
        }

        $class = \Modules\Inventory\Services\RecipeStockValidator::class;

        if (! function_exists('module_enabled') || ! module_enabled('Inventory')) {
            self::$validatorClass = false;

            return null;
        }

        $module = Module::find('Inventory');
        $validatorPath = ($module ? $module->getPath() : base_path('Modules/Inventory'))
            .'/Services/RecipeStockValidator.php';

        if (! is_file($validatorPath)) {
            self::$validatorClass = false;

            return null;
        }

        return self::$validatorClass = $class;
    }
}
