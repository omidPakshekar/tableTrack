<?php

namespace App\Services\Pos;

use App\Models\ItemCategory;
use App\Models\Menu;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * POS "taxonomy": menus (top-level) and item categories with per-filter item counts.
 * Uses a per-branch revision counter so all filter variants invalidate together.
 *
 * Bump {@see self::bumpBranch()} from ItemCategoryObserver, MenuObserver, and MenuItemObserver.
 */
class PosTaxonomyCache
{
    public const CACHE_VERSION = 1;

    public const TTL_SECONDS = 86400;

    public static function revisionKey(int $branchId): string
    {
        return sprintf('pos.taxonomy.rev.v%d.%d', self::CACHE_VERSION, $branchId);
    }

    public static function branchRevision(int $branchId): int
    {
        return (int) Cache::get(self::revisionKey($branchId), 0);
    }

    /**
     * Invalidate cached menus + category payloads for this branch (all menu/search variants).
     */
    public static function bumpBranch(?int $branchId): void
    {
        if (!$branchId) {
            return;
        }

        $bid = (int) $branchId;
        $key = self::revisionKey($bid);
        Cache::forever($key, self::branchRevision($bid) + 1);

        Cache::forget('menus_' . $bid);
    }

    public static function menusCacheKey(int $branchId): string
    {
        $locale = app()->getLocale();

        return sprintf(
            'pos.pos_menus.v%d.%d.%s.r%d',
            self::CACHE_VERSION,
            $branchId,
            $locale,
            self::branchRevision($branchId)
        );
    }

    public static function categoriesCacheKey(int $branchId, ?string $menuId, string $search): string
    {
        $locale = app()->getLocale();
        $menuKey = ($menuId !== null && $menuId !== '') ? (string) $menuId : '_';
        $searchNorm = trim($search);
        $searchKey = $searchNorm !== '' ? 'q_' . md5($searchNorm) : '_';

        return sprintf(
            'pos.pos_categories.v%d.%d.%s.m%s.s%s.r%d',
            self::CACHE_VERSION,
            $branchId,
            $locale,
            $menuKey,
            $searchKey,
            self::branchRevision($branchId)
        );
    }

    /**
     * @template T
     * @param  Closure(): T  $resolver
     * @return T
     */
    public static function rememberMenus(int $branchId, Closure $resolver)
    {
        return Cache::remember(
            self::menusCacheKey($branchId),
            self::TTL_SECONDS,
            $resolver
        );
    }

    /**
     * Item categories for POS filters (counts depend on menu + search).
     *
     * @param  Closure(): array<int, array<string, mixed>>  $resolver
     * @return array<int, array<string, mixed>>
     */
    public static function rememberCategories(int $branchId, ?string $menuId, string $search, Closure $resolver): array
    {
        return Cache::remember(
            self::categoriesCacheKey($branchId, $menuId, $search),
            self::TTL_SECONDS,
            $resolver
        );
    }

    /**
     * Run the POS categories query (used by cache resolver and for parity).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildCategoriesPayload(int $branchId, ?string $menuId, string $search): array
    {
        $categories = ItemCategory::query()
            ->select('id', 'category_name', 'sort_order')
            ->where('branch_id', $branchId)
            ->withCount(['items' => function ($query) use ($branchId, $menuId, $search) {
                $query->where('branch_id', $branchId);

                if ($menuId) {
                    $query->where('menu_id', $menuId);
                }

                $query->matchesSearch($search);
            }])
            ->having('items_count', '>', 0)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'count' => $category->items_count,
                    'category_name' => $category->getTranslation('category_name', session('locale', app()->getLocale())),
                    'sort_order' => $category->sort_order,
                ];
            })
            ->values()
            ->all();

        return $categories;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function buildMenusPayload(int $branchId): array
    {
        return Menu::query()
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Menu $menu) {
                return [
                    'id' => $menu->id,
                    'menu_name' => $menu->getTranslation('menu_name', session('locale', app()->getLocale())),
                    'sort_order' => $menu->sort_order,
                ];
            })
            ->values()
            ->all();
    }
}
