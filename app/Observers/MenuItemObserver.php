<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Services\Pos\PosBranchCacheInvalidation;

class MenuItemObserver
{

    public function creating(MenuItem $menuItem)
    {
        if (branch()) {
            $menuItem->branch_id = branch()->id;
        }
    }

    public function saved(MenuItem $menuItem): void
    {
        PosBranchCacheInvalidation::invalidateForBranch((int) $menuItem->branch_id);

        if ($menuItem->wasChanged('branch_id')) {
            $previousBranchId = $menuItem->getOriginal('branch_id');
            if ($previousBranchId) {
                PosBranchCacheInvalidation::invalidateForBranch((int) $previousBranchId);
            }
        }
    }

    public function deleted(MenuItem $menuItem): void
    {
        PosBranchCacheInvalidation::invalidateForBranch((int) $menuItem->branch_id);

        MenuItem::deleteImageFileIfUnreferenced($menuItem->image);
    }

}
