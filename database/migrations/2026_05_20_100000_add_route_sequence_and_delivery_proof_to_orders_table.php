<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'route_sequence')) {
                $table->unsignedSmallInteger('route_sequence')->nullable()->after('delivery_executive_id');
            }
            if (! Schema::hasColumn('orders', 'delivery_proof')) {
                $table->string('delivery_proof')->nullable()->after('route_sequence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_proof')) {
                $table->dropColumn('delivery_proof');
            }
            if (Schema::hasColumn('orders', 'route_sequence')) {
                $table->dropColumn('route_sequence');
            }
        });
    }
};
