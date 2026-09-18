<?php

namespace App\Livewire\Payments;

use App\Models\Payment;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use App\Helper\Common;

class PaymentsTable extends Component
{

    use WithPagination, WithoutUrlPagination;

    public $search;
    public $showRefundModal = false;
    public $selectedPayment = null;

    protected $listeners = ['refreshPayments' => '$refresh'];

    public function openRefundModal($paymentId)
    {
        $payment = Payment::with(['order.deliveryPlatform', 'refunds'])->find($paymentId);
        
        // Check if payment already has a processed refund
        if ($payment && $payment->refunds()->where('status', 'processed')->exists()) {
            return; // Don't open modal if already refunded
        }
        
        $this->selectedPayment = $payment;
        $this->showRefundModal = true;
    }

    public function closeRefundModal()
    {
        $this->showRefundModal = false;
        $this->selectedPayment = null;
    }

    #[On('refundProcessed')]
    public function handleRefundProcessed()
    {
        $this->closeRefundModal();
        $this->dispatch('refreshPayments');
    }

    #[On('closeRefundModal')]
    public function handleCloseRefundModal()
    {
        $this->closeRefundModal();
    }

    public function render()
    {


        $query = Payment::with(['order:id,order_number', 'refunds'])
            ->where('payment_method', '<>', 'due')
            ->where(function ($q) {

                $safeTerm = Common::safeString($this->search);

                return $q->where('amount', 'like', '%' . $safeTerm . '%')
                    ->orWhere('transaction_id', 'like', '%' . $safeTerm . '%')
                    ->orWhere('payment_method', 'like', '%' . $safeTerm . '%')
                    ->orWhereHas('order', function ($q) use ($safeTerm) {
                        $q->where('order_number', 'like', '%' . $safeTerm . '%');
                    });
            })
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.payments.payments-table', [
            'payments' => $query
        ]);
    }

    public function hasRefund($payment)
    {
        return $payment->refunds()->where('status', 'processed')->exists();
    }
}
