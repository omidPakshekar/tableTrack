<?php

namespace App\Livewire\Settings;

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Scopes\RestaurantScope;
use Livewire\Component;
use Jantinnerezo\LivewireAlert\LivewireAlert;

class SuperadminRoleSettings extends Component
{
    use LivewireAlert;

    public $permissions;
    public $roles;

    // Modal properties
    public $showAddRoleModal = false;
    
    // Role form properties
    public $newRoleDisplayName = '';
    public $copyFromRole = '';
    public $editingRoleId = null;
    public $editingRoleDisplayName = '';
    public $inlineEditingRoleId = null;
    public $inlineEditingRoleName = '';
    
    // Delete role properties
    public $deletingRoleId = null;
    public $deletingRoleName = '';
    public $usersWithDeletingRole = [];
    public $reassignRoleId = '';
    public $showReassignModal = false;

    
    public function mount()
    {
        $this->permissions = Module::where('is_superadmin', 1)->get();
        $this->roles = Role::withoutGlobalScope(RestaurantScope::class)
            ->whereNull('restaurant_id')->get();
    }

    public function setPermission($roleID, $permissionID)
    {
        $role = Role::withoutGlobalScope(RestaurantScope::class)->find($roleID);
        $role->givePermissionTo($permissionID);
    }

    public function removePermission($roleID, $permissionID)
    {
        $role = Role::withoutGlobalScope(RestaurantScope::class)->find($roleID);
        $role->revokePermissionTo($permissionID);
    }

    public function showAddRole()
    {
        $this->showAddRoleModal = true;
        $this->resetRoleForm();
    }

    public function closeAddRoleModal()
    {
        $this->showAddRoleModal = false;
        $this->resetRoleForm();
        $this->resetDeleteForm();
    }

    public function updatedShowAddRoleModal($value)
    {
        if (!$value) {
            $this->resetRoleForm();
            $this->resetDeleteForm();
        }
    }

    public function resetRoleForm()
    {
        $this->newRoleDisplayName = '';
        $this->copyFromRole = '';
        $this->editingRoleId = null;
        $this->editingRoleDisplayName = '';
        $this->inlineEditingRoleId = null;
        $this->inlineEditingRoleName = '';
        $this->resetErrorBag();
    }

    public function resetDeleteForm()
    {
        $this->deletingRoleId = null;
        $this->deletingRoleName = '';
        $this->usersWithDeletingRole = [];
        $this->reassignRoleId = '';
        $this->showReassignModal = false;
    }

