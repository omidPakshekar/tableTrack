<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Table;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderTableAssigned
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order, 
        public Table $newTable, 
        public ?Table $previousTable = null
    ) {
        //
    }
}
