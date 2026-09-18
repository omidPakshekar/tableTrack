<?php

use App\Models\NotificationSetting;
use App\Models\Restaurant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $restaurantIds = Restaurant::query()->pluck('id');

        foreach ($restaurantIds as $restaurantId) {
            NotificationSetting::firstOrCreate(
                [
                    'type' => 'reservation_submitted',
                    'restaurant_id' => $restaurantId,
                ],
                [
                    'send_email' => 1,
                ]
            );
        }
    }

    public function down(): void
    {
        NotificationSetting::where('type', 'reservation_submitted')->delete();
    }
};
