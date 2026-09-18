<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeletedOrder extends BaseModel
{
    use HasFactory;
    use HasBranch;

    protected $guarded = ['id'];

    protected $casts = [
        'order_date_time' => 'datetime',
        'order_deleted_at' => 'datetime',
        'payment_methods' => 'array',
        'items' => 'array',
    ];

    protected $appends = ['show_formatted_order_number'];

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function getShowFormattedOrderNumberAttribute(): string
    {
        if (!empty($this->formatted_order_number)) {
            return $this->formatted_order_number;
        }

        return $this->order_number ? '#' . $this->order_number : __('modules.report.notAvailable');
    }
}
