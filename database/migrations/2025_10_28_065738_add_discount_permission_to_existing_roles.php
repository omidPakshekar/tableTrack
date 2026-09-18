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
        $module = Module::where('name', 'Order')->first();

        if (!$module) {
            return;
        }

        // Create the new permission if it doesn't exist
        $permission = Permission::firstOrCreate(
            [
                'guard_name' => 'web',
                'name' => 'Add Discount on POS',
            ],
            [
                'module_id' => $module->id
            ]
        );

        // Get all Admin and Branch Head roles in a single query
        $roles = Role::whereIn('display_name', ['Admin', 'Branch Head'])->get();

        // Assign permission to all matching roles
        foreach ($roles as $role) {
            if (!$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permission = Permission::where('name', 'Add Discount on POS')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            // Revoke permission from all roles before deleting
            $roles = Role::whereIn('display_name', ['Admin', 'Branch Head'])->get();

            foreach ($roles as $role) {
                $role->revokePermissionTo($permission);
            }

            // Delete the permission
            $permission->delete();
        }
    }
};
