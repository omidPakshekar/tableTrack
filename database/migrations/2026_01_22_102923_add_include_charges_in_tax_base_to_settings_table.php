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
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('include_charges_in_tax_base')->default(false)->after('tax_inclusive');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_base', 16, 2)->nullable()->after('total_tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('include_charges_in_tax_base');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_base');
        });
    }
};
