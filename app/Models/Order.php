<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\HasBranch;
use App\Enums\OrderStatus;
use App\Models\OrderCharge;
use App\Scopes\BranchScope;
use App\Models\DeliveryExecutive;
use App\Models\OrderNumberSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class Order extends BaseModel
{
    use HasFactory;
    use HasBranch;

    /** Billing workflow values stored in orders.status (American spelling). */
    public const BILLING_STATUS_DRAFT = 'draft';

    public const BILLING_STATUS_CANCELED = 'canceled';

    protected $guarded = ['id'];
    protected $appends = ['show_formatted_order_number'];

    protected $casts = [
        'date_time' => 'datetime',
        'order_status' => OrderStatus::class,
        'cancel_time' => 'datetime',
        'pickup_date' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id')->withoutGlobalScope(BranchScope::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by')->withoutGlobalScope(BranchScope::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withoutGlobalScope(BranchScope::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(OrderTax::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(OrderCharge::class);
    }

    public function extraCharges(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantCharge::class, 'order_charges', 'order_id', 'charge_id');
    }

    public function kot(): HasMany
    {
        return $this->hasMany(Kot::class)->where('status', '!=', 'cancelled');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function orderCashCollection(): HasOne
    {
        return $this->hasOne(OrderCashCollection::class);
    }

    public function splitOrders(): HasMany
    {
        return $this->hasMany(SplitOrder::class, 'order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScopes();
    }

    public function deliveryExecutive(): BelongsTo
    {
        return $this->belongsTo(DeliveryExecutive::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function cancelReason(): BelongsTo
    {
        return $this->belongsTo(KotCancelReason::class, 'cancel_reason_id');
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    public function deliveryApp(): BelongsTo
    {
        return $this->belongsTo(DeliveryPlatform::class, 'delivery_app_id');
    }

    public function deliveryPlatform(): BelongsTo
    {
        return $this->deliveryApp();
    }

    public function hotelStay(): BelongsTo
    {
        if (isHotelModuleEnabled()) {
            return $this->belongsTo(\Modules\Hotel\Entities\Stay::class, 'context_id')
                ->withoutGlobalScopes();
        }

        return $this->belongsTo(self::class, 'context_id')->whereRaw('0 = 1');
    }

    public function hotelRoom(): BelongsTo
    {
        if (isHotelModuleEnabled()) {
            return $this->belongsTo(\Modules\Hotel\Entities\Room::class, 'hotel_room_id')
                ->withoutGlobalScopes();
        }

        return $this->belongsTo(self::class, 'hotel_room_id')->whereRaw('0 = 1');
    }

    public function getContextRoomNumberAttribute(): ?string
    {
        if (! isHotelModuleEnabled()) {
            return null;
        }

        if ($this->relationLoaded('hotelStay') && $this->hotelStay?->room) {
            return $this->hotelStay->room->room_number;
        }

        if ($this->context_type === 'HOTEL_ROOM' && $this->context_id) {
            $stay = $this->hotelStay()->with('room')->first();
            if ($stay?->room) {
                return $stay->room->room_number;
            }
        }

        if ($this->relationLoaded('hotelRoom') && $this->hotelRoom) {
            return $this->hotelRoom->room_number;
        }

        if ($this->hotel_room_id) {
            return \Modules\Hotel\Entities\Room::withoutGlobalScopes()
                ->find($this->hotel_room_id)
                ?->room_number;
        }

        return null;
    }

    public function isDineInOrder(): bool
    {
        return $this->order_type === 'dine_in';
    }

    public function isRoomServiceOrder(): bool
    {
        if ($this->order_type === 'room_service') {
            return true;
        }

        if ($this->relationLoaded('orderType') && $this->orderType) {
            return ($this->orderType->type ?? null) === 'room_service'
                || ($this->orderType->slug ?? null) === 'room_service';
        }

        if ($this->order_type_id) {
            $orderType = $this->orderType()->first(['type', 'slug']);

            return ($orderType?->type === 'room_service') || ($orderType?->slug === 'room_service');
        }

        return false;
    }

    public function getContextStayNumberAttribute(): ?string
    {
        if (! isHotelModuleEnabled()) {
            return null;
        }

        if ($this->relationLoaded('hotelStay') && $this->hotelStay) {
            return $this->hotelStay->stay_number;
        }

        if ($this->context_type === 'HOTEL_ROOM' && $this->context_id) {
            return $this->hotelStay()->value('stay_number');
        }

        return null;
    }

    public function hasCollectedCodPayment(): bool
    {
        if ($this->order_type !== 'delivery') {
            return false;
        }

        $cashCollectionStatus = $this->relationLoaded('orderCashCollection')
            ? $this->orderCashCollection?->status
            : $this->orderCashCollection()->value('status');

        return in_array($cashCollectionStatus, ['collected', 'submitted', 'settled'], true);
    }

    public function isFullyPaid(): bool
    {
        $totalAmount = round((float) ($this->total ?? 0), 2);

        if ($totalAmount <= 0) {
            return false;
        }

        return round((float) ($this->amount_paid ?? 0), 2) >= $totalAmount
            || $this->hasCollectedCodPayment();
    }

    public function remainingAmount(): float
    {
        if ($this->isFullyPaid()) {
            return 0.0;
        }

        return round(max((float) ($this->total ?? 0) - (float) ($this->amount_paid ?? 0), 0), 2);
    }

    /**
     * Orders whose party still counts against table seating (number_of_pax).
     * Paid dine-in stays reserved until order_status is completed or cancelled.
     */
    public function scopeOccupyingTableSeats(Builder $query): Builder
    {
        $releasedOrderStatuses = [
            OrderStatus::CANCELLED->value,
            OrderStatus::COMPLETED->value,
        ];

        return $query
            ->whereNotIn('status', [self::BILLING_STATUS_DRAFT, self::BILLING_STATUS_CANCELED])
            ->where(function (Builder $q) use ($releasedOrderStatuses) {
                $q->where(function (Builder $q2) {
                    $q2->whereNull('order_status')
                        ->where('status', '!=', 'paid');
                })->orWhereNotIn('order_status', $releasedOrderStatuses);
            });
    }

    /**
     * Latest occupying order id per table for a branch (one entry per table with an active order).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function activeOrderIdsByTableForBranch(int $branchId): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('table_id')
            ->occupyingTableSeats()
            ->selectRaw('table_id, MAX(id) as order_id')
            ->groupBy('table_id')
            ->pluck('order_id', 'table_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Mark table available and clear POS table lock after the visit is finished.
     */
    public function releaseTableSeatHoldIfAssigned(): void
    {
        if (! $this->table_id) {
            return;
        }

        $table = Table::query()->find($this->table_id);
        if (! $table) {
            return;
        }

        $table->update(['available_status' => 'available']);

        if ($table->tableSession) {
            $table->tableSession->releaseLock();
        }
    }

    public static function generateOrderNumber($branch)
    {
        // Check if order number settings exist and feature is enabled
        $settings = getOrderNumberSetting($branch->id);

        if ($settings && $settings->enable_feature) {
            return self::generateFormattedOrderNumber($branch->id, $settings);
        }

        $maxOrderNumber = Order::where('branch_id', $branch->id)->whereNotNull('order_number')->where('status', '!=', 'draft')->max(DB::raw('CAST(order_number AS UNSIGNED)'));
        $orderNumber = $maxOrderNumber ? ((int)$maxOrderNumber + 1) : 1;

        return [
            'order_number' => $orderNumber,
            'formatted_order_number' => ($settings && $settings->enable_feature) ? (string) $orderNumber : null
        ];
    }

    private static function generateFormattedOrderNumber($branchId, $settings)
    {
        $branch = Branch::find($branchId);
        $currentTime = now(restaurant()->timezone ?? 'UTC');

        // Determine the next order number
        $orderQuery = Order::where('branch_id', $branchId)->whereNotNull('order_number')->where('status', '!=', 'draft');

        if ($settings->reset_daily) {
            // Use business day boundaries if operational shifts are configured
            $boundaries = getBusinessDayBoundaries($branch, $currentTime);
            
            // Convert to UTC for database query (orders stored in UTC)
            $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
            $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();
            
            // Filter orders within business day
            $orderQuery->where('created_at', '>=', $startUTC)
                ->where('created_at', '<=', $endUTC);
        }

        $maxOrderNumber = $orderQuery->max(DB::raw('CAST(order_number AS UNSIGNED)'));
        $nextNumber = $maxOrderNumber ? ((int)$maxOrderNumber + 1) : 1;

        // Check if the order number already exists (to avoid duplicates)
        do {
            $existsQuery = Order::where('branch_id', $branchId)
                ->where('order_number', $nextNumber);
            
            if ($settings->reset_daily) {
                // Use business day boundaries for duplicate check
                $boundaries = getBusinessDayBoundaries($branch, $currentTime);
                $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
                $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();
                
                $existsQuery->where('created_at', '>=', $startUTC)
                    ->where('created_at', '<=', $endUTC);
            } else {
                $existsQuery->where('created_at', '>=', $currentTime->startOfDay());
            }
            
            $exists = $existsQuery->exists();

            if ($exists) {
                $nextNumber++;
            }
        } while ($exists);

        // Generate formatted order number
        $formattedNumber = self::buildFormattedOrderNumber($nextNumber, $settings, $currentTime);

        return [
            'order_number' => $nextNumber,
            'formatted_order_number' => $formattedNumber
        ];
    }


    public static function buildFormattedOrderNumber($orderNumber, $settings, $currentTime)
    {
        $parts = [];

        // Add prefix
        if (!empty($settings->prefix)) {
            $parts[] = $settings->prefix;
        }

        // Add date components if enabled
        if ($settings->include_date) {
            $dateParts = [];

            if ($settings->show_year) {
                $dateParts[] = $currentTime->format('Y');
            }

            if ($settings->show_month) {
                $dateParts[] = $currentTime->format('m');
            }

            if ($settings->show_day) {
                $dateParts[] = $currentTime->format('d');
            }

            if (!empty($dateParts)) {
                $parts[] = implode('', $dateParts);
            }

            if ($settings->show_time) {
                $parts[] = $currentTime->format('Hi'); // HHMM format
            }
        }

        $paddedNumber = str_pad($orderNumber, $settings->digits, '0', STR_PAD_LEFT);
        $parts[] = $paddedNumber;

        // Join all parts with separator
        return implode($settings->separator, $parts);
    }



    public function getShowFormattedOrderNumberAttribute()
    {

        if (!is_null($this->formatted_order_number)) {
            return $this->formatted_order_number;
        }

        return __('modules.order.orderNumber') . ' #' . $this->order_number;
    }

    public function kiosk(): ?BelongsTo
    {
        if (
            module_enabled('Kiosk') &&
            class_exists(\Modules\Kiosk\Entities\Kiosk::class)
        ) {
            return $this->belongsTo(\Modules\Kiosk\Entities\Kiosk::class);
        }

        return null;
    }

    /**
     * Relationship to POS Machine (MultiPOS Module)
     */
    public function posMachine()
    {
        if (module_enabled('MultiPOS')) {
            return $this->belongsTo(\Modules\MultiPOS\Entities\PosMachine::class, 'pos_machine_id');
        }

        return null;
    }
}
