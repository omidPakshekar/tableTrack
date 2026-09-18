<?php

namespace App\Livewire\Dashboard;

use App\Models\WaiterRequest;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ActiveWaiterRequests extends Component
{

    use LivewireAlert;

    protected $listeners = ['attended' => 'attended', 'newWaiterRequest' => 'handleNewWaiterRequest', 'waiterRequestCreated' => 'handleNewWaiterRequest'];

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

        // Get table IDs assigned to this waiter (as primary waiter or backup waiter)
        $assignedTableIds = DB::table('assign_waiter_to_tables')
            ->where(function ($query) use ($user) {
                $query->where('waiter_id', $user->id)
                    ->orWhere(function ($q) use ($user) {
                        $q->whereNull('waiter_id')
                            ->where('backup_waiter_id', $user->id);
                    });
            })
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

    /**
     * Build query for waiter requests with waiter filtering
     */
    private function getWaiterRequestQuery()
    {
        $query = WaiterRequest::where('status', 'pending');

        $assignedTableIds = $this->getAssignedTableIds();

        // If user is a waiter, filter by assigned tables
        if ($assignedTableIds !== null) {
            // If waiter has no assigned tables, return empty result
            if (empty($assignedTableIds)) {
                $query->whereRaw('1 = 0'); // Force empty result
            } else {
                $query->whereIn('table_id', $assignedTableIds);
            }
        }

        return $query;
    }

    public function mount()
    {
        if (!session()->has('active_waiter_requests_count')) {
            $count = $this->getWaiterRequestQuery()->distinct('table_id')->count();
            session(['active_waiter_requests_count' => $count]);
        }
    }

    public function handleNewWaiterRequest($data = null)
    {
        $recentRequest = $this->getWaiterRequestQuery()->latest()->first();

            if ($recentRequest) {
                // Play sound immediately
                $this->js('new Audio("' . asset('sound/new_order.wav') . '").play()');

                $this->confirm(__('modules.waiterRequest.newWaiterRequestForTable', ['name' => $recentRequest->table->table_code]), [
                    'position' => 'center',
                    'confirmButtonText' => __('modules.waiterRequest.markCompleted'),
                    'confirmButtonColor' => '#16a34a',
                    'onConfirmed' => 'attended',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('modules.waiterRequest.doItLater'),
                    'onCanceled' => 'doItLater',
                    'data' => [
                        'tableID' => $recentRequest->table_id
                    ]
                ]);
            }

        $count = $this->getWaiterRequestQuery()->distinct('table_id')->count();
        session(['active_waiter_requests_count' => $count]);

        $this->dispatch('$refresh');
    }

    public function attended($data)
    {
        WaiterRequest::where('table_id', $data['tableID'])->update(['status' => 'completed']);

        $count = $this->getWaiterRequestQuery()->distinct('table_id')->count();
        session(['active_waiter_requests_count' => $count]);

        $this->dispatch('$refresh');
    }

    public function doItLater()
    {
        return $this->redirect(route('waiter-requests.index'), navigate: true);
    }

    public function render()
    {
        $count = $this->getWaiterRequestQuery()->distinct('table_id')->count();

        if (session()->has('active_waiter_requests_count') && session('active_waiter_requests_count') < $count) {

            $recentRequest = $this->getWaiterRequestQuery()->latest()->first();

            if ($recentRequest) {
                // Play sound immediately
                $this->js('new Audio("' . asset('sound/new_order.wav') . '").play()');

                $this->confirm(__('modules.waiterRequest.newWaiterRequestForTable', ['name' => $recentRequest->table->table_code]), [
                    'position' => 'center',
                    'confirmButtonText' => __('modules.waiterRequest.markCompleted'),
                    'confirmButtonColor' => '#16a34a',
                    'onConfirmed' => 'attended',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('modules.waiterRequest.doItLater'),
                    'onCanceled' => 'doItLater',
                    'data' => [
                        'tableID' => $recentRequest->table_id
                    ]
                ]);
            }

            session(['active_waiter_requests_count' => $count]);
        }

        return view('livewire.dashboard.active-waiter-requests', [
            'count' => $count
        ]);
    }

    public function refreshActiveWaiterRequests()
    {
        $count = $this->getWaiterRequestQuery()->distinct('table_id')->count();

        // Check if there's a new waiter request
        if (session()->has('active_waiter_requests_count') && session('active_waiter_requests_count') < $count) {
            $recentRequest = $this->getWaiterRequestQuery()->latest()->first();

            if ($recentRequest) {
                // Play sound immediately
                $this->js('new Audio("' . asset('sound/new_order.wav') . '").play()');

                $this->confirm(__('modules.waiterRequest.newWaiterRequestForTable', ['name' => $recentRequest->table->table_code]), [
                    'position' => 'center',
                    'confirmButtonText' => __('modules.waiterRequest.markCompleted'),
                    'confirmButtonColor' => '#16a34a',
                    'onConfirmed' => 'attended',
                    'showCancelButton' => true,
                    'cancelButtonText' => __('modules.waiterRequest.doItLater'),
                    'onCanceled' => 'doItLater',
                    'data' => [
                        'tableID' => $recentRequest->table_id
                    ]
                ]);
            }

            session(['active_waiter_requests_count' => $count]);
        }

        $this->dispatch('$refresh');
    }
}
