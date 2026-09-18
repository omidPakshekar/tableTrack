<?php

namespace App\Observers;

use App\Models\Menu;
use App\Services\Pos\PosTaxonomyCache;

class MenuObserver
{

    public function creating(Menu $menu)
    {
        if (branch()) {
            $menu->branch_id = branch()->id;
        }
    }

    public function saved(Menu $menu): void
    {
        if ($menu->branch_id) {
            PosTaxonomyCache::bumpBranch((int) $menu->branch_id);
        }

        if ($menu->wasChanged('branch_id') && $menu->getOriginal('branch_id')) {
            PosTaxonomyCache::bumpBranch((int) $menu->getOriginal('branch_id'));
        }
    }

    public function deleted(Menu $menu): void
    {
        if ($menu->branch_id) {
            PosTaxonomyCache::bumpBranch((int) $menu->branch_id);
        }
    }

}
