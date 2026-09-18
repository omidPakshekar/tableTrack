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
        if (!Schema::hasColumn('payment_gateway_credentials', 'tlync_status')) {
            Schema::table('payment_gateway_credentials', function (Blueprint $table) {
                $table->boolean('tlync_status')->default(false);
                $table->enum('tlync_mode', ['test', 'live'])->default('test');
                $table->string('test_tlync_store_id')->nullable();
                $table->text('test_tlync_store_token')->nullable();
                $table->string('live_tlync_store_id')->nullable();
                $table->text('live_tlync_store_token')->nullable();
            });
        }

        if (!Schema::hasTable('tlync_payments')) {
            Schema::create('tlync_payments', function (Blueprint $table) {
                $table->id();
                $table->string('tlync_payment_id')->nullable();
                $table->unsignedBigInteger('order_id');
                $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
                $table->decimal('amount', 10, 2);
                $table->string('phone')->nullable();
                $table->string('custom_ref')->nullable();
                $table->enum('payment_status', ['pending', 'completed', 'failed'])->default('pending');
                $table->timestamp('payment_date')->nullable();
                $table->json('payment_error_response')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('global_settings', 'enable_tlync')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->boolean('enable_tlync')->default(true);
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
                'tlync_status',
                'tlync_mode',
                'test_tlync_store_id',
                'test_tlync_store_token',
                'live_tlync_store_id',
                'live_tlync_store_token',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('payment_gateway_credentials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('tlync_payments');

        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'enable_tlync')) {
                $table->dropColumn('enable_tlync');
            }
        });
    }
};
