<?php

namespace App\Models;

use App\Helper\Files;
use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BaseModel;
use App\Models\MenuItem;

class Area extends BaseModel
{
    use HasFactory;
    use HasBranch;

   


    protected $guarded = ['id'];

    protected $appends = [
        'area_photo_url',
    ];

    
    public function areaPhotoUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (blank($this->image)) {
                return null;
            }

            if (in_array($this->image, MenuItem::FILENAME_TO_EXCLUDE)) {
                return asset_url('area/' . $this->image);
            }


            return asset_url_local_s3('area/' . $this->image);
        });
    }

    public static function deleteImageFile(?string $image): void
    {
        if (blank($image)) {
            return;
        }

        Files::deleteFile($image, 'area');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
