<?php

namespace App\Models;

use App\Models\BaseModel;

class OfflinePaymentMethod extends BaseModel
{
    protected $fillable = ['restaurant_id', 'name', 'description', 'status'];

    /**
     * Relationship with Restaurant
     */
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Relationship with OfflinePlanChange
     */
    public function offlinePlanChanges()
    {
        return $this->hasMany(OfflinePlanChange::class, 'offline_method_id');
    }

    /**
     * Scope to get only active payment methods
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get only enabled payment methods (alias for active)
     */
    public function scopeEnabled($query)
    {
        return $query->where('status', 'active');
    }
}
