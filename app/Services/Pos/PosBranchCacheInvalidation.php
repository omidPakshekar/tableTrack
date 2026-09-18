<?php

namespace App\Services\Pos;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates server-side POS caches and bumps the per-branch tax revision marker
 * so Blade/Vue POS clients clear localStorage (see pos.blade.php syncPosTaxRevisionCache).
 */
class PosBranchCacheInvalidation
{
    public static function invalidateForBranch(?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        $bid = (int) $branchId;

        PosTaxonomyCache::bumpBranch($bid);
        MenuItemsCatalogCache::forgetForBranch($bid);
        PosIngredientStockCache::forgetForBranch($bid);
        Cache::forget('menus_' . $bid);

        $revKey = 'pos.tax.rev.branch.' . $bid;
        Cache::forever($revKey, (int) Cache::get($revKey, 0) + 1);
    }

    /**
     * Invalidate all branches for a restaurant (tax settings are restaurant-wide).
     */
    public static function invalidateForRestaurant(Restaurant $restaurant): void
    {
        $ids = $restaurant->branches()->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        if ($ids === [] && function_exists('branch') && branch()) {
            $ids = [(int) branch()->id];
        }
        foreach ($ids as $bid) {
            if ($bid > 0) {
                self::invalidateForBranch($bid);
            }
        }
    }
}
