<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Providers\CustomConfigProvider;

class PusherSetting extends BaseModel
{
    protected $guarded = ['id'];

    protected $appends = ['is_enabled_pusher_broadcast'];

    protected static function booted(): void
    {
        static::saved(function () {
            CustomConfigProvider::forgetBootstrapCache();
        });
    }

    public function getIsEnabledPusherBroadcastAttribute()
    {
        return $this->pusher_broadcast && $this->pusher_app_id && $this->pusher_key && $this->pusher_secret;
    }
}
