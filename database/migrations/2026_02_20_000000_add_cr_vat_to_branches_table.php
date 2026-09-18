<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds optional CR number and VAT number fields to branches,
     * allowing each branch to display its own receipt header information.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('cr_number')->nullable()->after('address');
            $table->string('vat_number')->nullable()->after('cr_number');
        });

        Schema::table('receipt_settings', function (Blueprint $table) {
            $table->boolean('show_restaurant_name')->default(true)->after('show_restaurant_logo');
            $table->boolean('show_branch_name')->default(false)->after('show_restaurant_name');
            $table->boolean('show_branch_address')->default(false)->after('show_branch_name');
            $table->boolean('show_cr_number')->default(false)->after('show_branch_address');
            $table->boolean('show_vat_number')->default(false)->after('show_cr_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['cr_number', 'vat_number']);
        });

        Schema::table('receipt_settings', function (Blueprint $table) {
            $table->dropColumn(['show_restaurant_name', 'show_branch_name', 'show_branch_address', 'show_cr_number', 'show_vat_number']);
        });
    }
};
