<?php

use App\Models\Branch;
use App\Models\ReceiptSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('restaurant_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade')->onUpdate('cascade');
        });

        // Create receipt settings for existing branches that don't have one yet
        Branch::withoutGlobalScopes()->each(function (Branch $branch) {
            $exists = ReceiptSetting::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->exists();

            if (!$exists) {
                // Try to copy from existing restaurant-level receipt setting
                $restaurantSetting = ReceiptSetting::withoutGlobalScopes()
                    ->where('restaurant_id', $branch->restaurant_id)
                    ->whereNull('branch_id')
                    ->first();

                if ($restaurantSetting) {
                    $data = $restaurantSetting->toArray();
                    unset($data['id'], $data['created_at'], $data['updated_at']);
                    $data['branch_id'] = $branch->id;
                    ReceiptSetting::withoutGlobalScopes()->create($data);
                } else {
                    ReceiptSetting::withoutGlobalScopes()->create([
                        'restaurant_id' => $branch->restaurant_id,
                        'branch_id'     => $branch->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('receipt_settings', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
