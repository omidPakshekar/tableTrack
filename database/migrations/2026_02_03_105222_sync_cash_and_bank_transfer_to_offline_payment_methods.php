<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Restaurant;
use App\Models\OfflinePaymentMethod;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get only restaurant IDs and eager load payment gateway with required fields
        $restaurants = Restaurant::select('id')
            ->with([
                'paymentGateways' => function ($query) {
                    $query->select('id', 'restaurant_id', 'is_cash_payment_enabled', 'is_offline_payment_enabled', 'offline_payment_detail');
                }
            ])->get();

        foreach ($restaurants as $restaurant) {
            $gateway = $restaurant->paymentGateways;
            
            OfflinePaymentMethod::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => 'cash',
                    'description' => null,
                    'status' => $gateway && $gateway->is_cash_payment_enabled ? 'active' : 'inactive',
                ]
            );

            OfflinePaymentMethod::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => 'bank_transfer',
                    'description' => $gateway ? $gateway->offline_payment_detail : null,
                    'status' => $gateway && $gateway->is_offline_payment_enabled ? 'active' : 'inactive',
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove cash and bank_transfer methods for all restaurants
        OfflinePaymentMethod::whereIn('name', ['cash', 'bank_transfer'])->delete();
    }
};
