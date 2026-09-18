<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Retrieve modules by name
        // Superadmin modules
        $restaurantsModule = Module::where('name', 'Restaurants')->where('is_superadmin', 1)->first();
        $superadminPaymentModule = Module::where('name', 'Superadmin Payment')->where('is_superadmin', 1)->first();
        $packagesModule = Module::where('name', 'Packages')->where('is_superadmin', 1)->first();
        $billingModule = Module::where('name', 'Billing')->where('is_superadmin', 1)->first();
        $offlineRequestModule = Module::where('name', 'Offline Request')->where('is_superadmin', 1)->first();
        $superAdminModule = Module::where('name', 'SuperAdmin')->where('is_superadmin', 1)->first();
        $landingSiteModule = Module::where('name', 'Landing Site')->where('is_superadmin', 1)->first();
        $superadminSettingsModule = Module::where('name', 'Superadmin Settings')->where('is_superadmin', 1)->first();
        $appUpdateModule = Module::where('name', 'App Update')->where('is_superadmin', 1)->first();

        // Admin/Restaurant modules
        $menuModule = Module::where('name', 'Menu')->where('is_superadmin', 0)->first();
        $menuItemModule = Module::where('name', 'Menu Item')->where('is_superadmin', 0)->first();
        $itemCategoryModule = Module::where('name', 'Item Category')->where('is_superadmin', 0)->first();
        $areaModule = Module::where('name', 'Area')->where('is_superadmin', 0)->first();
        $tableModule = Module::where('name', 'Table')->where('is_superadmin', 0)->first();
        $reservationModule = Module::where('name', 'Reservation')->where('is_superadmin', 0)->first();
        $kotModule = Module::where('name', 'KOT')->where('is_superadmin', 0)->first();
        $orderModule = Module::where('name', 'Order')->where('is_superadmin', 0)->first();
        $customerModule = Module::where('name', 'Customer')->where('is_superadmin', 0)->first();
        $staffModule = Module::where('name', 'Staff')->where('is_superadmin', 0)->first();
        $paymentModule = Module::where('name', 'Payment')->where('is_superadmin', 0)->first();
        $reportModule = Module::where('name', 'Report')->where('is_superadmin', 0)->first();
        $settingsModule = Module::where('name', 'Settings')->where('is_superadmin', 0)->first();
        $deliveryExecutiveModule = Module::where('name', 'Delivery Executive')->where('is_superadmin', 0)->first();
        $waiterRequestModule = Module::where('name', 'Waiter Request')->where('is_superadmin', 0)->first();
        $expenseModule = Module::where('name', 'Expense')->where('is_superadmin', 0)->first();

        // Define permissions to insert
        $permissions = [
            // Superadmin module permissions
            ['guard_name' => 'web', 'name' => 'Create Restaurant', 'module_id' => $restaurantsModule->id],
            ['guard_name' => 'web', 'name' => 'Show Restaurant', 'module_id' => $restaurantsModule->id],
            ['guard_name' => 'web', 'name' => 'Update Restaurant', 'module_id' => $restaurantsModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Restaurant', 'module_id' => $restaurantsModule->id],

            ['guard_name' => 'web', 'name' => 'Show Superadmin Payments', 'module_id' => $superadminPaymentModule->id],

            ['guard_name' => 'web', 'name' => 'Create Package', 'module_id' => $packagesModule->id],
            ['guard_name' => 'web', 'name' => 'Show Package', 'module_id' => $packagesModule->id],
            ['guard_name' => 'web', 'name' => 'Update Package', 'module_id' => $packagesModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Package', 'module_id' => $packagesModule->id],

            ['guard_name' => 'web', 'name' => 'Show Billing', 'module_id' => $billingModule->id],

            ['guard_name' => 'web', 'name' => 'Show Offline Request', 'module_id' => $offlineRequestModule->id],

            ['guard_name' => 'web', 'name' => 'Create SuperAdmin', 'module_id' => $superAdminModule->id],
            ['guard_name' => 'web', 'name' => 'Show SuperAdmin', 'module_id' => $superAdminModule->id],
            ['guard_name' => 'web', 'name' => 'Update SuperAdmin', 'module_id' => $superAdminModule->id],
            ['guard_name' => 'web', 'name' => 'Delete SuperAdmin', 'module_id' => $superAdminModule->id],

            ['guard_name' => 'web', 'name' => 'Show Landing Site', 'module_id' => $landingSiteModule->id],

            ['guard_name' => 'web', 'name' => 'Manage Superadmin Settings', 'module_id' => $superadminSettingsModule->id],

            ['guard_name' => 'web', 'name' => 'App Update', 'module_id' => $appUpdateModule->id],
            ['guard_name' => 'web', 'name' => 'Custom Modules', 'module_id' => $appUpdateModule->id],

            // Admin/Restaurant module permissions
            ['guard_name' => 'web', 'name' => 'Create Menu', 'module_id' => $menuModule->id],
            ['guard_name' => 'web', 'name' => 'Show Menu', 'module_id' => $menuModule->id],
            ['guard_name' => 'web', 'name' => 'Update Menu', 'module_id' => $menuModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Menu', 'module_id' => $menuModule->id],

            ['guard_name' => 'web', 'name' => 'Create Menu Item', 'module_id' => $menuItemModule->id],
            ['guard_name' => 'web', 'name' => 'Show Menu Item', 'module_id' => $menuItemModule->id],
            ['guard_name' => 'web', 'name' => 'Update Menu Item', 'module_id' => $menuItemModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Menu Item', 'module_id' => $menuItemModule->id],
            ['guard_name' => 'web', 'name' => 'Export Menu Item', 'module_id' => $menuItemModule->id],

            ['guard_name' => 'web', 'name' => 'Create Item Category', 'module_id' => $itemCategoryModule->id],
            ['guard_name' => 'web', 'name' => 'Show Item Category', 'module_id' => $itemCategoryModule->id],
            ['guard_name' => 'web', 'name' => 'Update Item Category', 'module_id' => $itemCategoryModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Item Category', 'module_id' => $itemCategoryModule->id],

            ['guard_name' => 'web', 'name' => 'Create Area', 'module_id' => $areaModule->id],
            ['guard_name' => 'web', 'name' => 'Show Area', 'module_id' => $areaModule->id],
            ['guard_name' => 'web', 'name' => 'Update Area', 'module_id' => $areaModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Area', 'module_id' => $areaModule->id],

            ['guard_name' => 'web', 'name' => 'Create Table', 'module_id' => $tableModule->id],
            ['guard_name' => 'web', 'name' => 'Show Table', 'module_id' => $tableModule->id],
            ['guard_name' => 'web', 'name' => 'Update Table', 'module_id' => $tableModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Table', 'module_id' => $tableModule->id],

            ['guard_name' => 'web', 'name' => 'Create Reservation', 'module_id' => $reservationModule->id],
            ['guard_name' => 'web', 'name' => 'Show Reservation', 'module_id' => $reservationModule->id],
            ['guard_name' => 'web', 'name' => 'Update Reservation', 'module_id' => $reservationModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Reservation', 'module_id' => $reservationModule->id],

            ['guard_name' => 'web', 'name' => 'Manage KOT', 'module_id' => $kotModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Item After KOT', 'module_id' => $kotModule->id],

            ['guard_name' => 'web', 'name' => 'Create Order', 'module_id' => $orderModule->id],
            ['guard_name' => 'web', 'name' => 'Show Order', 'module_id' => $orderModule->id],
            ['guard_name' => 'web', 'name' => 'Update Order', 'module_id' => $orderModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Order', 'module_id' => $orderModule->id],
            ['guard_name' => 'web', 'name' => 'Add Discount on POS', 'module_id' => $orderModule->id],

            ['guard_name' => 'web', 'name' => 'Create Customer', 'module_id' => $customerModule->id],
            ['guard_name' => 'web', 'name' => 'Show Customer', 'module_id' => $customerModule->id],
            ['guard_name' => 'web', 'name' => 'Update Customer', 'module_id' => $customerModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Customer', 'module_id' => $customerModule->id],

            ['guard_name' => 'web', 'name' => 'Create Staff Member', 'module_id' => $staffModule->id],
            ['guard_name' => 'web', 'name' => 'Show Staff Member', 'module_id' => $staffModule->id],
            ['guard_name' => 'web', 'name' => 'Update Staff Member', 'module_id' => $staffModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Staff Member', 'module_id' => $staffModule->id],

            ['guard_name' => 'web', 'name' => 'Create Delivery Executive', 'module_id' => $deliveryExecutiveModule->id],
            ['guard_name' => 'web', 'name' => 'Show Delivery Executive', 'module_id' => $deliveryExecutiveModule->id],
            ['guard_name' => 'web', 'name' => 'Update Delivery Executive', 'module_id' => $deliveryExecutiveModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Delivery Executive', 'module_id' => $deliveryExecutiveModule->id],

            ['guard_name' => 'web', 'name' => 'Show Payments', 'module_id' => $paymentModule->id],
            ['guard_name' => 'web', 'name' => 'Refund Payments', 'module_id' => $paymentModule->id],
            ['guard_name' => 'web', 'name' => 'Bill and Payment', 'module_id' => $paymentModule->id],

            ['guard_name' => 'web', 'name' => 'Show Reports', 'module_id' => $reportModule->id],

            ['guard_name' => 'web', 'name' => 'Manage Settings', 'module_id' => $settingsModule->id],
            ['guard_name' => 'web', 'name' => 'Show Restaurant Open/Close', 'module_id' => $settingsModule->id],

            ['guard_name' => 'web', 'name' => 'Manage Waiter Request', 'module_id' => $waiterRequestModule->id],

            ['guard_name' => 'web', 'name' => 'Create Expense', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Show Expense', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Update Expense', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Expense', 'module_id' => $expenseModule->id],

            ['guard_name' => 'web', 'name' => 'Create Expense Category', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Show Expense Category', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Update Expense Category', 'module_id' => $expenseModule->id],
            ['guard_name' => 'web', 'name' => 'Delete Expense Category', 'module_id' => $expenseModule->id],

        ];

        // Create permissions only if they don't exist (idempotent seeder)
        // Uses (guard_name + name) as the unique key.
        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                [
                    'guard_name' => $permission['guard_name'],
                    'name' => $permission['name'],
                ],
                [
                    'module_id' => $permission['module_id'],
                ]
            );
        }
    }

}
