<?php

namespace App\Livewire\Menu;

use App\Models\Menu;
use App\Models\Table;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;

class Menus extends Component
{
    use LivewireAlert;

    public $activeMenu;
    public $search = '';
    public $menuId = null;
    public $menuItems = false;
    public $showEditMenuModal = false;
    public $confirmDeleteMenuModal = false;
    public $showAssignTableModal = false;
    public $selectedTableId = null;
    public $selectedMenuIds = [];
    public $is_active = true;
    public $menuCheckboxKey = 0;

    private bool $isLoadingFromDatabase = false;

    protected $listeners = ['refreshMenus' => '$refresh'];
    public function mount()
    {
        $firstMenu = Menu::first();

        if ($firstMenu) {
            $this->showMenuItems($firstMenu->id);
        }
    }

    public function showMenuItems($id)
    {
        $this->activeMenu = Menu::findOrFail($id);
        $this->menuId = $id;
        $this->menuItems = true;
    }

    public function showEditMenu($id)
    {
        $this->showEditMenuModal = true;
        $this->activeMenu = Menu::findOrFail($id);
    }

    #[On('hideEditMenu')]
    public function hideEditMenu()
    {
        $this->showEditMenuModal = false;
    }

    public function updatedShowAssignTableModal(bool $value): void
    {
        if ($value) {
            $this->resetAssignmentForm();
        }
    }

    public function updatedSelectedTableId(?int $value): void
    {
        if (!$value) {
            $this->selectedMenuIds = [];
            $this->is_active = true;
            return;
        }

        $this->isLoadingFromDatabase = true;

        try {
            $assignment = $this->loadTableMenuAssignment($value);
            $this->selectedMenuIds = $assignment['menuIds'];
            $this->is_active = $assignment['is_active'];
            $this->menuCheckboxKey++;
        } finally {
            $this->isLoadingFromDatabase = false;
        }
    }

    public function updatedSelectedMenuIds($value): void
    {
        if ($this->isLoadingFromDatabase || !is_array($this->selectedMenuIds)) {
            return;
        }

        $this->selectedMenuIds = $this->normalizeMenuIds($this->selectedMenuIds);
    }

    public function saveTableMenuAssignment(): void
    {
        $this->selectedMenuIds = $this->normalizeMenuIds($this->selectedMenuIds ?? []);

        $this->validate([
            'selectedTableId' => 'required|exists:tables,id',
            'selectedMenuIds' => 'required|array|min:1',
            'selectedMenuIds.*' => 'exists:menus,id',
            'is_active' => 'boolean'
        ], [
            'selectedTableId.required' => __('validation.required', ['attribute' => __('modules.table.table')]),
            'selectedTableId.exists' => __('validation.exists', ['attribute' => __('modules.table.table')]),
            'selectedMenuIds.required' => __('validation.required', ['attribute' => __('modules.menu.menuName')]),
            'selectedMenuIds.min' => __('validation.min.array', ['attribute' => __('modules.menu.menuName'), 'min' => 1]),
            'selectedMenuIds.*.exists' => __('validation.exists', ['attribute' => __('modules.menu.menuName')]),
        ]);

        try {
            DB::beginTransaction();

            $this->syncTableMenuAssignments();

            DB::commit();

            $this->alert('success', __('messages.tableMenuAssigned'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            $this->resetForm();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->alert('error', __('messages.somethingWentWrong'), [
                'toast' => true,
                'position' => 'top-end',
            ]);
        }
    }

    public function deleteMenu($id)
    {
        Menu::destroy($id);
        $this->confirmDeleteMenuModal = false;

        $this->alert('success', __('messages.menuDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);

        $this->menuItems = false;
        $this->activeMenu = false;
    }

    public function render()
    {
        return view('livewire.menu.menus', [
            'menus' => Menu::withCount('items')->search('menu_name', $this->search)->get(),
            'allTables' => Table::where('status', 'active')->orderBy('table_code')->get(),
            'allMenus' => Menu::orderBy('menu_name')->get()
        ]);
    }


    private function loadTableMenuAssignment(int $tableId): array
    {
        $assignment = DB::table('menu_table')
            ->where('table_id', $tableId)
            ->select('menu_id', 'is_active')
            ->get();

        $menuIds = $assignment->pluck('menu_id')->map(fn($id) => (int) $id)->values()->toArray();

        return [
            'menuIds' => [...$menuIds],
            'is_active' => $assignment->isNotEmpty() ? (bool) $assignment->first()->is_active : true,
        ];
    }

    /**
     * Normalize menu IDs to integers and remove duplicates
     */
    private function normalizeMenuIds(array $menuIds): array
    {
        return array_values(array_unique(array_map('intval', $menuIds)));
    }

    /**
     * Sync table menu assignments (add, update, remove)
     */
    private function syncTableMenuAssignments(): void
    {
        $existingMenuIds = $this->getExistingMenuIds();
        $selectedMenuIds = $this->selectedMenuIds;

        $menusToAdd = array_diff($selectedMenuIds, $existingMenuIds);
        $menusToRemove = array_diff($existingMenuIds, $selectedMenuIds);
        $menusToUpdate = array_intersect($selectedMenuIds, $existingMenuIds);

        // Bulk insert new assignments
        if (!empty($menusToAdd)) {
            $insertData = array_map(fn($menuId) => [
                'table_id' => $this->selectedTableId,
                'menu_id' => $menuId,
                'is_active' => (int) $this->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ], $menusToAdd);

            DB::table('menu_table')->insert($insertData);
        }

        // Bulk update existing assignments
        if (!empty($menusToUpdate)) {
            DB::table('menu_table')
                ->where('table_id', $this->selectedTableId)
                ->whereIn('menu_id', $menusToUpdate)
                ->update([
                    'is_active' => (int) $this->is_active,
                    'updated_at' => now(),
                ]);
        }

        // Bulk delete removed assignments
        if (!empty($menusToRemove)) {
            DB::table('menu_table')
                ->where('table_id', $this->selectedTableId)
                ->whereIn('menu_id', $menusToRemove)
                ->delete();
        }
    }

    /**
     * Get existing menu IDs for the selected table
     */
    private function getExistingMenuIds(): array
    {
        return DB::table('menu_table')
            ->where('table_id', $this->selectedTableId)
            ->pluck('menu_id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    /**
     * Reset assignment form to default state
     */
    private function resetAssignmentForm(): void
    {
        $this->selectedTableId = null;
        $this->selectedMenuIds = [];
        $this->is_active = true;
    }

    /**
     * Reset form after successful save
     */
    private function resetForm(): void
    {
        $this->showAssignTableModal = false;
        $this->resetAssignmentForm();
    }

}
