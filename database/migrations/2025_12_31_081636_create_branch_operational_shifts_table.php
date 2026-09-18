<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branch_operational_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade')->onUpdate('cascade');
            $table->string('shift_name')->nullable()->comment('Optional name for the shift (e.g., Morning Shift, Evening Shift)');
            $table->time('start_time')->comment('Start time of the shift (e.g., 09:00 for 9 AM)');
            $table->time('end_time')->comment('End time of the shift (e.g., 14:00 for 2 PM or 01:00 for 1 AM next day)');
            $table->enum('day_of_week', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])->nullable()->comment('Specific day of week, NULL means applies to all days');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0)->comment('Display order for shifts');
            $table->timestamps();
            
            // Index for faster queries
            $table->index(['branch_id', 'is_active']);
            $table->index(['branch_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_operational_shifts');
    }
};
