<?php

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const BILL_AND_PAYMENT = 'Bill and Payment';

    private const BILL_AND_PAYMENT_LEGACY = [
        'Bill and Payment Access',
    ];

    private const DELETE_ITEM_AFTER_KOT = 'Delete Item After KOT';

    private const RETIRED_PERMISSION_NAMES = [
        'Update Waiter Order Status',
    ];

    /**
     * Bill and Payment + Delete Item After KOT permissions (idempotent for existing installs).
     */
    public function up(): void
    {
        $this->removeRetiredPermissions();
        $this->setupBillAndPaymentPermission();
        $this->setupDeleteItemAfterKotPermission();
    }

    public function down(): void
    {
        $this->tearDownDeleteItemAfterKotPermission();
        $this->tearDownBillAndPaymentPermission();
    }

    private function setupBillAndPaymentPermission(): void
    {
        $module = Module::where('name', 'Payment')->where('is_superadmin', 0)->first();

        if (!$module) {
            return;
        }

        $permission = $this->consolidateBillAndPaymentPermission((int) $module->id);

        if (!$permission) {
            return;
        }

        $updateOrderPermission = Permission::where('name', 'Update Order')
            ->where('guard_name', 'web')
            ->first();

        if (!$updateOrderPermission) {
            return;
        }

        $roles = Role::whereHas('permissions', function ($query) use ($updateOrderPermission) {
            $query->where('permissions.id', $updateOrderPermission->id);
        })->get();

        foreach ($roles as $role) {
            if (!$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    private function setupDeleteItemAfterKotPermission(): void
    {
        $module = Module::where('name', 'KOT')->where('is_superadmin', 0)->first();

        if (!$module) {
            return;
        }

        $permission = Permission::firstOrCreate(
            [
                'guard_name' => 'web',
                'name' => self::DELETE_ITEM_AFTER_KOT,
            ],
            [
                'module_id' => $module->id,
            ]
        );

        $permission->update(['module_id' => $module->id]);

        $deleteOrderPermission = Permission::where('name', 'Delete Order')
            ->where('guard_name', 'web')
            ->first();

        if (!$deleteOrderPermission) {
            return;
        }

        $roles = Role::whereHas('permissions', function ($query) use ($deleteOrderPermission) {
            $query->where('permissions.id', $deleteOrderPermission->id);
        })->get();

        foreach ($roles as $role) {
            if (!$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    private function removeRetiredPermissions(): void
    {
        foreach (self::RETIRED_PERMISSION_NAMES as $name) {
            $permission = Permission::where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if (!$permission) {
                continue;
            }

            $roles = Role::whereHas('permissions', function ($query) use ($permission) {
                $query->where('permissions.id', $permission->id);
            })->get();

            foreach ($roles as $role) {
                $role->revokePermissionTo($permission);
            }

            $permission->delete();
        }
    }

    private function tearDownBillAndPaymentPermission(): void
    {
        $names = array_merge([self::BILL_AND_PAYMENT], self::BILL_AND_PAYMENT_LEGACY);

        $permissions = Permission::whereIn('name', $names)
            ->where('guard_name', 'web')
            ->get();

        foreach ($permissions as $permission) {
            $roles = Role::whereHas('permissions', function ($query) use ($permission) {
                $query->where('permissions.id', $permission->id);
            })->get();

            foreach ($roles as $role) {
                $role->revokePermissionTo($permission);
            }

            $permission->delete();
        }
    }

    private function tearDownDeleteItemAfterKotPermission(): void
    {
        $permission = Permission::where('name', self::DELETE_ITEM_AFTER_KOT)
            ->where('guard_name', 'web')
            ->first();

        if (!$permission) {
            return;
        }

        $roles = Role::whereHas('permissions', function ($query) use ($permission) {
            $query->where('permissions.id', $permission->id);
        })->get();

        foreach ($roles as $role) {
            $role->revokePermissionTo($permission);
        }

        $permission->delete();
    }

    private function consolidateBillAndPaymentPermission(int $moduleId): ?Permission
    {
        $allNames = array_merge([self::BILL_AND_PAYMENT], self::BILL_AND_PAYMENT_LEGACY);

        $existing = Permission::where('guard_name', 'web')
            ->whereIn('name', $allNames)
            ->orderBy('id')
            ->get();

        $permission = $existing->firstWhere('name', self::BILL_AND_PAYMENT)
            ?? $existing->first();

        if (!$permission) {
            return Permission::create([
                'guard_name' => 'web',
                'name' => self::BILL_AND_PAYMENT,
                'module_id' => $moduleId,
            ]);
        }

        $permission->update([
            'name' => self::BILL_AND_PAYMENT,
            'module_id' => $moduleId,
        ]);

        foreach ($existing as $duplicate) {
            if ($duplicate->id === $permission->id) {
                continue;
            }

            $this->mergePermissionAssignments($duplicate->id, $permission->id);
            $duplicate->delete();
        }

        return $permission->fresh();
    }

    private function mergePermissionAssignments(int $fromPermissionId, int $toPermissionId): void
    {
        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $fromPermissionId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert(
                [
                    'permission_id' => $toPermissionId,
                    'role_id' => $roleId,
                ],
                []
            );
        }

        DB::table('role_has_permissions')->where('permission_id', $fromPermissionId)->delete();

        $modelAssignments = DB::table('model_has_permissions')
            ->where('permission_id', $fromPermissionId)
            ->get();

        foreach ($modelAssignments as $assignment) {
            DB::table('model_has_permissions')->updateOrInsert(
                [
                    'permission_id' => $toPermissionId,
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                ],
                []
            );
        }

        DB::table('model_has_permissions')->where('permission_id', $fromPermissionId)->delete();
    }
};
