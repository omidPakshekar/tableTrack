<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSuccess
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ?int $restaurant_id = null,
        public ?int $branch_id = null,
        public ?string $gateway = null,
        public ?string $transaction_id = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?int $order_id = null,
    ) {}
}
