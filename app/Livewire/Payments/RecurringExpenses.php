<?php

namespace App\Livewire\Payments;

use App\Models\ExpensesRecurring;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class RecurringExpenses extends Component
{
    use WithPagination, LivewireAlert;

    public $search = '';

    public $showEditModal = false;

    public $confirmDelete = false;

    public $selectedId;

    public $deleteId;

    public function mount(): void
    {
        abort_if(!in_array('Expense', restaurant_modules()), 403);
        abort_if(!user_can('Show Expense'), 403);
    }

    #[On('recurringExpenseAdded')]
    #[On('recurringExpenseUpdated')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function showEdit(int $id): void
    {
        abort_if(!user_can('Update Expense'), 403);
        $this->selectedId = $id;
        $this->showEditModal = true;
    }

    public function showDelete(int $id): void
    {
        abort_if(!user_can('Delete Expense'), 403);
        $this->deleteId = $id;
        $this->confirmDelete = true;
    }

    public function deleteConfirmed(): void
    {
        if ($this->deleteId) {
            ExpensesRecurring::findOrFail($this->deleteId)->delete();
            $this->confirmDelete = false;
            $this->deleteId = null;
            $this->alert('success', __('messages.expenseDeleted'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close'),
            ]);
        }
    }

    #[On('hideEditRecurringExpense')]
    public function hideEditRecurringExpense(): void
    {
        $this->showEditModal = false;
        $this->selectedId = null;
    }

    public function render()
    {
        $query = ExpensesRecurring::query()->with('category');

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('item_name', 'like', $s)
                    ->orWhere('amount', 'like', $s)
                    ->orWhere('description', 'like', $s)
                    ->orWhere('rotation', 'like', $s)
                    ->orWhereHas('category', fn ($q2) => $q2->where('name', 'like', $s));
            });
        }

        $rows = $query->orderByDesc('id')->paginate(10);

        return view('livewire.payments.recurring-expenses', [
            'rows' => $rows,
        ]);
    }
}
