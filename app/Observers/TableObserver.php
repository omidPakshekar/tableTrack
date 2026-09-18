<?php

namespace App\Observers;

use App\Models\Table;
use App\Services\Tables\TablesIndexCache;

class TableObserver
{
    public function creating(Table $table)
    {
        if (branch()) {
            $table->branch_id = branch()->id;
        }
    }

    public function created(Table $table): void
    {
        TablesIndexCache::forgetForBranch($table->branch_id);
    }

    public function updated(Table $table): void
    {
        TablesIndexCache::forgetForBranch($table->branch_id);
        if ($table->wasChanged('branch_id') && $table->getOriginal('branch_id')) {
            TablesIndexCache::forgetForBranch((int) $table->getOriginal('branch_id'));
        }
    }

    public function deleted(Table $table): void
    {
        TablesIndexCache::forgetForBranch($table->branch_id);
    }

    public function restored(Table $table): void
    {
        TablesIndexCache::forgetForBranch($table->branch_id);
    }
}
