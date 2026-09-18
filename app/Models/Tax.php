<?php

namespace App\Models;

use App\Traits\HasBranch;
use App\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use App\Models\BaseModel;

class Tax extends BaseModel
{
    use HasFactory;
    use HasBranch;

    protected $guarded = ['id'];

    protected static function booted()
    {
        if (self::hasColumn('restaurant_id')) {
            static::addGlobalScope(new RestaurantScope());
        }
    }

    protected static function hasColumn(string $column): bool
    {
        static $columns = [];
        $table = (new static())->getTable();
        $key = $table . '.' . $column;

        if (array_key_exists($key, $columns)) {
            return $columns[$key];
        }

        try {
            $columns[$key] = Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            $columns[$key] = false;
        }

        return $columns[$key];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_tax', 'tax_id', 'menu_item_id');
    }

    public function orderTaxes(): HasMany
    {
        return $this->hasMany(OrderTax::class);
    }
}
