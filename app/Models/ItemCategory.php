<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ItemCategory extends BaseModel
{

    use HasFactory;
    use HasBranch;
    use HasTranslations;

    protected $guarded = ['id'];
    public $translatable = ['category_name'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->withoutGlobalScopes();
    }
    protected $appends = [
        'category_image_url',
        'category_initials',
    ];

    public function categoryImageUrl(): Attribute
    {
        return Attribute::get(function (): string {
            
            if ($this->image) {
                return asset_url_local_s3('item-category/' . $this->image);
            }
           
            return asset('img/category.svg');
        });
    }

  
    public function categoryInitials(): Attribute
    {   
        return Attribute::get(function (): string {
            $name = (string) $this->category_name;
            $words = preg_split('/\s+/', trim($name)) ?: [];
            $initials = collect($words)
                ->filter()
                ->take(2)
                ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
                ->join('');
            $initials = $initials !== '' ? $initials : '?';
            return $initials;
        });
    }


    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrderItem::class,
            MenuItem::class,
            'item_category_id', // Foreign key on the environments table...
            'menu_item_id', // Foreign key on the deployments table...
            'id', // Local key on the projects table...
            'id' // Local key on the environments table...
        );
    }
}
