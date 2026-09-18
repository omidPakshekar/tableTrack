<?php

namespace App\Livewire\Forms;

use App\Helper\Files;
use App\Models\ExpenseCategory;
use App\Models\Expenses;
use App\Models\ExpensesRecurring;
use Carbon\Carbon;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class AddRecurringExpense extends Component
{
    use WithFileUploads, LivewireAlert;

    public $item_name = '';

    public $expense_category_id;

    public $amount;

    public $payment_method;

    public $rotation = 'daily';

    public $billing_cycle;

    public $issue_date;

    public $next_expense_date;

    public $unlimited_recurring = false;

    public $immediate_expense = false;

    public $status = 'active';

    public $description;

    public $bill;

    public $showExpenseCategoryModal = false;

    public $paymentMethods = [
        'cash' => 'modules.expenses.methods.cash',
        'bank_transfer' => 'modules.expenses.methods.bank_transfer',
        'credit_card' => 'modules.expenses.methods.credit_card',
        'debit_card' => 'modules.expenses.methods.debit_card',
        'check' => 'modules.expenses.methods.check',
        'digital_wallet' => 'modules.expenses.methods.digital_wallet',
    ];

    public function mount(): void
    {
        $this->issue_date = Carbon::now(timezone())->displayDate();
        $this->recalculateNextExpenseDate();
    }

    public function updatedRotation(): void
    {
        $this->recalculateNextExpenseDate();
    }

    public function updatedIssueDate(): void
    {
        $this->recalculateNextExpenseDate();
    }

    #[On('hideExpenseCategoryModal')]
    public function hideExpenseCategoryModal(): void
    {
        $this->showExpenseCategoryModal = false;
    }

    private function recalculateNextExpenseDate(): void
    {
        $issueYmd = $this->parseRestaurantDate($this->issue_date, 'issue_date');

        if (!$issueYmd) {
            $this->next_expense_date = null;
            return;
        }

        $temp = new ExpensesRecurring(['rotation' => $this->rotation ?: 'daily']);
        $nextYmd = $temp->computeNextExpenseDate(Carbon::parse($issueYmd))->format('Y-m-d');

        $this->next_expense_date = Carbon::parse($nextYmd)->displayDate();
    }

    private function parseRestaurantDate(?string $date, string $field): ?string
    {
        if (!$date) {
            return null;
        }

        $format = dateFormat();
        $tz = timezone();

        try {
            return Carbon::createFromFormat($format, $date, $tz)->format('Y-m-d');
        } catch (\Exception $e) {
            try {
                return Carbon::parse($date, $tz)->format('Y-m-d');
            } catch (\Exception $e2) {
                $this->addError($field, __('validation.date', [
                    'attribute' => __('modules.expenses.' . $field),
                ]));

                return null;
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'item_name' => 'required|string|max:191',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
            'rotation' => 'required|in:' . implode(',', ExpensesRecurring::ROTATIONS),
            'billing_cycle' => 'nullable|integer|min:1',
            'issue_date' => 'required',
            'unlimited_recurring' => 'boolean',
            'immediate_expense' => 'boolean',
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string|max:5000',
            'bill' => 'nullable|file|max:5120',
        ]);

        $issueYmd = $this->parseRestaurantDate($this->issue_date, 'issue_date');

        if (!$issueYmd || $this->getErrorBag()->hasAny(['issue_date'])) {
            return;
        }

        $issueCarbon = Carbon::parse($issueYmd);
        $temp = new ExpensesRecurring(['rotation' => $this->rotation]);
        $computedNext = $temp->computeNextExpenseDate($issueCarbon)->format('Y-m-d');

        $data = [
            'item_name' => $this->item_name,
            'expense_category_id' => $this->expense_category_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'rotation' => $this->rotation,
            'billing_cycle' => $this->billing_cycle,
            'issue_date' => $issueYmd,
            'next_expense_date' => $computedNext,
            'unlimited_recurring' => (bool) $this->unlimited_recurring,
            // Always true now: first expense is created immediately on save.
            'immediate_expense' => true,
            'status' => $this->status,
            'description' => $this->description,
        ];

        $recurring = ExpensesRecurring::create($data);

        if ($this->bill) {
            $path = Files::uploadLocalOrS3($this->bill, 'expense');
            $recurring->update(['bill' => $path]);
        }

        // Create the first expense immediately on issue_date (idempotent guard).
        $alreadyExists = Expenses::query()
            ->where('expenses_recurring_id', $recurring->id)
            ->whereDate('expense_date', $issueYmd)
            ->exists();

        if (!$alreadyExists) {
            Expenses::create([
                'expense_title' => $this->item_name,
                'expense_category_id' => $this->expense_category_id,
                'amount' => $this->amount,
                'expense_date' => $issueYmd,
                'payment_status' => 'pending',
                'payment_date' => $issueYmd,
                'payment_due_date' => $issueYmd,
                'payment_method' => $this->payment_method,
                'description' => $this->description,
                'expenses_recurring_id' => $recurring->id,
            ]);
        }

        $this->resetExcept('paymentMethods');
        $this->issue_date = Carbon::now(timezone())->displayDate();
        $this->rotation = 'daily';
        $this->status = 'active';
        $this->recalculateNextExpenseDate();

        $this->dispatch('hideAddRecurringExpense');
        $this->dispatch('recurringExpenseAdded');

        $this->alert('success', __('messages.expenseAdded'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close'),
        ]);
    }

    public function render()
    {
        return view('livewire.forms.add-recurring-expense', [
            'categories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }
}
