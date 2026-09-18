<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ItemCategory;
use App\Models\ItemModifier;
use App\Models\KotPlace;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\MenuItemVariation;
use App\Models\ModifierGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchMenuCloneService
{
    /**
     * @param  array<int>  $targetBranchIds
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, int>>
     */
    public function cloneToBranches(int $sourceBranchId, array $targetBranchIds, array $options): array
    {
        $results = [];

        foreach ($targetBranchIds as $targetBranchId) {
            $targetBranch = Branch::withoutGlobalScopes()->find($targetBranchId);

            if (! $targetBranch || (int) $targetBranchId === $sourceBranchId) {
                continue;
            }

            $results[(int) $targetBranchId] = $this->clone($sourceBranchId, $targetBranch, $options);
        }

        return $results;
    }

    /**
     * @param  array{
     *     clone_menu?: bool,
     *     clone_categories?: bool,
     *     clone_menu_items?: bool,
     *     clone_item_modifiers?: bool,
     *     clone_modifier_groups?: bool,
     *     skip_existing?: bool,
     * }  $options
     * @return array{menus: int, categories: int, menu_items: int, modifier_groups: int, item_modifiers: int, skipped: array<string, int>}
     */
    public function clone(int $sourceBranchId, Branch $targetBranch, array $options): array
    {
        $counts = [
            'menus' => 0,
            'categories' => 0,
            'menu_items' => 0,
            'modifier_groups' => 0,
            'item_modifiers' => 0,
            'skipped' => [
                'menus' => 0,
                'categories' => 0,
                'menu_items' => 0,
                'modifier_groups' => 0,
                'item_modifiers' => 0,
            ],
        ];

        if ($sourceBranchId === (int) $targetBranch->id) {
            return $counts;
        }

        $cloneMenu = (bool) ($options['clone_menu'] ?? false);
        $cloneCategories = (bool) ($options['clone_categories'] ?? false);
        $cloneMenuItems = (bool) ($options['clone_menu_items'] ?? false);
        $cloneItemModifiers = (bool) ($options['clone_item_modifiers'] ?? false);
        $cloneModifierGroups = (bool) ($options['clone_modifier_groups'] ?? false);
        $skipExisting = (bool) ($options['skip_existing'] ?? false);

        if (! $cloneMenu && ! $cloneCategories && ! $cloneMenuItems && ! $cloneItemModifiers && ! $cloneModifierGroups) {
            return $counts;
        }

        return DB::transaction(function () use (
            $sourceBranchId,
            $targetBranch,
            $cloneMenu,
            $cloneCategories,
            $cloneMenuItems,
            $cloneItemModifiers,
            $cloneModifierGroups,
            $skipExisting,
            $counts
        ) {
            $menuMap = [];
            $categoryMap = [];
            $itemMap = [];
            $groupMap = [];
            $variationMap = [];

            $defaultKotPlaceId = KotPlace::withoutGlobalScopes()
                ->where('branch_id', $targetBranch->id)
                ->value('id');

            if ($cloneMenu) {
                $menus = Menu::withoutGlobalScopes()->where('branch_id', $sourceBranchId)->get();
                foreach ($menus as $menu) {
                    if ($skipExisting) {
                        $existingMenuId = $this->findExistingMenuId($targetBranch->id, $menu);

                        if ($existingMenuId) {
                            $menuMap[$menu->id] = $existingMenuId;
                            $counts['skipped']['menus']++;

                            continue;
                        }
                    }

                    Menu::withoutEvents(function () use ($menu, $targetBranch, &$menuMap, &$counts) {
                        $clone = $menu->replicate();
                        $clone->branch_id = $targetBranch->id;
                        $clone->save();
                        $menuMap[$menu->id] = $clone->id;
                        $counts['menus']++;
                    });
                }
            }

            if ($cloneCategories) {
                $categories = ItemCategory::withoutGlobalScopes()->where('branch_id', $sourceBranchId)->get();
                foreach ($categories as $category) {
                    if ($skipExisting) {
                        $existingCategoryId = $this->findExistingCategoryId($targetBranch->id, $category);

                        if ($existingCategoryId) {
                            $categoryMap[$category->id] = $existingCategoryId;
                            $counts['skipped']['categories']++;

                            continue;
                        }
                    }

                    ItemCategory::withoutEvents(function () use ($category, $targetBranch, &$categoryMap, &$counts) {
                        $clone = $category->replicate();
                        $clone->branch_id = $targetBranch->id;
                        $clone->save();
                        $categoryMap[$category->id] = $clone->id;
                        $counts['categories']++;
                    });
                }
            }

            if ($cloneModifierGroups) {
                $modifierGroups = ModifierGroup::withoutGlobalScopes()
                    ->where('branch_id', $sourceBranchId)
                    ->with(['translations', 'options'])
                    ->get();

                foreach ($modifierGroups as $group) {
                    if ($skipExisting) {
                        $existingGroupId = $this->findExistingModifierGroupId($targetBranch->id, $group);

                        if ($existingGroupId) {
                            $groupMap[$group->id] = $existingGroupId;
                            $counts['skipped']['modifier_groups']++;

                            continue;
                        }
                    }

                    $clonedGroup = $group->replicate();
                    $clonedGroup->branch_id = $targetBranch->id;
                    $clonedGroup->save();
                    $groupMap[$group->id] = $clonedGroup->id;
                    $counts['modifier_groups']++;

                    foreach ($group->translations as $translation) {
                        $clonedTranslation = $translation->replicate();
                        $clonedTranslation->modifier_group_id = $clonedGroup->id;
                        $clonedTranslation->save();
                    }

                    foreach ($group->options as $option) {
                        $clonedOption = $option->replicate();
                        $clonedOption->modifier_group_id = $clonedGroup->id;
                        $clonedOption->save();
                    }
                }
            }

            $menuItems = MenuItem::with(['modifiers', 'taxes', 'translations', 'variations'])
                ->withoutGlobalScopes()
                ->where('branch_id', $sourceBranchId)
                ->get();

            $existingMenuItemKeys = $skipExisting
                ? $this->buildExistingMenuItemKeys($targetBranch->id)
                : collect();

            if ($cloneMenuItems && $menuItems->isNotEmpty()) {
                foreach ($menuItems as $item) {
                    $targetMenuId = $menuMap[$item->menu_id] ?? null;
                    $targetCategoryId = $categoryMap[$item->item_category_id] ?? null;

                    if ($skipExisting) {
                        $itemKey = $this->menuItemMatchKey(
                            $targetMenuId,
                            $targetCategoryId,
                            $this->comparableMenuItemName($item)
                        );

                        if ($itemKey && $existingMenuItemKeys->has($itemKey)) {
                            $itemMap[$item->id] = $existingMenuItemKeys->get($itemKey);
                            $counts['skipped']['menu_items']++;

                            continue;
                        }

                        if (! $targetMenuId) {
                            continue;
                        }
                    }

                    MenuItem::withoutEvents(function () use (
                        $item,
                        $targetBranch,
                        $menuMap,
                        $categoryMap,
                        $defaultKotPlaceId,
                        &$itemMap,
                        &$variationMap,
                        &$counts
                    ) {
                        $clone = $item->replicate();
                        $clone->branch_id = $targetBranch->id;
                        $clone->kot_place_id = $defaultKotPlaceId;
                        $clone->menu_id = $menuMap[$item->menu_id] ?? null;
                        $clone->item_category_id = $categoryMap[$item->item_category_id] ?? null;
                        $clone->save();

                        $itemMap[$item->id] = $clone->id;
                        $counts['menu_items']++;

                        foreach ($item->translations as $translation) {
                            $clonedTranslation = $translation->replicate();
                            $clonedTranslation->menu_item_id = $clone->id;
                            $clonedTranslation->save();
                        }

                        foreach ($item->variations as $variation) {
                            $clonedVariation = $variation->replicate();
                            $clonedVariation->menu_item_id = $clone->id;
                            $clonedVariation->save();
                            $variationMap[$variation->id] = $clonedVariation->id;
                        }

                        if ($item->taxes && $item->taxes->isNotEmpty()) {
                            $taxIds = $item->taxes->pluck('id')->toArray();
                            $clone->taxes()->sync($taxIds);
                        }
                    });
                }
            }

            if ($cloneItemModifiers && $menuItems->isNotEmpty()) {
                foreach ($menuItems as $item) {
                    $clonedItemId = $itemMap[$item->id] ?? null;

                    if (! $clonedItemId || ! $item->modifiers) {
                        continue;
                    }

                    foreach ($item->modifiers as $modifier) {
                        $targetGroupId = $groupMap[$modifier->modifier_group_id] ?? null;

                        if (! $targetGroupId) {
                            continue;
                        }

                        if ($skipExisting && ItemModifier::where('menu_item_id', $clonedItemId)
                            ->where('modifier_group_id', $targetGroupId)
                            ->exists()) {
                            $counts['skipped']['item_modifiers']++;

                            continue;
                        }

                        $clonedModifier = $modifier->replicate();
                        $clonedModifier->menu_item_id = $clonedItemId;
                        $clonedModifier->modifier_group_id = $targetGroupId;
                        $clonedModifier->menu_item_variation_id = isset($modifier->menu_item_variation_id, $variationMap[$modifier->menu_item_variation_id])
                            ? $variationMap[$modifier->menu_item_variation_id]
                            : $modifier->menu_item_variation_id;
                        $clonedModifier->save();
                        $counts['item_modifiers']++;
                    }
                }
            }

            return $counts;
        });
    }

    protected function findExistingMenuId(int $targetBranchId, Menu $menu): ?int
    {
        $needle = $this->comparableMenuName($menu);

        return Menu::withoutGlobalScopes()
            ->where('branch_id', $targetBranchId)
            ->get()
            ->first(fn (Menu $candidate) => $this->comparableMenuName($candidate) === $needle)
            ?->id;
    }

    protected function findExistingCategoryId(int $targetBranchId, ItemCategory $category): ?int
    {
        $needle = $this->comparableCategoryName($category);

        return ItemCategory::withoutGlobalScopes()
            ->where('branch_id', $targetBranchId)
            ->get()
            ->first(fn (ItemCategory $candidate) => $this->comparableCategoryName($candidate) === $needle)
            ?->id;
    }

    protected function findExistingModifierGroupId(int $targetBranchId, ModifierGroup $group): ?int
    {
        $needle = $this->comparableModifierGroupName($group);

        return ModifierGroup::withoutGlobalScopes()
            ->where('branch_id', $targetBranchId)
            ->with('translations')
            ->get()
            ->first(fn (ModifierGroup $candidate) => $this->comparableModifierGroupName($candidate) === $needle)
            ?->id;
    }

    /**
     * @return Collection<string, int>
     */
    protected function buildExistingMenuItemKeys(int $targetBranchId): Collection
    {
        return MenuItem::withoutGlobalScopes()
            ->where('branch_id', $targetBranchId)
            ->get()
            ->mapWithKeys(function (MenuItem $item) {
                $key = $this->menuItemMatchKey(
                    $item->menu_id,
                    $item->item_category_id,
                    $this->comparableMenuItemName($item)
                );

                return $key ? [$key => $item->id] : [];
            });
    }

    protected function menuItemMatchKey(?int $menuId, ?int $categoryId, string $itemName): ?string
    {
        if (! $menuId || $itemName === '') {
            return null;
        }

        return $menuId.'|'.($categoryId ?? '0').'|'.$itemName;
    }

    protected function comparableMenuName(Menu $menu): string
    {
        return $this->normalizeLabel($menu->getTranslation('menu_name', app()->getLocale(), false)
            ?: $menu->getTranslation('menu_name', 'en', false)
            ?: $menu->menu_name);
    }

    protected function comparableCategoryName(ItemCategory $category): string
    {
        return $this->normalizeLabel($category->getTranslation('category_name', app()->getLocale(), false)
            ?: $category->getTranslation('category_name', 'en', false)
            ?: $category->category_name);
    }

    protected function comparableMenuItemName(MenuItem $item): string
    {
        return $this->normalizeLabel($item->item_name);
    }

    protected function comparableModifierGroupName(ModifierGroup $group): string
    {
        return $this->normalizeLabel($group->name);
    }

    protected function normalizeLabel(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[app()->getLocale()] ?? reset($value) ?: '';
        }

        return strtolower(trim((string) $value));
    }
}
