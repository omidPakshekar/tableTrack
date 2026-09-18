<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run($branch): void
    {
        User::withoutEvents(function () use ($branch) {

            if ($branch->restaurant->id == 1) {

                $admin = User::create([
                    'name' => 'John Doe',
                    'email' => 'admin@example.com',
                    'password' => bcrypt(123456),
                    'restaurant_id' => $branch->restaurant->id,
                    'locale' => 'fa',
                ]);

                $adminRole = Role::where('name', 'Admin_' . $branch->restaurant_id)->first();
                $waiterRole = Role::where('name', 'Waiter_' . $branch->restaurant_id)->first();

                $admin->assignRole($adminRole);

                for ($i = 1; $i <= 5; $i++) {
                    $waiterEmail = $i === 1 ? 'waiter@example.com' : "waiter{$i}@example.com";
                    $waiter = User::create([
                        'name' => 'Waiter ' . $i,
                        'email' => $waiterEmail,
                        'password' => bcrypt(123456),
                        'restaurant_id' => $branch->restaurant->id,
                        'branch_id' => $branch->id,
                        'locale' => 'fa',
                    ]);

                    $waiter->assignRole($waiterRole);
                }
            } else {
                $admin = User::create([
                    'name' => fake()->firstName() . ' ' . fake()->lastName(),
                    'email' => $branch->restaurant->email,
                    'password' => bcrypt(123456),
                    'restaurant_id' => $branch->restaurant->id,
                    'locale' => 'fa',
                ]);

                $adminRole = Role::where('name', 'Admin_' . $branch->restaurant_id)->first();
                $waiterRole = Role::where('name', 'Waiter_' . $branch->restaurant_id)->first();

                $admin->assignRole($adminRole);

                for ($i = 1; $i <= 5; $i++) {
                    $waiter = User::create([
                        'name' => 'Waiter ' . $i,
                        'email' => "waiter{$i}.restaurant{$branch->restaurant->id}.branch{$branch->id}@example.com",
                        'password' => bcrypt(123456),
                        'restaurant_id' => $branch->restaurant->id,
                        'branch_id' => $branch->id,
                        'locale' => 'fa',
                    ]);

                    $waiter->assignRole($waiterRole);
                }
            }
        });
    }
}
