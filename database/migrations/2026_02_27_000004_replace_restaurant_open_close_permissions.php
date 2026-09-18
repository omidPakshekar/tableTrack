<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GUARD = 'web';
    private const PERMISSION_NAME = 'Show Restaurant Open/Close';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settingsModuleId = DB::table('modules')
            ->where('name', 'Settings')
            ->where('is_superadmin', 0)
            ->value('id');

        if (!$settingsModuleId) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            [
                'guard_name' => self::GUARD,
                'name' => self::PERMISSION_NAME,
            ],
            [
                'module_id' => $settingsModuleId,
            ]
        );

        $permissionId = DB::table('permissions')
            ->where('guard_name', self::GUARD)
            ->where('name', self::PERMISSION_NAME)
            ->value('id');

        if (!$permissionId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->where(function ($query) {
                $query->where('name', 'like', 'Admin\_%')
                    ->orWhere('name', 'like', 'Branch Head\_%');
            })
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ],
                []
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', self::GUARD)
            ->where('name', self::PERMISSION_NAME)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
