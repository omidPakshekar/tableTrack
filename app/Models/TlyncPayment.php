<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TlyncPayment extends Model
{
    use HasFactory;

    protected $table = 'tlync_payments';

    protected $fillable = [
        'tlync_payment_id',
        'order_id',
        'amount',
        'phone',
        'custom_ref',
        'payment_status',
        'payment_date',
        'payment_error_response',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'payment_error_response' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
