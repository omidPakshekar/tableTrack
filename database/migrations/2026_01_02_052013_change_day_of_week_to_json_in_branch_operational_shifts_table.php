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
        // First, fix any double/triple-encoded JSON data
        $shifts = DB::table('branch_operational_shifts')->get();
        foreach ($shifts as $shift) {
            $dayValue = $shift->day_of_week;
            $finalDay = null;
            
            // Recursively decode until we get a valid day name
            $current = $dayValue;
            $maxIterations = 10;
            $iteration = 0;
            
            while ($iteration < $maxIterations) {
                $decoded = json_decode($current, true);
                
                if ($decoded === null) {
                    // Can't decode, check if it's a valid day name
                    if (in_array($current, ['All', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])) {
                        $finalDay = $current;
                    }
                    break;
                }
                
                if (is_array($decoded)) {
                    // Check if array contains valid day names
                    $validDays = array_filter($decoded, function($d) {
                        return is_string($d) && in_array($d, ['All', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
                    });
                    
                    if (count($validDays) > 0) {
                        $finalDay = array_values($validDays);
                        break;
                    }
                    
                    // If first element is a string, try decoding it
                    if (count($decoded) > 0 && is_string($decoded[0])) {
                        $current = $decoded[0];
                        $iteration++;
                        continue;
                    }
                    break;
                }
                
                if (is_string($decoded)) {
                    $current = $decoded;
                    $iteration++;
                    continue;
                }
                
                break;
            }
            
            // Create final array
            if ($finalDay === null) {
                $finalArray = ['All'];
            } elseif (is_array($finalDay)) {
                $finalArray = $finalDay;
            } else {
                $finalArray = [$finalDay];
            }
            
            // If 'All' is in the array, it should be the only element
            if (in_array('All', $finalArray)) {
                $finalArray = ['All'];
            }
            
            // Use DB::raw to set the JSON value directly
            DB::statement("
                UPDATE branch_operational_shifts 
                SET day_of_week = ? 
                WHERE id = ?
            ", [json_encode($finalArray), $shift->id]);
        }
        
        // Drop the index on day_of_week if it exists
        try {
            DB::statement("ALTER TABLE branch_operational_shifts DROP INDEX branch_operational_shifts_branch_id_day_of_week_index");
        } catch (\Exception $e) {
            // Index might not exist, continue
        }
        
        // Change column to JSON
        DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week JSON NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert JSON back to single string (take first day or 'All')
        DB::statement("
            UPDATE branch_operational_shifts 
            SET day_of_week = CASE 
                WHEN JSON_CONTAINS(day_of_week, '\"All\"') THEN 'All'
                ELSE JSON_UNQUOTE(JSON_EXTRACT(day_of_week, '$[0]'))
            END
        ");
        
        // Change back to VARCHAR
        DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week VARCHAR(20) NOT NULL DEFAULT 'All'");
        
        // Re-add the composite index
        Schema::table('branch_operational_shifts', function (Blueprint $table) {
            $table->index(['branch_id', 'day_of_week']);
        });
    }
};
