<?php

namespace App\Observers;

use App\Models\ItemCategory;
use App\Services\Pos\PosTaxonomyCache;

class ItemCategoryObserver
{

    public function creating(ItemCategory $itemCategory)
    {
        if (branch()) {
            $itemCategory->branch_id = branch()->id;
        }
    }

    public function saved(ItemCategory $itemCategory): void
    {
        if ($itemCategory->branch_id) {
            PosTaxonomyCache::bumpBranch((int) $itemCategory->branch_id);
        }

        if ($itemCategory->wasChanged('branch_id') && $itemCategory->getOriginal('branch_id')) {
            PosTaxonomyCache::bumpBranch((int) $itemCategory->getOriginal('branch_id'));
        }
    }

    public function deleted(ItemCategory $itemCategory): void
    {
        if ($itemCategory->branch_id) {
            PosTaxonomyCache::bumpBranch((int) $itemCategory->branch_id);
        }
    }

}
