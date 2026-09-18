<?php

use App\Models\Branch;
use App\Models\Tax;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('restaurant_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
        });

        // Migrate existing taxes: for each existing tax (which has restaurant_id but no branch_id),
        // find all branches of that restaurant and create a copy per branch.
        // The original record is assigned to the first branch; copies are created for subsequent branches.
        $taxes = DB::table('taxes')->whereNull('branch_id')->get();

        foreach ($taxes as $tax) {
            if (!$tax->restaurant_id) {
                continue;
            }

            $branches = DB::table('branches')
                ->where('restaurant_id', $tax->restaurant_id)
                ->orderBy('id')
                ->get();

            if ($branches->isEmpty()) {
                continue;
            }

            // Assign the first branch to the original tax record
            $firstBranch = $branches->first();
            DB::table('taxes')
                ->where('id', $tax->id)
                ->update(['branch_id' => $firstBranch->id]);

            // Create copies for remaining branches
            foreach ($branches->skip(1) as $branch) {
                $newTaxId = DB::table('taxes')->insertGetId([
                    'restaurant_id' => $tax->restaurant_id,
                    'branch_id'     => $branch->id,
                    'tax_name'      => $tax->tax_name,
                    'tax_percent'   => $tax->tax_percent,
                    'created_at'    => $tax->created_at,
                    'updated_at'    => now(),
                ]);

                // Copy menu_item_tax associations for items in this branch
                $menuItemIds = DB::table('menu_items')
                    ->where('branch_id', $branch->id)
                    ->pluck('id');

                $existingAssociations = DB::table('menu_item_tax')
                    ->where('tax_id', $tax->id)
                    ->whereIn('menu_item_id', $menuItemIds)
                    ->get();

                foreach ($existingAssociations as $assoc) {
                    DB::table('menu_item_tax')->insert([
                        'menu_item_id' => $assoc->menu_item_id,
                        'tax_id'       => $newTaxId,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
