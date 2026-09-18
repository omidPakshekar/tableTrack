<?php

namespace App\Models;

use App\Traits\HasBranch;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchOperationalShift extends BaseModel
{
    use HasBranch;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'day_of_week' => 'array', // Cast JSON to array
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
