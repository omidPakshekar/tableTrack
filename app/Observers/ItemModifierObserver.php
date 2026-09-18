<?php

namespace App\Observers;

use App\Models\ItemModifier;
use App\Models\MenuItem;
use App\Services\Pos\PosBranchCacheInvalidation;

class ItemModifierObserver
{
    public function saved(ItemModifier $itemModifier): void
    {
        $this->invalidateBranchCatalog($itemModifier);
    }

    public function deleted(ItemModifier $itemModifier): void
    {
        $this->invalidateBranchCatalog($itemModifier);
    }

    private function invalidateBranchCatalog(ItemModifier $itemModifier): void
    {
        if (!$itemModifier->menu_item_id) {
            return;
        }

        $branchId = MenuItem::withoutGlobalScopes()
            ->where('id', $itemModifier->menu_item_id)
            ->value('branch_id');

        PosBranchCacheInvalidation::invalidateForBranch((int) $branchId);
    }
}
