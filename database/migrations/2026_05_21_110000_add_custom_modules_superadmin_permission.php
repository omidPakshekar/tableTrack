<?php

use App\Models\Module;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private const GUARD = 'web';
    private const MODULE_NAME = 'App Update';
    private const PERMISSION_NAME = 'Custom Modules';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('modules') || !Schema::hasTable('permissions') || !Schema::hasTable('roles')) {
            return;
        }

        $module = Module::withoutGlobalScopes()->where('name', self::MODULE_NAME)->first();

        if (!$module) {
            $module = Module::withoutGlobalScopes()->create([
                'name' => self::MODULE_NAME,
                'is_superadmin' => 1,
            ]);
        }

        Permission::updateOrCreate(
            [
                'guard_name' => self::GUARD,
                'name' => self::PERMISSION_NAME,
            ],
            [
                'module_id' => $module->id,
            ]
        );

        $superAdminRole = Role::whereNull('restaurant_id')
            ->where(function ($q) {
                $q->where('name', 'Super Admin')
                    ->orWhere('display_name', 'Super Admin');
            })
            ->first();

        if ($superAdminRole) {
            $superAdminRole->givePermissionTo(self::PERMISSION_NAME);
        }

        cache()->clear();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally not deleting permission on rollback for existing installs.
    }
};
