<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_cash_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_executive_id')->constrained()->cascadeOnDelete();
            $table->string('settlement_number')->nullable()->unique();
            $table->decimal('submitted_amount', 16, 2);
            $table->decimal('verified_amount', 16, 2)->nullable();
            $table->enum('status', ['submitted', 'approved', 'rejected'])->default('submitted');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->index(['delivery_executive_id', 'status'], 'dcs_exec_status_idx');
        });

        Schema::create('delivery_cash_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('delivery_cash_settlements')->cascadeOnDelete();
            $table->foreignId('order_cash_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 16, 2);
            $table->timestamps();

            $table->unique(['settlement_id', 'order_cash_collection_id'], 'dcsi_settlement_collection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_cash_settlement_items');
        Schema::dropIfExists('delivery_cash_settlements');
    }
};
