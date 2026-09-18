<?php

use App\Models\GlobalSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'map_provider')) {
                $table->enum('map_provider', ['google', 'osm'])->default('osm')->after('google_map_api_key');
            }
        });

        $globalSetting = GlobalSetting::first();

        if ($globalSetting) {
            if ($globalSetting->google_map_api_key) {
                $globalSetting->map_provider = 'google';
            } else {
                $globalSetting->map_provider = 'osm';
            }
            $globalSetting->save();
        }
    }

    public function down(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'map_provider')) {
                $table->dropColumn('map_provider');
            }
        });
    }
};
