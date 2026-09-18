<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CachedSchema
{
    public static function hasColumn(string $table, string $column): bool
    {
        try {
            return Cache::rememberForever(
                "schema:{$column}_column:{$table}",
                fn () => Schema::hasColumn($table, $column)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function rememberSingleton(string $modelClass, string $cacheKey): object
    {
        return Cache::rememberForever($cacheKey, function () use ($modelClass) {
            $record = $modelClass::query()->first();
            if (!$record) {
                $record = $modelClass::create([]);
            }
            return $record;
        });
    }

    public static function forgetSingleton(string $cacheKey): void
    {
        Cache::forget($cacheKey);
    }
}