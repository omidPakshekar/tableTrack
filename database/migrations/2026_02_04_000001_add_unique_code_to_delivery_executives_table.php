<?php

use App\Models\DeliveryExecutive;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('delivery_executives', 'unique_code')) {
            Schema::table('delivery_executives', function (Blueprint $table) {
                $table->string('unique_code')->nullable()->after('phone');
            });
        }

        // UNIQUE CODE FOR EXISTING DELIVERY EXECUTIVES with uppercase
        DeliveryExecutive::query()
            ->whereNull('unique_code')
            ->chunkById(100, function ($deliveryExecutives) {
                foreach ($deliveryExecutives as $deliveryExecutive) {
                    $deliveryExecutive->unique_code = strtoupper(Str::random(4)) . $deliveryExecutive->id;
                    $deliveryExecutive->saveQuietly();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('delivery_executives', 'unique_code')) {
            Schema::table('delivery_executives', function (Blueprint $table) {
                $table->dropColumn('unique_code');
            });
        }
    }
};
