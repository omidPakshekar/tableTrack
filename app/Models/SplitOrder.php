<?php

namespace App\Models;

use App\Models\BaseModel;

class SplitOrder extends BaseModel
{
    protected $fillable = [
        'order_id', 
        'amount', 
        'payment_method', 
        'status',
        'payer_name',
    ];

    public function items()
    {
        return $this->hasMany(SplitOrderItem::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
