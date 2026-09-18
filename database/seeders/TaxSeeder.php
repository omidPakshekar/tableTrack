<?php

namespace Database\Seeders;

use App\Models\Tax;
use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run($restaurant): void
    {
        // Load all branches for this restaurant
        $branches = Branch::withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->pluck('id');

        if ($branches->isEmpty()) {
            return;
        }

        // Remove any existing SGST/CGST taxes for all branches of this restaurant
        // withoutGlobalScopes() bypasses BranchScope (applied via HasBranch trait on Tax model)
        Tax::withoutEvents(function () use ($restaurant, $branches) {
            Tax::withoutGlobalScopes()
                ->where('restaurant_id', $restaurant->id)
                ->whereIn('tax_name', ['SGST', 'CGST'])
                ->whereIn('branch_id', $branches)
                ->delete();
        });

        // Build bulk insert data: one SGST + one CGST per branch
        $now = now();
        $taxRows = [];

        foreach ($branches as $branchId) {
            $taxRows[] = [
                'tax_name'      => 'SGST',
                'tax_percent'   => '2.5',
                'restaurant_id' => $restaurant->id,
                'branch_id'     => $branchId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $taxRows[] = [
                'tax_name'      => 'CGST',
                'tax_percent'   => '2.5',
                'restaurant_id' => $restaurant->id,
                'branch_id'     => $branchId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        // Insert all at once without triggering model events
        Tax::withoutEvents(function () use ($taxRows) {
            DB::table('taxes')->insert($taxRows);
        });
    }

}
