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
            if (!Schema::hasTable('menu_table')) {
                return;
            }

            // Case 1: old column exists → rename (keeps data)
            if (Schema::hasColumn('menu_table', 'isactive') && !Schema::hasColumn('menu_table', 'is_active')) {
                Schema::table('menu_table', function (Blueprint $table) {
                    $table->renameColumn('isactive', 'is_active');
                });
            }

            // Case 2: neither exists → create new
            if (!Schema::hasColumn('menu_table', 'is_active')) {
                Schema::table('menu_table', function (Blueprint $table) {
                    $table->boolean('is_active')->default(true)->after('menu_id');
                });
            }
        }

        public function down(): void
        {
            if (!Schema::hasTable('menu_table')) {
                return;
            }

            // Rollback: remove is_active
            if (Schema::hasColumn('menu_table', 'is_active')) {
                Schema::table('menu_table', function (Blueprint $table) {
                    $table->dropColumn('is_active');
                });
            }
        }
};
