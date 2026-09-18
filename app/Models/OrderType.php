<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Model;

class OrderType extends Model
{
    use HasBranch;

    protected $guarded = ['id'];


    protected $casts = [
        'enable_token_number' => 'boolean',
        'show_order_number_on_board' => 'boolean',
        'enable_from_customer_site' => 'boolean',
    ];

    /**
     * Get the translated name for the order type
     */
    public function getTranslatedNameAttribute()
    {
        // Try to get translation based on slug
        if ($this->slug) {
            $translationKey = 'modules.order.' . $this->slug;
            $translated = __($translationKey);

            // If translation exists, use it
            if ($translated !== $translationKey) {
                return $translated;
            }
        }

        // Fallback to order_type_name
        return $this->order_type_name;
    }
    
    /**
     * Scope to filter order types based on module availability
     * Filters out room_service if Hotel module is not enabled
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailableForRestaurant($query)
    {
        $isHotelModuleEnabled = isHotelModuleEnabled();
        
        // If Hotel module is not enabled, exclude room_service
        if (!$isHotelModuleEnabled) {
            $query->where('slug', '!=', 'room_service');
        }
        
        return $query;
    }
}
