<?php

namespace App\Models;

use App\Traits\HasRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\BaseModel;

class KotCancelReason extends BaseModel
{
    use HasRestaurant, HasFactory;

    protected $guarded = ['id'];

    protected $with = ['translations'];

    public function translations(): HasMany
    {
        return $this->hasMany(KotCancelReasonTranslation::class);
    }

    public function translation(?string $locale = null): HasOne
    {
        return $this->hasOne(KotCancelReasonTranslation::class)->where('locale', $locale ?? app()->getLocale());
    }

    public function getTranslatedReason(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $translation = $this->translations->firstWhere('locale', $locale);

        return $translation?->reason ?? $this->attributes['reason'] ?? '';
    }

    public function getReasonAttribute(): string
    {
        return $this->getTranslatedReason();
    }

    public function isOtherReason(): bool
    {
        return strtolower((string) ($this->getRawOriginal('reason') ?? '')) === 'other';
    }
}
