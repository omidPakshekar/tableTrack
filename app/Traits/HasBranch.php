<?php
namespace App\Traits;
use App\Models\Branch;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
trait HasBranch
{
    protected static function bootHasBranch()
    {
        static::applyBranchScopeIfColumnExists();
    }
    protected static function booted()
    {
        static::applyBranchScopeIfColumnExists();
    }
    protected static function applyBranchScopeIfColumnExists(): void
    {
        $table = (new static())->getTable();
        static $hasBranchColumn = [];
        if (!array_key_exists($table, $hasBranchColumn)) {
            try {
                $hasBranchColumn[$table] = \App\Support\CachedSchema::hasColumn($table, 'branch_id');
            } catch (\Throwable $e) {
                $hasBranchColumn[$table] = false;
            }
        }
        if ($hasBranchColumn[$table]) {
            static::addGlobalScope(new BranchScope());
        }
    }
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}