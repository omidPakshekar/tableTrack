<?php

namespace App\Models;

use App\Traits\HasRestaurant;

class RestaurantEuAllergenSetting extends BaseModel
{
    use HasRestaurant;

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'allergen_keys' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
