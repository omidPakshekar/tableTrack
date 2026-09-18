<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'discount_apply_on')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('discount_apply_on')->default('sub_total')->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'discount_apply_on')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('discount_apply_on');
            });
        }
    }
};
