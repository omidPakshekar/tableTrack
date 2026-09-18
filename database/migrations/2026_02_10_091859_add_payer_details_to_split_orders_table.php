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
            $table->string('payer_name')->nullable()->after('payment_method')->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('split_orders', function (Blueprint $table) {
            $table->dropColumn(['payer_name']);
        });
    }
};
