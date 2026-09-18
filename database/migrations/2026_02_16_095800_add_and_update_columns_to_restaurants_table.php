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
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('auto_confirm_orders_before_payment')->default(false)->after('auto_confirm_orders');

            $table->boolean('auto_confirm_orders_after_payment')->default(false)->after('auto_confirm_orders_before_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('auto_confirm_orders_before_payment');
            $table->dropColumn('auto_confirm_orders_after_payment');
        });
    }
};
