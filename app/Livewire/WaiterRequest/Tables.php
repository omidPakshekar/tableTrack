<?php

namespace App\Livewire\WaiterRequest;

use Livewire\Component;
use App\Models\Area;
use App\Models\Table;
use App\Models\WaiterRequest;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Illuminate\Support\Facades\DB;

class Tables extends Component
{
    use LivewireAlert;

    protected $listeners = [
        'waiterRequestCreated' => 'render',
        'refreshWaiterRequests' => '$refresh',
    ];

    public $pollingEnabled = true;
    public $pollingInterval = 10;

    /**
     * Get table IDs assigned to the current waiter
     * Returns null if user is not a waiter (shows all requests)
     */
    private function getAssignedTableIds()
    {
        $user = user();

        // Check if current user is a waiter
        if (!$user || !$user->hasRole('Waiter_' . $user->restaurant_id)) {
            return null; // Not a waiter, return null to show all requests
        }

        $today = now()->format('Y-m-d');

        // Get table IDs assigned to this waiter
        $assignedTableIds = DB::table('assign_waiter_to_tables')
            ->where('waiter_id', $user->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->pluck('table_id')
            ->toArray();

        return $assignedTableIds;
    }

    public function mount()
    {
        // Load polling settings from cookies
        $this->pollingEnabled = filter_var(request()->cookie('waiter_request_polling_enabled', 'true'), FILTER_VALIDATE_BOOLEAN);
        $this->pollingInterval = (int)request()->cookie('waiter_request_polling_interval', 10);
    }



    public function updatedPollingEnabled($value)
    {
        cookie()->queue(cookie('waiter_request_polling_enabled', $value ? 'true' : 'false', 60 * 24 * 30)); // 30 days
    }

    public function updatedPollingInterval($value)
    {
        cookie()->queue(cookie('waiter_request_polling_interval', (int)$value, 60 * 24 * 30)); // 30 days
    }



    public function showTableOrder($id)
    {
        return $this->redirect(route('pos.show', $id));
    }

    public function showTableOrderDetail($id)
    {
        return $this->redirect(route('pos.order', [$id]));
    }

    public function markCompleted($id)
    {
        $waiterRequest = WaiterRequest::findOrFail($id);

        // Check if waiter can access this table
        $assignedTableIds = $this->getAssignedTableIds();
        if ($assignedTableIds !== null && !in_array($waiterRequest->table_id, $assignedTableIds)) {
            $this->alert('error', __('messages.unauthorized'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
            return;
        }

        WaiterRequest::where('table_id', $waiterRequest->table_id)->update(['status' => 'completed']);

        // Count waiter requests with filtering
        $countQuery = WaiterRequest::where('status', 'pending');
        if ($assignedTableIds !== null) {
            if (empty($assignedTableIds)) {
                $count = 0;
            } else {
                $count = $countQuery->whereIn('table_id', $assignedTableIds)->distinct('table_id')->count();
            }
        } else {
            $count = $countQuery->distinct('table_id')->count();
        }
        session(['active_waiter_requests_count' => $count]);

        $this->dispatch('$refresh');

        $this->alert('success', __('messages.waiterRequestCompleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

    }

    public function render()
    {
        $assignedTableIds = $this->getAssignedTableIds();

        $query = Area::with(['tables' => function ($query) use ($assignedTableIds) {
            $query->whereHas('activeWaiterRequest');

            // If user is a waiter, filter by assigned tables
            if ($assignedTableIds !== null) {
                if (empty($assignedTableIds)) {
                    // Waiter has no assigned tables, return empty result
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('id', $assignedTableIds);
                }
            }

            return $query;
        }, 'tables.waiterRequests', 'tables.activeOrder']);

        $query = $query->get();

        // Filter out areas that have no tables after filtering
        $query = $query->filter(function ($area) {
            return $area->tables->isNotEmpty();
        });

        return view('livewire.waiter-request.tables', [
            'tables' => $query,
            'areas' => Area::get()
        ]);
    }
}
