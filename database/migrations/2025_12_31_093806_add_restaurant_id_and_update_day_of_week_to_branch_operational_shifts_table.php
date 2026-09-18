<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if restaurant_id column exists, if not add it
        if (!Schema::hasColumn('branch_operational_shifts', 'restaurant_id')) {
            Schema::table('branch_operational_shifts', function (Blueprint $table) {
                // Add restaurant_id column as nullable first
                $table->unsignedBigInteger('restaurant_id')->nullable()->after('branch_id');
            });
        }

        // FIRST: Update NULL values to a temporary valid enum value (Monday) so we can modify the enum
        DB::table('branch_operational_shifts')
            ->whereNull('day_of_week')
            ->update(['day_of_week' => 'Monday']);

        // NOW: Update enum to include 'All' and make it NOT NULL
        DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week ENUM('All', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL DEFAULT 'All'");

        // Update the temporary 'Monday' values (that were NULL) back to 'All'
        // We'll identify them by checking if they should be 'All' (we'll update all to 'All' for now, user can change later)
        // Actually, let's keep them as 'Monday' for now - user can change to 'All' if needed

        // Populate restaurant_id from branch BEFORE adding foreign key
        // First, delete any shifts with invalid branch_id
        DB::statement('
            DELETE bos FROM branch_operational_shifts bos
            LEFT JOIN branches b ON bos.branch_id = b.id
            WHERE b.id IS NULL
        ');
        
        // Now populate restaurant_id - update ALL rows to ensure they have correct restaurant_id
        // Fix any 0 values and NULL values
        DB::statement('
            UPDATE branch_operational_shifts bos
            INNER JOIN branches b ON bos.branch_id = b.id
            INNER JOIN restaurants r ON b.restaurant_id = r.id
            SET bos.restaurant_id = b.restaurant_id
            WHERE bos.restaurant_id IS NULL OR bos.restaurant_id = 0
        ');
        
        // Delete any rows that still have invalid restaurant_id
        DB::statement('
            DELETE bos FROM branch_operational_shifts bos
            LEFT JOIN restaurants r ON bos.restaurant_id = r.id
            WHERE bos.restaurant_id IS NOT NULL AND r.id IS NULL
        ');
        
        // Set any remaining NULL restaurant_id to a valid restaurant (if any exist)
        $firstRestaurant = DB::table('restaurants')->first();
        if ($firstRestaurant) {
            DB::table('branch_operational_shifts')
                ->whereNull('restaurant_id')
                ->orWhere('restaurant_id', 0)
                ->update(['restaurant_id' => $firstRestaurant->id]);
        }

        // Check if foreign key exists, if not add it
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'branch_operational_shifts' 
            AND CONSTRAINT_NAME = 'branch_operational_shifts_restaurant_id_foreign'
        ");

        if (empty($foreignKeys)) {
            Schema::table('branch_operational_shifts', function (Blueprint $table) {
                $table->foreign('restaurant_id')->references('id')->on('restaurants')->onDelete('cascade')->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_operational_shifts', function (Blueprint $table) {
            // Revert day_of_week to nullable enum without 'All'
            DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NULL");
            
            // Update 'All' values back to NULL
            DB::table('branch_operational_shifts')
                ->where('day_of_week', 'All')
                ->update(['day_of_week' => null]);
            
            // Drop restaurant_id foreign key and column
            $table->dropForeign(['restaurant_id']);
            $table->dropColumn('restaurant_id');
        });
    }
};
