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
        if (!Schema::hasColumn('restaurant_charges', 'is_taxable')) {
            Schema::table('restaurant_charges', function (Blueprint $table) {
                $table->boolean('is_taxable')->default(true)->after('is_enabled');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('restaurant_charges', 'is_taxable')) {
            Schema::table('restaurant_charges', function (Blueprint $table) {
                $table->dropColumn('is_taxable');
            });
        }
    }
};
