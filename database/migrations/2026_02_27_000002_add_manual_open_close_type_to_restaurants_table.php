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
            $table->enum('restaurant_manual_open_close_type', ['time', 'toggle'])
                ->default('time')
                ->after('restaurant_open_close_mode');
        });

        if (!Schema::hasColumn('restaurants', 'restaurant_open_close_mode')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->enum('restaurant_open_close_mode', ['auto', 'manual'])->default('auto');
            });
        }

        if (!Schema::hasColumn('restaurants', 'restaurant_manual_open_close_type')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->enum('restaurant_manual_open_close_type', ['time', 'toggle'])->default('time');
            });
        }

        if (!Schema::hasColumn('restaurants', 'manual_open_time')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->time('manual_open_time')->nullable();
            });
        }

        if (!Schema::hasColumn('restaurants', 'manual_close_time')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->time('manual_close_time')->nullable();
            });
        }

        if (!Schema::hasColumn('restaurants', 'is_temporarily_closed')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('is_temporarily_closed')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('restaurant_manual_open_close_type');
        });

        if (Schema::hasColumn('restaurants', 'is_temporarily_closed')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('is_temporarily_closed');
            });
        }

        if (Schema::hasColumn('restaurants', 'manual_close_time')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('manual_close_time');
            });
        }

        if (Schema::hasColumn('restaurants', 'manual_open_time')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('manual_open_time');
            });
        }

        if (Schema::hasColumn('restaurants', 'restaurant_manual_open_close_type')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('restaurant_manual_open_close_type');
            });
        }

        if (Schema::hasColumn('restaurants', 'restaurant_open_close_mode')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('restaurant_open_close_mode');
            });
        }
    }
};
