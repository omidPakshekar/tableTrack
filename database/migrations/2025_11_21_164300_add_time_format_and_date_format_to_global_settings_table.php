<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            $table->string('time_format')->default('h:i A')->after('timezone');
            $table->string('date_format')->default('d/m/Y')->after('time_format');
        });
        // Set default time and date format for all existing global settings
        DB::table('global_settings')->update([
            'time_format' => 'h:i A',
            'date_format' => 'd/m/Y'
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            $table->dropColumn(['time_format', 'date_format']);
        });
    }
};

