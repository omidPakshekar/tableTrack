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
            if (!Schema::hasColumn('superadmin_payment_gateways', 'mollie_status')) {
                $table->boolean('mollie_status')->default(false);
            }
            if (!Schema::hasColumn('superadmin_payment_gateways', 'mollie_mode')) {
                $table->enum('mollie_mode', ['test', 'live'])->default('test');
            }
            if (!Schema::hasColumn('superadmin_payment_gateways', 'test_mollie_key')) {
                $table->text('test_mollie_key')->nullable();
            }
            if (!Schema::hasColumn('superadmin_payment_gateways', 'live_mollie_key')) {
                $table->text('live_mollie_key')->nullable();
            }
        });

        Schema::table('restaurants', function (Blueprint $table) {
          if (!Schema::hasColumn('restaurants', 'mollie_customer_id')) {
            $table->string('mollie_customer_id')->nullable();
          }
        });

        Schema::table('restaurant_payments', function (Blueprint $table) {

           if (!Schema::hasColumn('restaurant_payments', 'mollie_payment_id')) {
            $table->string('mollie_payment_id')->nullable();
           }
           if (!Schema::hasColumn('restaurant_payments', 'mollie_customer_id')) {
            $table->string('mollie_customer_id')->nullable();
           }
           if (!Schema::hasColumn('restaurant_payments', 'mollie_subscription_id')) {
            $table->string('mollie_subscription_id')->nullable();
           }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('superadmin_payment_gateways', function (Blueprint $table) {
            if (Schema::hasColumn('superadmin_payment_gateways', 'mollie_status')) {
                $table->dropColumn('mollie_status');
            }
            if (Schema::hasColumn('superadmin_payment_gateways', 'mollie_mode')) {
                $table->dropColumn('mollie_mode');
            }
            if (Schema::hasColumn('superadmin_payment_gateways', 'test_mollie_key')) {
                $table->dropColumn('test_mollie_key');
            }
            if (Schema::hasColumn('superadmin_payment_gateways', 'live_mollie_key')) {
                $table->dropColumn('live_mollie_key');
            }
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('mollie_customer_id');
        });

        Schema::table('restaurant_payments', function (Blueprint $table) {
            $table->dropColumn('mollie_payment_id');
            $table->dropColumn('mollie_customer_id');
            $table->dropColumn('mollie_subscription_id');
        });
    }
};
