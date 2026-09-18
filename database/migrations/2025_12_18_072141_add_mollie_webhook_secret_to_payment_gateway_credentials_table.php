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
            if (!Schema::hasColumn('payment_gateway_credentials', 'mollie_webhook_secret')) {
                $table->string('mollie_webhook_secret')->nullable()->after('live_mollie_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateway_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('payment_gateway_credentials', 'mollie_webhook_secret')) {
                $table->dropColumn('mollie_webhook_secret');
            }
        });
    }
};
