<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->enum('restaurant_open_close_mode', ['auto', 'manual'])
                ->default('auto')
                ->after('disable_order_type_popup');
            $table->time('manual_open_time')->nullable()->after('restaurant_open_close_mode');
            $table->time('manual_close_time')->nullable()->after('manual_open_time');
            $table->boolean('is_temporarily_closed')->default(false)->after('manual_close_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'restaurant_open_close_mode',
                'manual_open_time',
                'manual_close_time',
                'is_temporarily_closed',
            ]);
        });
    }
};

