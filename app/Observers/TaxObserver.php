<?php

namespace App\Observers;

use App\Models\Tax;
use App\Services\Pos\PosBranchCacheInvalidation;

class TaxObserver
{
    private function invalidatePosCachesForBranch(?int $branchId): void
    {
        PosBranchCacheInvalidation::invalidateForBranch($branchId);
    }

    public function creating(Tax $tax)
    {
        if (restaurant()) {
            $tax->restaurant_id = restaurant()->id;
        }

        if (branch()) {
            $tax->branch_id = branch()->id;
        }
    }

    public function created(Tax $tax): void
    {
        $this->invalidatePosCachesForBranch($tax->branch_id);
    }

    public function updated(Tax $tax): void
    {
        $this->invalidatePosCachesForBranch($tax->branch_id);

        $originalBranchId = $tax->getOriginal('branch_id');
        if ($originalBranchId && (int) $originalBranchId !== (int) $tax->branch_id) {
            $this->invalidatePosCachesForBranch((int) $originalBranchId);
        }
    }

    public function deleted(Tax $tax): void
    {
        $this->invalidatePosCachesForBranch($tax->branch_id ?: $tax->getOriginal('branch_id'));
    }
}
