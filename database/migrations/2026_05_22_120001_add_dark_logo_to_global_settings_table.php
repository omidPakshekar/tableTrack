<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('global_settings', 'dark_logo')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            $table->string('dark_logo')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('global_settings', 'dark_logo')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            $table->dropColumn('dark_logo');
        });
    }
};
