<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KotCancelReasonTranslation extends BaseModel
{
    protected $guarded = ['id'];

    public $timestamps = false;

    public function kotCancelReason(): BelongsTo
    {
        return $this->belongsTo(KotCancelReason::class);
    }
}
