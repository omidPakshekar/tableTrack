<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Enums\OrderStatus;

return new class extends Migration
{
    /**
     * Before the "completed" order progress step, dine-in orders often stayed on order_status "served"
     * after payment. Align those finished orders with the new terminal state.
     */
    public function up(): void
    {
        
        DB::table('orders')
            ->whereDate('updated_at', '<', now()->subDays(1)->toDateString())
            ->update([
                'order_status' => OrderStatus::COMPLETED->value,
                'updated_at' => now(),
            ]);
       
    }

    /**
     * Cannot safely revert: native "completed" rows cannot be distinguished from migrated rows.
     */
    public function down(): void
    {
        //
    }
};
