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
        Schema::table('superadmin_payment_gateways', function (Blueprint $table) {
            $table->string('tap_merchant_id')->nullable();
            $table->string('live_tap_secret_key')->nullable();
            $table->string('live_tap_public_key')->nullable();
            $table->string('test_tap_secret_key')->nullable();
            $table->string('test_tap_public_key')->nullable();
            $table->boolean('tap_status')->default(false);
            $table->enum('tap_mode', ['sandbox', 'live'])->default('sandbox');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('superadmin_payment_gateways', function (Blueprint $table) {
            $table->dropColumn([
                'tap_merchant_id',
                'live_tap_secret_key',
                'live_tap_public_key',
                'test_tap_secret_key',
                'test_tap_public_key',
                'tap_status',
                'tap_mode',
            ]);
        });
    }
};
