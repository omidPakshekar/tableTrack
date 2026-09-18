<?php

namespace App\Models;

use App\Traits\HasBranch;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\BaseModel;

class Refund extends BaseModel
{
    use HasFactory;
    use HasBranch;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_adjustment' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundReason(): BelongsTo
    {
        return $this->belongsTo(RefundReason::class, 'refund_reason_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withoutGlobalScope(BranchScope::class);
    }

    public function deliveryApp(): BelongsTo
    {
        return $this->belongsTo(DeliveryPlatform::class, 'delivery_app_id');
    }

    public function deliveryPlatform(): BelongsTo
    {
        return $this->deliveryApp();
    }
}

