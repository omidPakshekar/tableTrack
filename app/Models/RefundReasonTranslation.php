<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundReasonTranslation extends BaseModel
{
    protected $guarded = ['id'];

    public $timestamps = false;

    public function refundReason(): BelongsTo
    {
        return $this->belongsTo(RefundReason::class);
    }
}
