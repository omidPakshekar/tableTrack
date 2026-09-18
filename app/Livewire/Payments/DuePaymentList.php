<?php

namespace App\Livewire\Payments;

use App\Exports\DuePaymentExport;
use App\Models\Payment;
use App\Models\Customer;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class DuePaymentList extends Component
{

    public $search;
    public $filterCustomer;

    protected $listeners = ['refreshPayments' => '$refresh'];

    public function mount()
    {
        abort_if(!in_array('Payment', restaurant_modules()), 403);
        abort_if((!user_can('Show Payments')), 403);
    }

    public function updatedFilterCustomer()
    {
        $this->dispatch('customerFilterUpdated', customerId: $this->filterCustomer);
    }

    public function exportDuePayments()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
        }
        else {
            return Excel::download(
                new DuePaymentExport($this->search, $this->filterCustomer),
                'due-payments-' . now()->toDateTimeString() . '.xlsx'
            );
        }
    }

    public function render()
    {
        $query = Payment::where('payment_method', 'due')->sum('amount');

        // Get all customers who have due payments
        $customers = Customer::where('restaurant_id', restaurant()->id)
            ->whereHas('orders.payments', function($q) {
                $q->where('payment_method', 'due');
            })
            ->orderBy('name')
            ->get();

        return view('livewire.payments.due-payment-list', [
            'dueTotal' => $query,
            'customers' => $customers
        ]);
    }

}
