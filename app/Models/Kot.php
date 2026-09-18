<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BaseModel;

class Kot extends BaseModel
{
    use HasFactory;
    use HasBranch;

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(KotItem::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function kotPlace(): BelongsTo
    {
        return $this->belongsTo(KotPlace::class, 'kitchen_place_id');
    }

    public function cancelReason(): BelongsTo
    {
        return $this->belongsTo(KotCancelReason::class, 'cancel_reason_id');
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    public static function generateKotNumber($branch)
    {
        $lastKot = Kot::where('branch_id', $branch->id)->latest()->first();

        if ($lastKot) {
            return (((int)$lastKot->kot_number) + 1);
        }

        return 1;
    }

    public static function generateTokenNumber(int $branchId, ?int $orderTypeId): ?int
    {
        if (!$orderTypeId) {
            return null;
        }

        $orderType = OrderType::find($orderTypeId);
        if (!$orderType || !$orderType->enable_token_number) {
            return null;
        }

        $branch = Branch::find($branchId);
        
        // Get business day boundaries (in restaurant timezone)
        $boundaries = getBusinessDayBoundaries($branch, now());
        
        // Convert to UTC for database query (KOTs stored in UTC)
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $lastKotWithToken = self::where('branch_id', $branchId)
            ->whereNotNull('token_number')
            ->where('created_at', '>=', $startUTC)
            ->where('created_at', '<=', $endUTC)
            ->orderByDesc('id')
            ->first();

        return $lastKotWithToken ? ((int) $lastKotWithToken->token_number + 1) : 1;
    }
}
