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
        Schema::table('payment_gateway_credentials', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_gateway_credentials', 'mollie_status')) {
                $table->boolean('mollie_status')->default(false);
            }
            if (!Schema::hasColumn('payment_gateway_credentials', 'mollie_mode')) {
                $table->enum('mollie_mode', ['test', 'live'])->default('test');
            }
            if (!Schema::hasColumn('payment_gateway_credentials', 'test_mollie_key')) {
                $table->string('test_mollie_key')->nullable();
            }
            if (!Schema::hasColumn('payment_gateway_credentials', 'live_mollie_key')) {
                $table->string('live_mollie_key')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateway_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('payment_gateway_credentials', 'mollie_status')) {
                $table->dropColumn('mollie_status');
            }
            if (Schema::hasColumn('payment_gateway_credentials', 'mollie_mode')) {
                $table->dropColumn('mollie_mode');
            }
            if (Schema::hasColumn('payment_gateway_credentials', 'test_mollie_key')) {
                $table->dropColumn('test_mollie_key');
            }
            if (Schema::hasColumn('payment_gateway_credentials', 'live_mollie_key')) {
                $table->dropColumn('live_mollie_key');
            }
        });
    }
};