    public function startInlineEdit($roleId, $roleName)
    {
        // Check if role is protected
        $protectedRoles = ['Admin', 'Super Admin', 'Branch Head', 'Waiter', 'Chef'];
        $role = Role::withoutGlobalScope(RestaurantScope::class)->find($roleId);
        
        if (in_array($role->display_name, $protectedRoles)) {
            $this->alert('error', __('messages.roleCannotBeUpdated'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }
        
        $this->inlineEditingRoleId = $roleId;
        $this->inlineEditingRoleName = $roleName;
    }

    public function saveInlineEdit()
    {
        $this->validate([
            'inlineEditingRoleName' => 'required|string|max:255',
        ]);

        try {
            $role = Role::withoutGlobalScope(RestaurantScope::class)->find($this->inlineEditingRoleId);
            
            if (!$role) {
                $this->alert('error', __('messages.roleNotFound'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close')
                ]);
                return;
            }

            // Check if new display name already exists for superadmin roles (restaurant_id is null)
            $existingRole = Role::withoutGlobalScope(RestaurantScope::class)
                               ->where('display_name', $this->inlineEditingRoleName)
                               ->where('id', '<>', $this->inlineEditingRoleId)
                               ->where('display_name', '<>', 'Admin')
                               ->where('display_name', '<>', 'Super Admin')
                               ->whereNull('restaurant_id')
                               ->first();

            if ($existingRole) {
                $this->addError('inlineEditingRoleName', 'Role display name already exists.');
                return;
            }

            // Update both name and display_name (no restaurant_id suffix for superadmin roles)
            $role->update([
                'name' => $this->inlineEditingRoleName,
                'display_name' => $this->inlineEditingRoleName,
            ]);

            // Refresh the roles list
            $this->roles = Role::withoutGlobalScope(RestaurantScope::class)
                              ->whereNull('restaurant_id')
                              ->get();

            // Show success message
            $this->alert('success', __('messages.roleUpdatedSuccessfully'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            // Reset inline editing
            $this->inlineEditingRoleId = null;
            $this->inlineEditingRoleName = '';

        } catch (\Exception $e) {
            $this->alert('error', __('messages.errorUpdatingRole') . $e->getMessage(), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }
    }

    public function cancelInlineEdit()
    {
        $this->inlineEditingRoleId = null;
        $this->inlineEditingRoleName = '';
        $this->resetErrorBag();
    }

    public function createRole()
    {
        $this->validate([
            'newRoleDisplayName' => 'required|string|max:255',
        ]);

        // Check if role display name already exists for superadmin roles (restaurant_id is null)
        $existingRole = Role::withoutGlobalScope(RestaurantScope::class)
                           ->where('display_name', $this->newRoleDisplayName)
                           ->where('display_name', '<>', 'Admin')
                           ->where('display_name', '<>', 'Super Admin')
                           ->whereNull('restaurant_id')
                           ->first();

        if ($existingRole) {
            $this->addError('newRoleDisplayName', 'Role display name already exists.');
            return;
        }

        try {
            // Get source role permissions if copying
            $sourcePermissions = null;
            if ($this->copyFromRole) {
                $copyRole = Role::withoutGlobalScope(RestaurantScope::class)->find($this->copyFromRole);
                if ($copyRole) {
                    $sourcePermissions = $copyRole->permissions;
                }
            }

            // Create the role without restaurant_id (superadmin role)
            $role = Role::create([
                'name' => $this->newRoleDisplayName,
                'display_name' => $this->newRoleDisplayName,
                'guard_name' => 'web',
                'restaurant_id' => null,
            ]);

            // Copy permissions if source permissions exist
            if ($sourcePermissions) {
                $role->syncPermissions($sourcePermissions);
            }

            // Refresh the roles list
            $this->roles = Role::withoutGlobalScope(RestaurantScope::class)
                              ->whereNull('restaurant_id')
                              ->get();

            // Show success message
            $this->alert('success', __('messages.roleCreatedSuccessfully'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            // Close modal and reset form
            $this->showAddRoleModal = false;
            $this->resetRoleForm();

        } catch (\Exception $e) {
            $this->alert('error', __('messages.errorCreatingRole') . $e->getMessage(), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }
    }

    public function deleteRole($roleId)
    {
        // Check if role is protected
        $protectedRoles = ['Admin', 'Super Admin', 'Branch Head', 'Waiter', 'Chef'];
        $role = Role::withoutGlobalScope(RestaurantScope::class)->find($roleId);
        
        if (in_array($role->display_name, $protectedRoles)) {
            $this->alert('error', __('messages.roleCannotBeDeleted'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
            return;
        }
        
        $this->deletingRoleId = $roleId;
        $this->deletingRoleName = $role->display_name;
        $this->usersWithDeletingRole = User::whereHas('roles', function($query) use ($roleId) {
            $query->where('roles.id', $roleId);
        })->get();
        $this->showReassignModal = true;
    }

    public function confirmDeleteRole()
    {
        $this->validate([
            'reassignRoleId' => 'required|exists:roles,id',
        ]);

        try {
            $deletingRole = Role::withoutGlobalScope(RestaurantScope::class)->find($this->deletingRoleId);
            $reassignRole = Role::withoutGlobalScope(RestaurantScope::class)->find($this->reassignRoleId);

            if (!$deletingRole || !$reassignRole) {
                $this->alert('error', __('messages.roleNotFound'), [
                    'toast' => true,
                    'position' => 'top-end',
                    'showCancelButton' => false,
                    'cancelButtonText' => __('app.close')
                ]);
                return;
            }

            // Reassign users to the new role
            foreach ($this->usersWithDeletingRole as $user) {
                $user->removeRole($deletingRole);
                $user->assignRole($reassignRole);
            }

            // Delete the role
            $deletingRole->delete();

            // Refresh the roles list
            $this->roles = Role::withoutGlobalScope(RestaurantScope::class)
                              ->whereNull('restaurant_id')
                              ->get();

            // Show success message
            $this->alert('success', __('messages.roleDeletedAndUsersReassigned'), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);

            // Close reassign modal
            $this->showReassignModal = false;
            $this->resetDeleteForm();

        } catch (\Exception $e) {
            $this->alert('error', __('messages.errorDeletingRole') . $e->getMessage(), [
                'toast' => true,
                'position' => 'top-end',
                'showCancelButton' => false,
                'cancelButtonText' => __('app.close')
            ]);
        }
    }

    public function render()
    {
        return view('livewire.settings.superadmin-role-settings');
    }

}
