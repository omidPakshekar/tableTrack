<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            // Superadmin modules (is_superadmin = 1)
            ['name' => 'Restaurants', 'is_superadmin' => 1],
            ['name' => 'Superadmin Payment', 'is_superadmin' => 1],
            ['name' => 'Packages', 'is_superadmin' => 1],
            ['name' => 'Billing', 'is_superadmin' => 1],
            ['name' => 'Offline Request', 'is_superadmin' => 1],
            ['name' => 'SuperAdmin', 'is_superadmin' => 1],
            ['name' => 'Landing Site', 'is_superadmin' => 1],
            ['name' => 'Superadmin Settings', 'is_superadmin' => 1],
            ['name' => 'App Update', 'is_superadmin' => 1],

            // Admin/Restaurant modules (is_superadmin = 0)
            ['name' => 'Menu', 'is_superadmin' => 0],
            ['name' => 'Menu Item', 'is_superadmin' => 0],
            ['name' => 'Item Category', 'is_superadmin' => 0],
            ['name' => 'Area', 'is_superadmin' => 0],
            ['name' => 'Table', 'is_superadmin' => 0],
            ['name' => 'Reservation', 'is_superadmin' => 0],
            ['name' => 'KOT', 'is_superadmin' => 0],
            ['name' => 'Order', 'is_superadmin' => 0],
            ['name' => 'Customer', 'is_superadmin' => 0],
            ['name' => 'Staff', 'is_superadmin' => 0],
            ['name' => 'Report', 'is_superadmin' => 0],
            ['name' => 'Delivery Executive', 'is_superadmin' => 0],
            ['name' => 'Waiter Request', 'is_superadmin' => 0],
            ['name' => 'Expense', 'is_superadmin' => 0],
            ['name' => 'Payment', 'is_superadmin' => 0],
            ['name' => 'Settings', 'is_superadmin' => 0],
        ];

        // Create modules if missing, and also ensure is_superadmin is correct if module already exists.
        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['name' => $module['name']],
                ['is_superadmin' => $module['is_superadmin']]
            );
        }
    }

}
