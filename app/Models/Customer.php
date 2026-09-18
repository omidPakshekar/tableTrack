<?php

namespace App\Models;

use App\Traits\HasRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends BaseModel
{
    use HasFactory;
    use Notifiable;
    use HasRestaurant;
    use Notifiable;

    protected $guarded = ['id'];

    /**
     * Get loyalty points attribute
     * This accessor ensures the property is accessible in views
     * Works whether loyalty module is enabled or not
     */
    public function getLoyaltyPointsAttribute()
    {
        // Get attributes using getAttributes() method (more reliable than direct access)
        $attrs = $this->getAttributes();

        // Check if loyalty_points exists in attributes
        if (array_key_exists('loyalty_points', $attrs)) {
            $value = $attrs['loyalty_points'];
            // Handle null, empty string, or falsy values
            if ($value === null || $value === '' || $value === false) {
                return 0;
            }
            return (int)$value;
        }

        // Check if it was set as a regular property
        if (property_exists($this, 'loyalty_points') && $this->loyalty_points !== null && $this->loyalty_points !== '') {
            return (int)$this->loyalty_points;
        }

        // Default to 0 if not set (module might not be enabled or customer has no account)
        return 0;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id', 'desc');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderBy('id', 'desc');
    }

    public function latestDeliveryAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class, 'customer_id')->orderByDesc('id');
    }

    public function routeNotificationForVonage($notification)
    {
        if (!is_null($this->phone) && !is_null($this->phone_code)) {
            return '+' . $this->phone_code . $this->phone;
        }

        return null;
    }

    public function routeNotificationForTwilio()
    {
        if (!is_null($this->phone) && !is_null($this->phone_code)) {
            return '+' . $this->phone_code . $this->phone;
        }

        return null;
    }

    public function routeNotificationForMsg91($notification)
    {
        if (!is_null($this->phone) && !is_null($this->phone_code)) {
            return $this->phone_code . $this->phone;
        }

        return null;
    }

    public function routeNotificationForAndroidSmsGateway($notification)
    {
        return $this->routeNotificationForVonage($notification);
    }

}
