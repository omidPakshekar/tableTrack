<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_order_id');
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->nullable();
            $table->string('formatted_order_number')->nullable();
            $table->uuid('uuid')->nullable();
            $table->dateTime('order_date_time')->nullable();
            $table->timestamp('order_deleted_at');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_by_name')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('table_code')->nullable();
            $table->unsignedBigInteger('waiter_id')->nullable();
            $table->string('waiter_name')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('placed_by_name')->nullable();
            $table->string('order_type')->nullable();
            $table->string('status')->nullable();
            $table->string('order_status')->nullable();
            $table->decimal('sub_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->default(0);
            $table->decimal('total_tax_amount', 16, 2)->default(0);
            $table->integer('number_of_pax')->nullable();
            $table->json('payment_methods')->nullable();
            $table->json('items')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'order_deleted_at']);
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_orders');
    }
};
