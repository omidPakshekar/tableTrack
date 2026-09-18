<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotificationSetting extends Model
{
    protected $table = 'order_notification_settings';

    protected $fillable = [
        'restaurant_id',
        'role_id',
        'hide_new_order_notification',
    ];
}

