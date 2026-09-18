<?php

use App\Models\NotificationSetting;
use App\Models\Restaurant;
use App\Scopes\RestaurantScope;
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
        $checkCount = Restaurant::withoutGlobalScope(RestaurantScope::class)->count();

        if ($checkCount == 0) {
            return;
        }

        $restaurants = Restaurant::withoutGlobalScope(RestaurantScope::class)->select('id')->get();

        foreach ($restaurants as $restaurant) {
            // Check if menu_pdf_sent notification already exists for this restaurant
            $exists = NotificationSetting::withoutGlobalScope(RestaurantScope::class)
                ->where('type', 'menu_pdf_sent')
                ->where('restaurant_id', $restaurant->id)
                ->exists();

            if (!$exists) {
                NotificationSetting::withoutGlobalScope(RestaurantScope::class)->insert([
                    [
                        'type' => 'menu_pdf_sent',
                        'send_email' => 0,
                        'send_time' => null,
                        'restaurant_id' => $restaurant->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove menu_pdf_sent notification settings
        NotificationSetting::withoutGlobalScope(RestaurantScope::class)
            ->where('type', 'menu_pdf_sent')
            ->delete();
    }
};
