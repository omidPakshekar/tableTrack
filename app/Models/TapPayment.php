<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TapPayment extends Model
{
    use HasFactory;

    protected $table = 'tap_payments';

    protected $fillable = [
        'tap_payment_id',
        'order_id',
        'amount',
        'payment_status',
        'payment_date',
        'payment_error_response',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'payment_error_response' => 'array',
    ];

    /**
     * Relationship: Each payment belongs to an order.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}


