<?php

namespace App\Observers;

use App\Models\ExpensesRecurring;

class ExpensesRecurringObserver
{
    public function creating(ExpensesRecurring $recurring): void
    {
        if (branch()) {
            $recurring->branch_id = branch()->id;
        }
    }
}
