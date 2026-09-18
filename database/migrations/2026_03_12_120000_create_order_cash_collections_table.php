<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cash_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('delivery_executive_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 16, 2)->default(0);
            $table->decimal('collected_amount', 16, 2)->nullable();
            $table->enum('status', [
                'pending_collection',
                'collected',
                'partial',
                'not_collected',
                'submitted',
                'settled',
            ])->default('pending_collection');
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestamps();

            $table->index(['delivery_executive_id', 'status'], 'occ_exec_status_idx');
            $table->index(['branch_id', 'status'], 'occ_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cash_collections');
    }
};
