<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('restaurants')
            ->where('default_table_reservation_status', 'Confirmed')
            ->update(['default_table_reservation_status' => 'Pending']);
    }

    public function down(): void
    {
        // No rollback — restaurants may have intentionally set Pending.
    }
};
