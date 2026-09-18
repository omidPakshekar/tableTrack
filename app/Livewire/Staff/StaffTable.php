<?php

namespace App\Livewire\Staff;

use App\Models\Role;
use App\Services\Pos\PosWaitersCache;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class StaffTable extends Component
{

    use LivewireAlert;
    use WithPagination, WithoutUrlPagination;

    public $search;
    public $customer;
    public $roles;
    public $showEditCustomerModal = false;
    public $confirmDeleteCustomerModal = false;
    public $showCustomerOrderModal = false;

    protected $listeners = ['refreshCustomers' => '$refresh'];

    public function mount()
    {
        $this->roles = Role::where('name', '<>', 'Super Admin')->get();
    }

    /**
     * Same scope as {@see render()} so list rows are always resolvable here.
     * BranchScope would hide users with null branch_id (e.g. restaurant admins) from findOrFail().
     */
    protected function staffMembersQuery(): Builder
    {
        return User::withoutGlobalScope(BranchScope::class)
            ->where(function ($q) {
                $q->where('branch_id', branch()->id)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', restaurant()->id);
    }

    public function showEditCustomer($id)
    {
        $this->customer = User::withoutGlobalScopes()->where('restaurant_id', restaurant()->id)->findOrFail($id);
        $this->showEditCustomerModal = true;
    }

    #[On('hideEditStaff')]
    public function hideEditStaff()
    {
        $this->showEditCustomerModal = false;
    }

    public function showDeleteCustomer($id)
    {
        $this->customer = $this->staffMembersQuery()->findOrFail($id);
        $this->confirmDeleteCustomerModal = true;
    }

    public function deleteCustomer($id)
    {
        if ((int) $id === (int) auth()->id()) {
            $this->alert('error', __('messages.cannotDeleteOwnAccount'), [
                'toast' => true,
                'position' => 'top-end',
            ]);

            return;
        }

        $user = $this->staffMembersQuery()->findOrFail($id);
        $restaurantId = $user->restaurant_id;
        $user->delete();

        if ($restaurantId) {
            cache()->forget('restaurant_' . $restaurantId . '_staff_stats');
        }

        PosWaitersCache::forgetForRestaurant((int) $restaurantId);

        $this->confirmDeleteCustomerModal = false;
        $this->customer = null;

        $this->alert('success', __('messages.memberDeleted'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
            'cancelButtonText' => __('app.close')
        ]);
    }

    public function setUserRole($role, $userID)
    {
        $employee = $this->staffMembersQuery()->findOrFail($userID);
        $employee->syncRoles([$role]);
        if ($employee && $employee->restaurant_id) {
            PosWaitersCache::forgetForRestaurant((int) $employee->restaurant_id);
        }
        $this->redirect(route('staff.index'), navigate: true);
    }

    #[On('hideEditCustomer')]
    public function hideEditCustomer()
    {
        $this->showEditCustomerModal = false;
    }

    public function render()
    {
        $query = $this->staffMembersQuery()
            ->where(function ($q) {
                return $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            })
            ->paginate(10);

        return view('livewire.staff.staff-table', [
            'members' => $query
        ]);
    }
}
