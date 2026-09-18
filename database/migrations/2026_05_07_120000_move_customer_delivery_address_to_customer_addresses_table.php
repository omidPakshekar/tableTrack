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
        if (
            !Schema::hasTable('customers') ||
            !Schema::hasTable('customer_addresses') ||
            !Schema::hasColumn('customers', 'delivery_address')
        ) {
            return;
        }

        $now = now();

        DB::table('customers')
            ->select('id', 'delivery_address')
            ->whereNotNull('delivery_address')
            ->where('delivery_address', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($customers) use ($now) {

                $rows = [];

                foreach ($customers as $customer) {
                    $alreadyExists = DB::table('customer_addresses')
                        ->where('customer_id', $customer->id)
                        ->where('address', $customer->delivery_address)
                        ->exists();

                    if ($alreadyExists) {
                        continue;
                    }

                    $rows[] = [
                        'customer_id' => $customer->id,
                        'label' => 'Home',
                        'address' => $customer->delivery_address,
                        'lat' => null,
                        'lng' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (!empty($rows)) {
                    DB::table('customer_addresses')->insert($rows);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};