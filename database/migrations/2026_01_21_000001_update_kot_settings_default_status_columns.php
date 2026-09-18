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
        if (Schema::hasColumn('kot_settings', 'default_status')) {
            Schema::table('kot_settings', function (Blueprint $table) {

                \Illuminate\Support\Facades\DB::statement("ALTER TABLE kot_settings
CHANGE default_status default_status_pos ENUM('pending','cooking') DEFAULT 'pending'");
            });
        }

        if (!Schema::hasColumn('kot_settings', 'default_status_customer')) {
            Schema::table('kot_settings', function (Blueprint $table) {
                $table->enum('default_status_customer', ['pending', 'cooking'])
                    ->default('pending')
                    ->after('default_status_pos');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('kot_settings', 'default_status_customer')) {
            Schema::table('kot_settings', function (Blueprint $table) {
                $table->dropColumn('default_status_customer');
            });
        }

        if (Schema::hasColumn('kot_settings', 'default_status_pos')) {
            Schema::table('kot_settings', function (Blueprint $table) {
                $table->renameColumn('default_status_pos', 'default_status');
            });
        }
    }
};
