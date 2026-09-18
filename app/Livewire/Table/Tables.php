<?php

namespace App\Livewire\Table;

use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use App\Services\Pos\PosWaitersCache;
use App\Services\Tables\TablesIndexCache;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;

class Tables extends Component
{
    use LivewireAlert;

    public $activeTable;

    public $showAddTableModal = false;

    public $showEditTableModal = false;

    public $confirmDeleteTableModal = false;

    public $showAssignWaiterModal = false;

    public $showUpdateWaiterModal = false;

    public $showUpdateConfirmationModal = false;

    public $selectedTableId = null;

    public $selectedTable = null;

    public $currentWaiter = null;

    public $existingAssignment = false;

    protected $listeners = [
        'refreshTables' => '$refresh',
    ];

    #[On('hideAddTable')]
    public function hideAddTable()
    {
        $this->showAddTableModal = false;
    }

    #[On('hideEditTable')]
    public function hideEditTable()
    {
        $this->showEditTableModal = false;
    }

    public function showEditTable($id)
    {
        $this->activeTable = Table::findOrFail($id);
        $this->showEditTableModal = true;
    }

    public function showTableOrder($id)
    {
        $table = Table::find($id);

        if ($table && ! $table->canBeAccessedByUser(user()->id)) {
            $session = $table->tableSession;
            $lockedByUser = $session?->lockedByUser;
            $lockedUserName = $lockedByUser?->name ?? 'Admin';

            $this->alert('error', __('messages.tableLockedByUser', ['user' => $lockedUserName]), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        return $this->redirect(route('pos.show', $id));
    }

    public function showTableOrderDetail($id)
    {
        return $this->redirect(route('pos.order', [$id]));
    }

    public function showSpecificOrder($orderId)
    {
        $order = Order::query()->find((int) $orderId);

        if (! $order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        return $this->redirect(route('pos.kot', ['id' => $order->id, 'show-order-detail' => 'true']));
    }

    public function newKotForSpecificOrder($orderId)
    {
        $order = Order::query()->find((int) $orderId);

        if (! $order) {
            $this->alert('error', __('messages.orderNotFound'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        return $this->redirect(route('pos.kot', ['id' => $order->id]));
    }

    public function forceUnlockTable($tableId)
    {
        $table = Table::find($tableId);

        if (! $table) {
            $this->alert('error', __('messages.tableNotFound'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $hasPermission = user()->hasRole('Admin_'.user()->restaurant_id) ||
                        ($table->tableSession && $table->tableSession->locked_by_user_id == user()->id);

        if (! $hasPermission) {
            $this->alert('error', __('messages.tableUnlockFailed'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $result = $table->unlock(null, true);

        $this->alert(
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? __('messages.tableUnlockedSuccess', ['table' => $table->table_code])
                : __('messages.tableUnlockFailed'),
            ['toast' => true, 'position' => 'top-end']
        );

        $this->dispatch('refreshTables');
    }

    public function showWaiterSelect($tableId)
    {
        $this->selectedTableId = $tableId;
        $this->selectedTable = Table::find($tableId);
        $this->currentWaiter = $this->selectedTable?->activeOrder?->waiter_id;

        $existingAssignment = DB::table('assign_waiter_to_tables')
            ->where('table_id', $tableId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($existingAssignment) {
            $this->existingAssignment = true;
            $this->showUpdateWaiterModal = true;
        } else {
            $this->existingAssignment = false;
            $this->showAssignWaiterModal = true;
        }
    }

    #[On('waiterAssigned')]
    public function handleWaiterAssigned()
    {
        $this->showAssignWaiterModal = false;
        $this->selectedTableId = null;
        $this->selectedTable = null;
        $this->currentWaiter = null;
    }

    #[On('waiterUpdated')]
    public function handleWaiterUpdated()
    {
        $this->showUpdateWaiterModal = false;
        $this->showUpdateConfirmationModal = false;
        $this->selectedTableId = null;
        $this->selectedTable = null;
        $this->currentWaiter = null;
    }

    public function showUpdateConfirmation()
    {
        $this->showUpdateWaiterModal = false;
        $this->showUpdateConfirmationModal = true;
    }

    public function confirmUpdateWaiter()
    {
        $this->showUpdateConfirmationModal = false;
        $this->showUpdateWaiterModal = true;
        $this->dispatch('confirmUpdate');
    }

    public function getCurrentWaiterUserProperty()
    {
        if ($this->currentWaiter) {
            return User::find($this->currentWaiter);
        }

        return null;
    }

    /**
     * @param  array{areas: array<int, array<string, mixed>>}  $payload
     * @return array{areas: array<int, array<string, mixed>>}
     */
    private function filterPayloadForWaiter(array $payload): array
    {
        $user = user();
        if (! $user || ! $user->hasRole('Waiter_'.$user->restaurant_id)) {
            return $payload;
        }

        // Scope waiter assignments to tables visible in this payload (current branch).
        // Without this, assignments from other branches can produce an empty board.
        $payloadTableIds = collect($payload['areas'] ?? [])
            ->flatMap(fn ($area) => collect($area['tables'] ?? [])->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($payloadTableIds === []) {
            return $payload;
        }

        $today = now()->format('Y-m-d');
        $assignedTableIds = DB::table('assign_waiter_to_tables')
            ->where(function ($q) use ($user) {
                $q->where('waiter_id', $user->id)
                    ->orWhere('backup_waiter_id', $user->id);
            })
            ->whereIn('table_id', $payloadTableIds)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            })
            ->pluck('table_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if ($assignedTableIds === []) {
            return $payload;
        }

        $areasOut = [];
        foreach ($payload['areas'] as $area) {
            $tables = array_values(array_filter($area['tables'], function (array $t) use ($assignedTableIds) {
                return in_array((int) $t['id'], $assignedTableIds, true);
            }));
            if ($tables !== []) {
                $areasOut[] = [
                    'id' => $area['id'],
                    'area_name' => $area['area_name'],
                    'tables' => $tables,
                ];
            }
        }

        return ['areas' => $areasOut];
    }

    public function render()
    {
        $branchId = (int) branch()->id;
        $payload = TablesIndexCache::get($branchId);
        $tablesPayload = $this->filterPayloadForWaiter($payload);

        $waiters = PosWaitersCache::remember((int) restaurant()->id, $branchId)
            ->map(fn (User $w) => ['id' => $w->id, 'name' => $w->name])
            ->values()
            ->all();

        return view('livewire.table.tables', [
            'tablesPayload' => $tablesPayload,
            'tablesPayloadSignature' => hash('xxh128', (string) json_encode($tablesPayload, JSON_INVALID_UTF8_SUBSTITUTE)),
            'areasList' => collect($payload['areas'])->map(fn ($a) => ['id' => $a['id'], 'area_name' => $a['area_name']])->values()->all(),
            'waiters' => $waiters,
            'authUserId' => (int) user()->id,
            'isRestaurantAdmin' => user()->hasRole('Admin_'.user()->restaurant_id),
            'isWaiterRole' => user()->hasRole('Waiter_'.user()->restaurant_id),
            'canCreateTable' => user_can('Create Table'),
            'canUpdateTable' => user_can('Update Table'),
            'canShowOrder' => user_can('Show Order'),
            'canCreateOrder' => user_can('Create Order'),
            'tablesUi' => [
                'list' => __('app.list'),
                'grid' => __('app.grid'),
                'layout' => __('app.layout'),
                'filterAvailable' => __('modules.table.filterAvailable'),
                'showing' => __('app.showing'),
                'showAll' => __('app.showAll'),
                'available' => __('modules.table.available'),
                'running' => __('modules.table.running'),
                'reserved' => __('modules.table.reserved'),
                'allAreas' => __('modules.table.allAreas'),
                'tableView' => __('modules.table.tableView'),
                'addTable' => __('modules.table.addTable'),
                'seats' => __('modules.table.seats'),
                'inactive' => __('app.inactive'),
                'kot' => __('modules.order.kot'),
                'orderNumber' => __('modules.order.orderNumber'),
                'pax' => __('modules.order.noOfPax'),
                'remaining' => __('modules.order.remaining'),
                'assignWaiter' => __('modules.table.assignWaiter'),
                'showOrder' => __('modules.order.showOrder'),
                'newKot' => __('modules.order.newKot'),
                'awaitingPayment' => __('modules.order.infobilled'),
                'lockedByYou' => __('modules.table.lockedByYou'),
                'forceUnlock' => __('modules.table.forceUnlock'),
                'locked' => __('modules.table.locked'),
            ],
        ]);
    }
}
