<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\DeliveryPlatform;

class DeliveryPlatformObserver
{
    /**
     * Handle the DeliveryPlatform "creating" event.
     */
    public function creating(DeliveryPlatform $deliveryPlatform): void
    {
        if (branch()) {
            $deliveryPlatform->branch_id = branch()->id;
        }
    }

    public function created(DeliveryPlatform $deliveryPlatform): void
    {
        $this->forgetDeliveryPlatformsCache($deliveryPlatform);
    }

    public function updated(DeliveryPlatform $deliveryPlatform): void
    {
        $this->forgetDeliveryPlatformsCache($deliveryPlatform);
    }

    public function deleted(DeliveryPlatform $deliveryPlatform): void
    {
        $this->forgetDeliveryPlatformsCache($deliveryPlatform);
    }

    private function forgetDeliveryPlatformsCache(DeliveryPlatform $deliveryPlatform): void
    {
        $branchId = $deliveryPlatform->branch_id;
        if (!$branchId) {
            return;
        }

        $restaurantId = Branch::where('id', $branchId)->value('restaurant_id');
        if (!$restaurantId) {
            return;
        }

        cache()->forget('delivery_platforms_' . $restaurantId);
        cache()->forget('delivery_platforms_' . $restaurantId . '_' . $branchId);
    }
}
