<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('language_settings')) {
            DB::table('language_settings')->where('language_code', 'en')->update(['active' => 0]);
            DB::table('language_settings')->where('language_code', 'fa')->update([
                'active' => 1,
                'is_rtl' => 1,
                'language_name' => 'فارسی',
            ]);
        }

        if (Schema::hasTable('global_settings')) {
            DB::table('global_settings')->update(['locale' => 'fa']);
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->update(['locale' => 'fa']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('language_settings')) {
            DB::table('language_settings')->where('language_code', 'en')->update(['active' => 1]);
            DB::table('language_settings')->where('language_code', 'fa')->update(['active' => 0]);
        }

        if (Schema::hasTable('global_settings')) {
            DB::table('global_settings')->update(['locale' => 'en']);
        }

        if (Schema::hasTable('users')) {
            DB::table('users')->update(['locale' => 'en']);
        }
    }
};
