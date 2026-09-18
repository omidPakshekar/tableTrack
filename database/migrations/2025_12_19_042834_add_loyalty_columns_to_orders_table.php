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
        Schema::table('orders', function (Blueprint $table) {
            // customer_id might already exist, so check first
            if (!Schema::hasColumn('orders', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'loyalty_points_redeemed')) {
                $table->integer('loyalty_points_redeemed')->default(0);
            }
            if (!Schema::hasColumn('orders', 'loyalty_discount_amount')) {
                $table->decimal('loyalty_discount_amount', 10, 2)->default(0);
            }
        });

        // Add stamp redemption tracking to orders
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'stamp_discount_amount')) {
                $table->decimal('stamp_discount_amount', 10, 2)->default(0)->after('loyalty_discount_amount');
            }
        });

        // Add stamp redemption tracking to order_items
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'is_free_item_from_stamp')) {
                $table->boolean('is_free_item_from_stamp')->default(false)->after('note');
            }
            if (!Schema::hasColumn('order_items', 'stamp_rule_id')) {
                $table->foreignId('stamp_rule_id')->nullable()->after('is_free_item_from_stamp')->nullOnDelete();
            }
        });

        Schema::table('kot_items', function (Blueprint $table) {
            // Add price and amount columns to store item pricing information (only if they don't exist)
            if (!Schema::hasColumn('kot_items', 'price')) {
                $table->decimal('price', 16, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('kot_items', 'amount')) {
                $table->decimal('amount', 16, 2)->nullable()->after('price');
            }
            
            // Add loyalty-related columns (only if they don't exist)
            if (!Schema::hasColumn('kot_items', 'is_free_item_from_stamp')) {
                $table->boolean('is_free_item_from_stamp')->default(false)->after('amount');
            }
            if (!Schema::hasColumn('kot_items', 'stamp_rule_id')) {
                $table->unsignedBigInteger('stamp_rule_id')->nullable()->after('is_free_item_from_stamp');
            }
            if (!Schema::hasColumn('kot_items', 'discount_amount')) {
                $table->decimal('discount_amount', 16, 2)->nullable()->default(0)->after('stamp_rule_id');
            }
            if (!Schema::hasColumn('kot_items', 'is_discounted')) {
                $table->boolean('is_discounted')->default(false)->after('discount_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'loyalty_points_redeemed')) {
                $table->dropColumn('loyalty_points_redeemed');
            }
            if (Schema::hasColumn('orders', 'loyalty_discount_amount')) {
                $table->dropColumn('loyalty_discount_amount');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'stamp_discount_amount')) {
                $table->dropColumn('stamp_discount_amount');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'stamp_rule_id')) {
                $table->dropForeign(['stamp_rule_id']);
                $table->dropColumn('stamp_rule_id');
            }
            if (Schema::hasColumn('order_items', 'is_free_item_from_stamp')) {
                $table->dropColumn('is_free_item_from_stamp');
            }
        });

        Schema::table('kot_items', function (Blueprint $table) {
            // Drop foreign key if it exists
            try {
                $table->dropForeign(['stamp_rule_id']);
            } catch (\Exception $e) {
                // Foreign key might not exist, ignore
            }
            
            // Drop columns only if they exist
            $columnsToDrop = [];
            if (Schema::hasColumn('kot_items', 'price')) {
                $columnsToDrop[] = 'price';
            }
            if (Schema::hasColumn('kot_items', 'amount')) {
                $columnsToDrop[] = 'amount';
            }
            if (Schema::hasColumn('kot_items', 'is_free_item_from_stamp')) {
                $columnsToDrop[] = 'is_free_item_from_stamp';
            }
            if (Schema::hasColumn('kot_items', 'stamp_rule_id')) {
                $columnsToDrop[] = 'stamp_rule_id';
            }
            if (Schema::hasColumn('kot_items', 'discount_amount')) {
                $columnsToDrop[] = 'discount_amount';
            }
            if (Schema::hasColumn('kot_items', 'is_discounted')) {
                $columnsToDrop[] = 'is_discounted';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
