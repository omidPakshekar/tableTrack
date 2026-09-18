<?php

use App\Models\Module;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // If core tables are missing, do nothing (fresh installs will use seeders).
        if (!Schema::hasTable('modules') || !Schema::hasTable('permissions') || !Schema::hasTable('roles')) {
            return;
        }

        // Ensure column exists (in case this migration runs before the is_superadmin migration for any reason).
        if (!Schema::hasColumn('modules', 'is_superadmin')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->boolean('is_superadmin')->default(0)->after('name');
            });
        }

        $checkModule = Module::count();

        if ($checkModule > 0) {
            $superadminModules = [
                'Restaurants' => [
                    'Create Restaurant',
                    'Show Restaurant',
                    'Update Restaurant',
                    'Delete Restaurant',
                ],
                'Superadmin Payment' => [
                    'Show Superadmin Payments',
                ],
                'Packages' => [
                    'Create Package',
                    'Show Package',
                    'Update Package',
                    'Delete Package',
                ],
                'Billing' => [
                    'Show Billing',
                ],
                'Offline Request' => [
                    'Show Offline Request',
                ],
                'SuperAdmin' => [
                    'Create SuperAdmin',
                    'Show SuperAdmin',
                    'Update SuperAdmin',
                    'Delete SuperAdmin',
                ],
                'Landing Site' => [
                    'Show Landing Site',
                ],
                'Superadmin Settings' => [
                    'Manage Superadmin Settings',
                ],
            ];

            $createdPermissionNames = [];

            foreach ($superadminModules as $moduleName => $permissionNames) {
                $checkModuleExists = Module::where('name', $moduleName)->first();

                if (!$checkModuleExists) {
                    // Create module if it doesn't exist
                    $module = Module::create([
                        'name' => $moduleName,
                        'is_superadmin' => 1,
                    ]);
                } else {
                    $module = $checkModuleExists;
                    // Update is_superadmin if needed
                    if ((int)($module->is_superadmin ?? 0) !== 1) {
                        $module->is_superadmin = 1;
                        $module->save();
                    }
                }

                // Create or update permissions
                foreach ($permissionNames as $permissionName) {
                    $permission = Permission::where('guard_name', 'web')
                        ->where('name', $permissionName)
                        ->first();

                    if (!$permission) {
                        Permission::create([
                            'guard_name' => 'web',
                            'name' => $permissionName,
                            'module_id' => $module->id,
                        ]);
                    } else {
                        // Keep module_id consistent for existing installs
                        if ((int)($permission->module_id ?? 0) !== (int)$module->id) {
                            $permission->module_id = $module->id;
                            $permission->save();
                        }
                    }

                    $createdPermissionNames[] = $permissionName;
                }
            }

            // Assign all superadmin permissions to the Super Admin role (restaurant_id is NULL)
            $superAdminRole = Role::whereNull('restaurant_id')
                ->where(function ($q) {
                    $q->where('name', 'Super Admin')
                        ->orWhere('display_name', 'Super Admin');
                })
                ->first();

            if ($superAdminRole && count($createdPermissionNames) > 0) {
                $superAdminRole->givePermissionTo($createdPermissionNames);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally not deleting modules/permissions on rollback for existing installs.
        // Removing permissions/modules here could break production setups.
    }
};


