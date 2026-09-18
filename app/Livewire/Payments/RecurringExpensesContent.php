<?php

namespace App\Livewire\Payments;

use Livewire\Attributes\On;
use Livewire\Component;

class RecurringExpensesContent extends Component
{
    public $search = '';

    public $showAddRecurringExpense = false;

    #[On('hideAddRecurringExpense')]
    public function hideAddRecurringExpense(): void
    {
        $this->showAddRecurringExpense = false;
    }

    public function render()
    {
        return view('livewire.payments.recurring-expenses-content');
    }
}
