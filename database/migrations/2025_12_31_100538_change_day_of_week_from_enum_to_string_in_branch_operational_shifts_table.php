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
        // Change day_of_week from enum to string
        DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week VARCHAR(20) NOT NULL DEFAULT 'All'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to enum
        DB::statement("ALTER TABLE branch_operational_shifts MODIFY COLUMN day_of_week ENUM('All', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL DEFAULT 'All'");
    }
};
