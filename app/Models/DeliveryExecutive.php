<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BaseModel;

class DeliveryExecutive extends BaseModel
{
    use HasBranch;
    use Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    

    /** @deprecated Renamed to {@see STATUS_ACTIVE}; kept for legacy rows before migration. */
    public const STATUS_AVAILABLE_LEGACY = 'available';

    protected $guarded = ['id'];

    public static function normalizeStatus(?string $status): string
    {
        return $status === self::STATUS_AVAILABLE_LEGACY ? self::STATUS_ACTIVE : (string) $status;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_AVAILABLE_LEGACY]);
    }

    /** Active executives who are online and can be assigned to delivery orders. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->active()->where('is_online', true);
    }

    public function isAssignable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_AVAILABLE_LEGACY], true)
            && (bool) $this->is_online;
    }

    public static function findAssignableForBranch(int $id, int $branchId): ?self
    {
        return static::query()
            ->where('id', $id)
            ->where('branch_id', $branchId)
            ->assignable()
            ->first();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id', 'desc');
    }

    public function orderCashCollections(): HasMany
    {
        return $this->hasMany(OrderCashCollection::class)->orderBy('id', 'desc');
    }

    public function cashSettlements(): HasMany
    {
        return $this->hasMany(DeliveryCashSettlement::class)->orderBy('id', 'desc');
    }

    public function routeNotificationForMail($notification): ?string
    {
        return $this->email ?: null;
    }
}
