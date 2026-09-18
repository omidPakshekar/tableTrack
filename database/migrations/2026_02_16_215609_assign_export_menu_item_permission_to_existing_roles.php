<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\Module;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $module = Module::where('name', 'Menu Item')->where('is_superadmin', 0)->first();

        if (!$module) {
            return;
        }

        // Create the new permission if it doesn't exist
        $permission = Permission::firstOrCreate(
            [
                'guard_name' => 'web',
                'name' => 'Export Menu Item',
            ],
            [
                'module_id' => $module->id
            ]
        );

        // Get the "Show Menu Item" permission to find roles that should have export permission
        $showMenuItemPermission = Permission::where('name', 'Show Menu Item')
            ->where('guard_name', 'web')
            ->first();

        if ($showMenuItemPermission) {
            // Get all roles that have "Show Menu Item" permission
            $roles = Role::whereHas('permissions', function ($query) use ($showMenuItemPermission) {
                $query->where('permissions.id', $showMenuItemPermission->id);
            })->get();

            // Assign "Export Menu Item" permission to these roles
            foreach ($roles as $role) {
                if (!$role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permission = Permission::where('name', 'Export Menu Item')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            // Revoke permission from all roles before deleting
            $roles = Role::whereHas('permissions', function ($query) use ($permission) {
                $query->where('permissions.id', $permission->id);
            })->get();

            foreach ($roles as $role) {
                $role->revokePermissionTo($permission);
            }

            // Delete the permission
            $permission->delete();
        }
    }
};
