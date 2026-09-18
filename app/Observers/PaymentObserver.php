<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\OrderCashCollectionService;

class PaymentObserver
{
    public function __construct(private readonly OrderCashCollectionService $orderCashCollectionService)
    {
    }

    public function creating(Payment $payment)
    {
        if (branch()) {
            $payment->branch_id = branch()->id;
        }
    }

    public function created(Payment $payment): void
    {
        if ($payment->relationLoaded('order')) {
            $this->orderCashCollectionService->syncForOrder($payment->order);
            return;
        }

        if ($payment->order) {
            $this->orderCashCollectionService->syncForOrder($payment->order);
        }
    }

    public function updated(Payment $payment): void
    {
        if ($payment->order) {
            $this->orderCashCollectionService->syncForOrder($payment->order);
        }
    }

    public function deleted(Payment $payment): void
    {
        if ($payment->order) {
            $this->orderCashCollectionService->syncForOrder($payment->order);
        }
    }
}
