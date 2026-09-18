<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\User;
use App\Observers\LanguageSettingObserver;
use App\Scopes\ModuleScope;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Scopes\RestaurantScope;

class SuperadminSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdminRole = Role::withoutGlobalScope(RestaurantScope::class)->create(['name' => 'Super Admin', 'display_name' => 'Super Admin', 'guard_name' => 'web', 'restaurant_id' => null]);

        $superadminModuleIds = Module::withoutGlobalScope(ModuleScope::class)->where('is_superadmin', 1)->pluck('id')->toArray();
        
        $allPermissions = Permission::whereIn('module_id', $superadminModuleIds)->pluck('name')->toArray();
            
        $superAdminRole->syncPermissions($allPermissions);

        $user  = User::create([
            'name' => 'Emma Holden',
            'email' => 'superadmin@example.com',
            'password' => bcrypt(123456),
            'locale' => 'fa',
        ]);

        $user->assignRole('Super Admin');
        

    }

}
