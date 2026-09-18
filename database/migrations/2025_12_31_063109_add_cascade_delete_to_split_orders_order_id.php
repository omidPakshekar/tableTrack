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
        Schema::table('split_orders', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['order_id']);
            
            // Recreate it with cascade delete
            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('split_orders', function (Blueprint $table) {
            // Drop the cascade foreign key
            $table->dropForeign(['order_id']);
            
            // Recreate it without cascade delete (original state)
            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders');
        });
    }
};
