<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses_recurring', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade')->onUpdate('cascade');

            $table->unsignedBigInteger('expense_category_id')->nullable();
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->nullOnDelete();

            $table->string('item_name');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method')->nullable();

            $table->string('rotation', 32)->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->nullable()->default(1);
            $table->unsignedTinyInteger('day_of_week')->nullable()->default(1);
            $table->unsignedInteger('billing_cycle')->nullable();

            $table->date('issue_date');
            $table->date('next_expense_date')->nullable();

            $table->boolean('unlimited_recurring')->default(false);
            $table->boolean('immediate_expense')->default(false);

            $table->string('status', 20)->default('active');
            $table->text('description')->nullable();
            $table->string('bill')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses_recurring');
    }
};
