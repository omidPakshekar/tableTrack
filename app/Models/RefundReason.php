<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\BaseModel;

class RefundReason extends BaseModel
{
    use HasBranch, HasFactory;

    protected $guarded = ['id'];

    protected $with = ['translations'];

    public function translations(): HasMany
    {
        return $this->hasMany(RefundReasonTranslation::class);
    }

    public function translation(?string $locale = null): HasOne
    {
        return $this->hasOne(RefundReasonTranslation::class)->where('locale', $locale ?? app()->getLocale());
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
}
