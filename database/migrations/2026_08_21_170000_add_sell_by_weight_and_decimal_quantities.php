<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_items') && ! Schema::hasColumn('menu_items', 'sell_by_weight')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->boolean('sell_by_weight')->default(false)->after('in_stock');
            });
        }

        // Order / KOT quantities must support fractional kg (e.g. 0.570 for 570g)
        if (Schema::hasTable('order_items')) {
            DB::statement('ALTER TABLE order_items MODIFY quantity DECIMAL(16, 3) NOT NULL');
        }

        if (Schema::hasTable('kot_items')) {
            DB::statement('ALTER TABLE kot_items MODIFY quantity DECIMAL(16, 3) NOT NULL');
        }

        if (Schema::hasTable('split_order_items') && Schema::hasColumn('split_order_items', 'quantity')) {
            DB::statement('ALTER TABLE split_order_items MODIFY quantity DECIMAL(16, 3) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_items') && Schema::hasColumn('menu_items', 'sell_by_weight')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->dropColumn('sell_by_weight');
            });
        }

        if (Schema::hasTable('order_items')) {
            DB::statement('ALTER TABLE order_items MODIFY quantity INT NOT NULL');
        }

        if (Schema::hasTable('kot_items')) {
            DB::statement('ALTER TABLE kot_items MODIFY quantity INT NOT NULL');
        }

        if (Schema::hasTable('split_order_items') && Schema::hasColumn('split_order_items', 'quantity')) {
            DB::statement('ALTER TABLE split_order_items MODIFY quantity INT NULL');
        }
    }
};
