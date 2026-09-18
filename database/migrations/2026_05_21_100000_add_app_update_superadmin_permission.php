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
    private const PERMISSION_NAME = 'App Update';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        
        $module = Module::withoutGlobalScopes()->firstOrCreate(
            ['name' => self::MODULE_NAME],
            ['is_superadmin' => 1]
        );

        if ((int) ($module->is_superadmin ?? 0) !== 1) {
            $module->is_superadmin = 1;
            $module->save();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally not deleting module/permission on rollback for existing installs.
    }
};
