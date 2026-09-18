<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantImpersonationLog extends BaseModel
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function superadmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superadmin_user_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RestaurantImpersonationLogAction::class);
    }

    public function isActive(): bool
    {
        return is_null($this->ended_at);
    }

    public function durationInSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->ended_at ?? now();

        return (int) $this->started_at->diffInSeconds($end);
    }
}
