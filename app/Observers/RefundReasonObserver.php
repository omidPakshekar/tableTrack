<?php

namespace App\Observers;

use App\Models\RefundReason;

class RefundReasonObserver
{

    public function creating(RefundReason $table)
    {
        if (branch()) {
            $table->branch_id = branch()->id;
        }
    }

}
