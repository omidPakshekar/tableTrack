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
        if (!Schema::hasTable('delivery_executives') || !Schema::hasColumn('delivery_executives', 'is_online')) {
            return;
        }

        DB::table('delivery_executives')
            ->whereIn('status', ['available', 'on_delivery'])
            ->update(['is_online' => 1]);

        DB::table('delivery_executives')
            ->whereNotIn('status', ['available', 'on_delivery'])
            ->update(['is_online' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time data backfill. No reversal needed.
    }
};
