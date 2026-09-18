<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'meta_image')) {
                $table->string('meta_image')->nullable()->after('meta_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'meta_image')) {
                $table->dropColumn('meta_image');
            }
        });
    }
};

