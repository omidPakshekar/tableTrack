<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('delivery_executives') || !Schema::hasColumn('delivery_executives', 'status')) {
            return;
        }

        DB::statement(
            "ALTER TABLE delivery_executives MODIFY status ENUM('available', 'active', 'on_delivery', 'inactive') NOT NULL DEFAULT 'available'"
        );

        DB::table('delivery_executives')
            ->where('status', 'available')
            ->update(['status' => 'active']);

        DB::statement(
            "ALTER TABLE delivery_executives MODIFY status ENUM('active', 'on_delivery', 'inactive') NOT NULL DEFAULT 'active'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('delivery_executives') || !Schema::hasColumn('delivery_executives', 'status')) {
            return;
        }

        DB::statement(
            "ALTER TABLE delivery_executives MODIFY status ENUM('available', 'active', 'on_delivery', 'inactive') NOT NULL DEFAULT 'active'"
        );

        DB::table('delivery_executives')
            ->where('status', 'active')
            ->update(['status' => 'available']);

        DB::statement(
            "ALTER TABLE delivery_executives MODIFY status ENUM('available', 'on_delivery', 'inactive') NOT NULL DEFAULT 'available'"
        );
    }
};
