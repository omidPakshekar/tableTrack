<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('areas', 'image')) {
            return;
        }

        Schema::table('areas', function (Blueprint $table) {
            $table->string('image')->nullable()->after('area_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('areas', 'image')) {
            return;
        }

        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
