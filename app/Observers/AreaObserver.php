<?php

namespace App\Observers;

use App\Models\Area;
use App\Services\Tables\TablesIndexCache;

class AreaObserver
{
    public function creating(Area $area)
    {
        if (branch()) {
            $area->branch_id = branch()->id;
        }
    }

    public function created(Area $area): void
    {
        TablesIndexCache::forgetForBranch($area->branch_id);
    }

    public function updated(Area $area): void
    {
        TablesIndexCache::forgetForBranch($area->branch_id);
        if ($area->wasChanged('branch_id') && $area->getOriginal('branch_id')) {
            TablesIndexCache::forgetForBranch((int) $area->getOriginal('branch_id'));
        }
    }

    public function deleted(Area $area): void
    {
        Area::deleteImageFile($area->image);

        TablesIndexCache::forgetForBranch($area->branch_id);
    }

    public function restored(Area $area): void
    {
        TablesIndexCache::forgetForBranch($area->branch_id);
    }
}
