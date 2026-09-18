<?php

namespace App\Livewire\Forms;

use App\Models\Table;
use App\Services\Tables\TablesIndexCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\Attributes\On;

class UpdateWaiterToTableForm extends Component
{
    use LivewireAlert;

    public $tableId;
    public $assignment_id = null;
    public $waiter_id = null;
    public $backup_waiter_id = null;
    public $is_active = 1;
    public $effective_from = null;
    public $effective_to = null;
    public $waiters = [];

    public function mount($tableId = null, $assignmentId = null)
    {
        $this->tableId = $tableId;
        $this->assignment_id = $assignmentId;
        $this->loadWaiters();
        $this->loadAssignment();
    }

    public function loadWaiters()
    {
        $this->waiters = \App\Services\Pos\PosWaitersCache::remember((int) restaurant()->id, (int) branch()->id);
    }

    public function loadAssignment()
    {
        if (!$this->tableId) {
            return;
        }

        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Get the latest assignment for the table
        $existingAssignment = DB::table('assign_waiter_to_tables')
            ->where('table_id', $this->tableId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($existingAssignment) {
            $this->assignment_id = $existingAssignment->id;
            $this->waiter_id = $existingAssignment->waiter_id;
            $this->backup_waiter_id = $existingAssignment->backup_waiter_id;
            $this->is_active = $existingAssignment->is_active ? 1 : 0;

            // Convert dates from database format to restaurant format
            try {
                $this->effective_from = Carbon::createFromFormat('Y-m-d', $existingAssignment->effective_from)->displayDate($dateFormat);
            } catch (\Exception $e) {
                $this->effective_from = now()->displayDate($dateFormat);
            }

            if ($existingAssignment->effective_to) {
                try {
                    $this->effective_to = Carbon::createFromFormat('Y-m-d', $existingAssignment->effective_to)->displayDate($dateFormat);
                } catch (\Exception $e) {
                    $this->effective_to = null;
                }
            } else {
                $this->effective_to = null;
            }
        } else {
            // Initialize with default values if no assignment exists
            $this->initializeForm();
        }
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

    public function update()
    {
        // Convert is_active to boolean if it's a string
        $this->is_active = filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN);

        // Validate form data
        $this->validate([
            'assignment_id' => 'required|exists:assign_waiter_to_tables,id',
            'waiter_id' => 'required|exists:users,id',
            'backup_waiter_id' => 'nullable|exists:users,id',
            'is_active' => 'required|boolean',
            'effective_from' => 'required',
            'effective_to' => 'nullable',
        ], [
            'assignment_id.required' => __('messages.assignmentNotFound'),
            'assignment_id.exists' => __('messages.assignmentNotFound'),
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

        // Verify assignment belongs to the selected table
        $assignment = DB::table('assign_waiter_to_tables')
            ->where('id', $this->assignment_id)
            ->where('table_id', $this->tableId)
            ->first();

        if (!$assignment) {
            $this->alert('error', __('messages.assignmentNotFound'), [
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

        // Update the assignment
        DB::table('assign_waiter_to_tables')
            ->where('id', $this->assignment_id)
            ->update([
                'waiter_id' => $this->waiter_id,
                'backup_waiter_id' => $this->backup_waiter_id,
                'is_active' => $this->is_active,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'updated_at' => now(),
            ]);

        TablesIndexCache::forgetForBranch((int) branch()->id);

        $this->alert('success', __('messages.waiterAssignmentUpdatedSuccessfully'), [
            'toast' => true,
            'position' => 'top-end'
        ]);

        // Dispatch event to refresh parent component
        $this->dispatch('waiterUpdated');
        $this->dispatch('refreshTables');
    }

    #[On('confirmUpdate')]
    public function handleUpdate()
    {
        $this->update();
    }

    #[On('resetForm')]
    public function resetForm()
    {
        $this->loadAssignment();
    }

    public function render()
    {
        return view('livewire.forms.update-waiter-to-table-form');
    }
}

