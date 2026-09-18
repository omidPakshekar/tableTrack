<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'auto_mark_order_completed_on_paid')) {
                $table->boolean('auto_mark_order_completed_on_paid')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('restaurants', 'auto_mark_order_completed_on_paid')) {
                $table->dropColumn('auto_mark_order_completed_on_paid');
            }
        });
    }
};
