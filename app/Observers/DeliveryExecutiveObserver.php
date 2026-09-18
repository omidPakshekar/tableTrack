<?php

namespace App\Observers;

use App\Models\DeliveryExecutive;
use Illuminate\Support\Str;

class DeliveryExecutiveObserver
{

    public function creating(DeliveryExecutive $deliveryExecutive)
    {
        if (branch()) {
            $deliveryExecutive->branch_id = branch()->id;
        }
    }

    public function created(DeliveryExecutive $deliveryExecutive)
    {
        if (!$deliveryExecutive->unique_code) {
            $deliveryExecutive->unique_code = strtoupper(Str::random(2)) . 'TT' . $deliveryExecutive->id;
            $deliveryExecutive->saveQuietly();
        }
    }
}
