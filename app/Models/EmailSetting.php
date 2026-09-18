<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BaseModel;
use App\Providers\CustomConfigProvider;

class EmailSetting extends BaseModel
{
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saved(function () {
            CustomConfigProvider::forgetBootstrapCache();
        });
    }
}
