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
        Schema::create('refund_reasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade')->onUpdate('cascade');
            $table->text('reason');
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade')->onUpdate('cascade');
            
            $table->unsignedBigInteger('payment_id');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade')->onUpdate('cascade');
            
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade')->onUpdate('cascade');

            $table->unsignedBigInteger('delivery_app_id')->nullable();
            $table->foreign('delivery_app_id')->references('id')->on('delivery_platforms')->onDelete('cascade')->onUpdate('cascade');
            
            $table->unsignedBigInteger('refund_reason_id')->nullable();
            $table->foreign('refund_reason_id')->references('id')->on('refund_reasons')->onDelete('set null')->onUpdate('cascade');
            
            $table->enum('refund_type', ['full', 'partial', 'waste'])->default('full');
            $table->enum('partial_refund_type', ['half', 'fixed', 'custom'])->nullable();
            $table->decimal('amount', 16, 2);
            $table->decimal('commission_adjustment', 16, 2)->nullable();
            
            $table->enum('status', ['pending', 'processed', 'failed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->foreign('processed_by')->references('id')->on('users')->onDelete('set null')->onUpdate('cascade');
            $table->timestamp('processed_at')->nullable();
            
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_reasons');
        Schema::dropIfExists('refunds');
    }
};

