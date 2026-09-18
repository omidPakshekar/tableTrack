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
        // Add Tap columns in payment_gateway_credentials (same style as epay/mollie)
        if (!Schema::hasColumn('payment_gateway_credentials', 'tap_status')) {
            Schema::table('payment_gateway_credentials', function (Blueprint $table) {
                $table->boolean('tap_status')->default(false);
                $table->enum('tap_mode', ['sandbox', 'live'])->default('sandbox');
                $table->string('tap_merchant_id')->nullable();
                $table->text('live_tap_secret_key')->nullable();
                $table->text('live_tap_public_key')->nullable();
                $table->text('test_tap_secret_key')->nullable();
                $table->text('test_tap_public_key')->nullable();
            });
        }

        // Create tap_payments table (same style as paypal_payments / epay_payments)
        if (!Schema::hasTable('tap_payments')) {
            Schema::create('tap_payments', function (Blueprint $table) {
                $table->id();
                $table->string('tap_payment_id')->nullable();
                $table->unsignedBigInteger('order_id');
                $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
                $table->decimal('amount', 10, 2);
                $table->enum('payment_status', ['pending', 'completed', 'failed'])->default('pending');
                $table->timestamp('payment_date')->nullable();
                $table->json('payment_error_response')->nullable();
                $table->timestamps();
            });
        }

        // Optional global flag (mirrors enable_epay pattern)
        if (!Schema::hasColumn('global_settings', 'enable_tap')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->boolean('enable_tap')->default(true);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateway_credentials', function (Blueprint $table) {
            $cols = [
                'tap_status',
                'tap_mode',
                'tap_merchant_id',
                'live_tap_secret_key',
                'live_tap_public_key',
                'test_tap_secret_key',
                'test_tap_public_key',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('payment_gateway_credentials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('tap_payments');

        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'enable_tap')) {
                $table->dropColumn('enable_tap');
            }
        });
    }
};
