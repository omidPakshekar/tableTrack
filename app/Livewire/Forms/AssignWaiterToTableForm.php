<?php

namespace App\Livewire\Forms;

use App\Models\Table;
use App\Services\Tables\TablesIndexCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\Attributes\On;

class AssignWaiterToTableForm extends Component
{
    use LivewireAlert;

    public $tableId;
    public $waiter_id = null;
    public $backup_waiter_id = null;
    public $is_active = 1;
    public $effective_from = null;
    public $effective_to = null;
    public $waiters = [];

    public function mount($tableId = null)
    {
        $this->tableId = $tableId;
        $this->loadWaiters();
        $this->initializeForm();
    }

    public function loadWaiters()
    {
        $this->waiters = \App\Services\Pos\PosWaitersCache::remember((int) restaurant()->id, (int) branch()->id);
    }

    public function initializeForm()
    {
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $this->is_active = 1;
        $this->effective_from = now()->displayDate($dateFormat);
        $this->effective_to = null;
        $this->waiter_id = null;
        $this->backup_waiter_id = null;
    }

    public function save()
    {
        // Convert is_active to boolean if it's a string
        $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN);

        // Validate form data
        $this->validate([
            'waiter_id' => 'required|exists:users,id',
            'backup_waiter_id' => 'nullable|exists:users,id',
            'is_active' => 'required|boolean',
            'effective_from' => 'required',
            'effective_to' => 'nullable',
        ], [
            'waiter_id.required' => __('messages.waiterRequired'),
            'waiter_id.exists' => __('messages.waiterNotFound'),
            'backup_waiter_id.exists' => __('messages.waiterNotFound'),
            'effective_from.required' => __('messages.effectiveFromRequired'),
        ]);

        $table = Table::find($this->tableId);

        if (!$table) {
            $this->alert('error', __('messages.tableNotFound'), [
                'toast' => true,
                'position' => 'top-end'
            ]);
            return;
        }

        // Convert dates from restaurant format to Y-m-d format
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        try {
            $effectiveFromDate = parseRestaurantDate($this->effective_from, $dateFormat);
            $effectiveFrom = $effectiveFromDate->format('Y-m-d');
        } catch (\Exception $e) {
            $this->alert('error', __('messages.invalidDateFormat'), [
                'toast' => true,
                'position' => 'top-end'
            ]);
            return;
        }

        $effectiveTo = null;
        if ($this->effective_to) {
            try {
                $effectiveToDate = parseRestaurantDate($this->effective_to, $dateFormat);

                // Validate that effective_to is after or equal to effective_from
                if ($effectiveToDate->lt($effectiveFromDate)) {
                    $this->alert('error', __('messages.effectiveToMustBeAfterEffectiveFrom'), [
                        'toast' => true,
                        'position' => 'top-end'
                    ]);
                    return;
                }

                $effectiveTo = $effectiveToDate->format('Y-m-d');
            } catch (\Exception $e) {
                $this->alert('error', __('messages.invalidDateFormat'), [
                    'toast' => true,
                    'position' => 'top-end'
                ]);
                return;
            }
        }

        // Save to assign_waiter_to_tables table
        DB::table('assign_waiter_to_tables')->insert([
            'table_id' => $this->tableId,
            'waiter_id' => $this->waiter_id,
            'backup_waiter_id' => $this->backup_waiter_id,
            'assigned_by' => user()->id,
            'is_active' => $this->is_active,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TablesIndexCache::forgetForBranch((int) branch()->id);

        $this->alert('success', __('messages.waiterAssignedSuccessfully'), [
            'toast' => true,
            'position' => 'top-end'
        ]);

        // Reset form
        $this->initializeForm();

        // Dispatch event to refresh parent component
        $this->dispatch('waiterAssigned');
        $this->dispatch('refreshTables');
    }

    #[On('saveWaiterAssignment')]
    public function handleSave()
    {
        $this->save();
    }

    #[On('resetForm')]
    public function resetForm()
    {
        $this->initializeForm();
    }

    public function render()
    {
        return view('livewire.forms.assign-waiter-to-table-form');
    }
}

