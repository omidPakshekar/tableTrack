<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservations', 'area_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('area_id')->nullable()->after('table_id');
                $table->foreign('area_id')->references('id')->on('areas')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'area_id')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropForeign(['area_id']);
                $table->dropColumn('area_id');
            });
        }
    }
};
