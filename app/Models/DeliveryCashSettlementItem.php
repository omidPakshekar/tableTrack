<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCashSettlementItem extends BaseModel
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'float',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DeliveryCashSettlement::class, 'settlement_id');
    }

    public function orderCashCollection(): BelongsTo
    {
        return $this->belongsTo(OrderCashCollection::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
