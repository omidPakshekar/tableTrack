<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'show_payment_qr_on_customer_display')) {
                $table->boolean('show_payment_qr_on_customer_display')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('restaurants', 'show_payment_qr_on_customer_display')) {
                $table->dropColumn('show_payment_qr_on_customer_display');
            }
        });
    }
};
