<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\OrderType;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Pos\MenuItemsCatalogCache;
use App\Support\DietaryLabels;
use App\Support\EuAnnexIiAllergens;
use App\Services\Pos\IngredientStockGuard;
use Illuminate\Support\Facades\DB;

class CustomerSiteCatalogBuilder
{
    /**
     * Full customer-site menu payload for one-shot hydration (filters run client-side).
     *
     * @return array<string, mixed>
     */
    public static function build(
        Restaurant $restaurant,
        Branch $shopBranch,
        ?Table $table,
        bool $cameFromQr,
        string $locale,
        int $resolvedOrderTypeId
    ): array {
        $assignedMenuIds = [];
        if ($cameFromQr && $table?->id) {
            $assignedMenuIds = DB::table('menu_table')
                ->where('table_id', $table->id)
                ->where('is_active', true)
                ->pluck('menu_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $menuQuery = Menu::withoutGlobalScopes()
            ->where('branch_id', $shopBranch->id);

        if ($assignedMenuIds !== []) {
            $menuQuery->whereIn('id', $assignedMenuIds);
        }

        $menus = $menuQuery
            ->withCount([
                'items as customer_items_count' => function ($q) {
                    $q->where('show_on_customer_site', true);
                },
            ])
            ->orderBy('sort_order')
            ->get();

        $itemsQuery = MenuItem::query()
            ->select('menu_items.*', 'item_categories.category_name', 'item_categories.sort_order as category_sort_order')
            ->join('item_categories', 'menu_items.item_category_id', '=', 'item_categories.id')
            ->where('menu_items.branch_id', $shopBranch->id)
            ->where('menu_items.show_on_customer_site', true);

        if ($assignedMenuIds !== []) {
            $itemsQuery->whereIn('menu_items.menu_id', $assignedMenuIds);
        }

        $items = $itemsQuery
            ->with([
                'category',
                'prices' => fn ($q) => $q->where('status', true),
            ])
            ->withCount(['variations', 'modifierGroups'])
            ->orderBy('item_categories.sort_order')
            ->orderBy('menu_items.item_category_id')
            ->orderBy('menu_items.sort_order')
            ->get();

        $customerOrderTypes = OrderType::query()
            ->where('branch_id', $shopBranch->id)
            ->where('enable_from_customer_site', true)
            ->availableForRestaurant()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        $orderTypeIds = $customerOrderTypes->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($resolvedOrderTypeId === 0 && $orderTypeIds !== []) {
            $resolvedOrderTypeId = (int) $customerOrderTypes->first()->id;
        }

        $modifierCatalogByItemId = MenuItemsCatalogCache::modifierCatalogForMenuItems($items);

        $euSelectableKeys = $restaurant->selectableEuAllergenKeys();
        $catalogEuAllergenEnabled = $euSelectableKeys !== [];

        $itemRows = [];
        foreach ($items as $item) {
            $pricesByOt = [];
            $priceLabelsByOt = [];
            foreach ($orderTypeIds as $otId) {
                $item->setPriceContext($otId, null);
                $p = (float) $item->price;
                $pricesByOt[(string) $otId] = $p;
                $priceLabelsByOt[(string) $otId] = currency_format($p, $restaurant->currency_id);
            }

            $names = [$item->getTranslatedValue('item_name', $locale)];
            foreach ($item->translations ?? [] as $tr) {
                if (!empty($tr->item_name)) {
                    $names[] = $tr->item_name;
                }
            }
            $searchBlob = mb_strtolower(implode(' ', array_unique(array_filter($names))));

            $categoryLabel = $item->category
                ? $item->category->getTranslation('category_name', $locale)
                : (string) ($item->category_name ?? '');

            $euKeysFiltered = [];
            $euAllergensDisplay = [];
            if ($catalogEuAllergenEnabled) {
                $stored = array_filter((array) ($item->eu_allergen_keys ?? []), 'is_string');
                $euKeysFiltered = array_values(array_unique(array_intersect(
                    EuAnnexIiAllergens::keys(),
                    $euSelectableKeys,
                    $stored
                )));
                foreach ($euKeysFiltered as $k) {
                    $euAllergensDisplay[] = [
                        'key' => $k,
                        'label' => __(EuAnnexIiAllergens::langKey($k)),
                        'icon' => EuAnnexIiAllergens::defaultIconUrl($k),
                    ];
                }
            }

            $dietaryKeys = DietaryLabels::normalize(
                is_array($item->dietary_labels ?? null) ? $item->dietary_labels : []
            );
            $dietaryLabelsDisplay = [];
            foreach ($dietaryKeys as $dk) {
                $dietaryLabelsDisplay[] = [
                    'key' => $dk,
                    'label' => __(DietaryLabels::langKey($dk)),
                    'icon' => DietaryLabels::defaultIconUrl($dk),
                ];
            }

            $itemRows[] = [
                'id' => $item->id,
                'menu_id' => $item->menu_id,
                'item_category_id' => $item->item_category_id,
                'category_label' => $categoryLabel,
                'category_sort_order' => (int) ($item->category_sort_order ?? 0),
                'item_name' => $item->getTranslatedValue('item_name', $locale),
                'description' => $item->getTranslatedValue('description', $locale),
                'type' => $item->type,
                'item_photo_url' => $item->item_photo_url,
                'preparation_time' => $item->preparation_time,
                'in_stock' => IngredientStockGuard::resolveInStockForCatalog(
                    (int) $shopBranch->id,
                    (int) $item->id,
                    (bool) $item->in_stock,
                ),
                'is_available' => (bool) $item->is_available,
                'sort_order' => (int) $item->sort_order,
                'variations_count' => (int) ($item->variations_count ?? 0),
                'modifier_groups_count' => (int) ($item->modifier_groups_count ?? 0),
                'modifier_catalog' => $modifierCatalogByItemId[$item->id] ?? ['base' => [], 'by_variation' => []],
                'search_blob' => $searchBlob,
                'prices_by_order_type' => $pricesByOt,
                'price_labels_by_order_type' => $priceLabelsByOt,
                'eu_allergen_keys' => $catalogEuAllergenEnabled ? $euKeysFiltered : null,
                'eu_allergens_display' => $euAllergensDisplay,
                'dietary_labels' => $dietaryKeys,
                'dietary_labels_display' => $dietaryLabelsDisplay,
            ];
        }

        $categories = $items
            ->groupBy('item_category_id')
            ->map(function ($group) use ($locale) {
                $first = $group->first();
                $label = $first->category
                    ? $first->category->getTranslation('category_name', $locale)
                    : (string) ($first->category_name ?? '');

                return [
                    'id' => (int) $first->item_category_id,
                    'name' => $label,
                    'sort_order' => (int) ($first->category_sort_order ?? 0),
                    'items_count' => $group->count(),
                ];
            })
            ->values()
            ->sortBy(['sort_order', 'id'])
            ->values()
            ->all();

        $menuPayload = $menus->map(function (Menu $menu) use ($locale) {
            return [
                'id' => $menu->id,
                'name' => $menu->getTranslation('menu_name', $locale),
                'sort_order' => (int) $menu->sort_order,
                'items_count' => (int) ($menu->customer_items_count ?? 0),
            ];
        })->values()->all();

        $orderTypesPayload = $customerOrderTypes->map(function (OrderType $ot) use ($locale) {
            return [
                'id' => $ot->id,
                'slug' => $ot->slug,
                'type' => $ot->type,
                'name' => $ot->translated_name,
            ];
        })->values()->all();

        return [
            'locale' => $locale,
            'currency_id' => $restaurant->currency_id,
            'resolved_order_type_id' => $resolvedOrderTypeId,
            'eu_allergens_enabled' => $catalogEuAllergenEnabled,
            'menus' => $menuPayload,
            'categories' => $categories,
            'items' => $itemRows,
            'order_types' => $orderTypesPayload,
        ];
    }
}
