<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds indexes to optimize queries in the POS Livewire component.
     * Based on analysis of app/Livewire/Pos/Pos.php queries.
     */
    public function up(): void
    {
        // Indexes for orders table
        Schema::table('orders', function (Blueprint $table) {
            // Composite index for queries filtering by branch, table, and status
            // Used in: openMergeTableModal(), mergeSelectedTables()
            if (!$this->hasIndex('orders', 'idx_orders_branch_table_status')) {
                $table->index(['branch_id', 'table_id', 'status'], 'idx_orders_branch_table_status');
            }
            
            // Composite index for table-specific order queries
            // Used in: mergeSelectedTables()
            if (!$this->hasIndex('orders', 'idx_orders_table_branch_status')) {
                $table->index(['table_id', 'branch_id', 'status'], 'idx_orders_table_branch_status');
            }
            
            // Index for status-based queries with branch
            // Used in: openMergeTableModal()
            if (!$this->hasIndex('orders', 'idx_orders_status_branch')) {
                $table->index(['status', 'branch_id'], 'idx_orders_status_branch');
            }
            
            // Index for order type queries
            // Used in: menuItems() computed property
            if (!$this->hasIndex('orders', 'idx_orders_order_type_branch')) {
                $table->index(['order_type_id', 'branch_id'], 'idx_orders_order_type_branch');
            }
            
            // Index for delivery app queries
            // Used in: various price context queries
            if (!$this->hasIndex('orders', 'idx_orders_delivery_app')) {
                $table->index('delivery_app_id', 'idx_orders_delivery_app');
            }
        });

        // Indexes for menu_item_prices table
        Schema::table('menu_item_prices', function (Blueprint $table) {
            // Composite index for price lookups with all filters
            // Used in: addCartItems(), menuItems() - prices relationship queries
            if (!$this->hasIndex('menu_item_prices', 'idx_prices_item_status_variation_type_app')) {
                $table->index(
                    ['menu_item_id', 'status', 'menu_item_variation_id', 'order_type_id', 'delivery_app_id'],
                    'idx_prices_item_status_variation_type_app'
                );
            }
            
            // Composite index for item-level prices (no variation)
            // Used in: prices relationship queries where menu_item_variation_id is null
            if (!$this->hasIndex('menu_item_prices', 'idx_prices_item_status_type_app')) {
                $table->index(
                    ['menu_item_id', 'status', 'order_type_id', 'delivery_app_id'],
                    'idx_prices_item_status_type_app'
                );
            }
            
            // Index for filtering by order type and delivery app
            // Used in: prices relationship queries
            if (!$this->hasIndex('menu_item_prices', 'idx_prices_type_app_status')) {
                $table->index(['order_type_id', 'delivery_app_id', 'status'], 'idx_prices_type_app_status');
            }
            
            // Index for menu_item_id lookups (most common filter)
            if (!$this->hasIndex('menu_item_prices', 'idx_prices_menu_item_id')) {
                $table->index('menu_item_id', 'idx_prices_menu_item_id');
            }
        });

        // Indexes for menu_items table
        Schema::table('menu_items', function (Blueprint $table) {
            // Index for category filtering
            // Used in: menuItems() computed property
            if (!$this->hasIndex('menu_items', 'idx_menu_items_category')) {
                $table->index('item_category_id', 'idx_menu_items_category');
            }
            
            // Index for menu filtering
            // Used in: menuItems() computed property
            if (!$this->hasIndex('menu_items', 'idx_menu_items_menu')) {
                $table->index('menu_id', 'idx_menu_items_menu');
            }
            
            // Composite index for combined menu and category filtering
            // Used in: menuItems() computed property
            if (!$this->hasIndex('menu_items', 'idx_menu_items_menu_category')) {
                $table->index(['menu_id', 'item_category_id'], 'idx_menu_items_menu_category');
            }
        });

        // Indexes for kot_items table
        Schema::table('kot_items', function (Blueprint $table) {
            // Composite index for KOT item queries with status
            // Used in: setupOrderItems(), deleteOrderItems()
            if (!$this->hasIndex('kot_items', 'idx_kot_items_kot_status')) {
                $table->index(['kot_id', 'status'], 'idx_kot_items_kot_status');
            }
            
            // Composite index for matching order items
            // Used in: deleteOrderItems()
            if (!$this->hasIndex('kot_items', 'idx_kot_items_menu_variation_qty')) {
                $table->index(['menu_item_id', 'menu_item_variation_id', 'quantity'], 'idx_kot_items_menu_variation_qty');
            }
            
            // Index for order_item_id relationship
            // Used in: orderItem relationship
            if (!$this->hasIndex('kot_items', 'idx_kot_items_order_item')) {
                $table->index('order_item_id', 'idx_kot_items_order_item');
            }
        });

        // Indexes for order_items table
        Schema::table('order_items', function (Blueprint $table) {
            // Composite index for order item queries
            // Used in: deleteOrderItems(), setupOrderItems()
            if (!$this->hasIndex('order_items', 'idx_order_items_order_menu_variation')) {
                $table->index(['order_id', 'menu_item_id', 'menu_item_variation_id'], 'idx_order_items_order_menu_variation');
            }
            
            // Index for order_id (already has foreign key, but explicit index helps)
            // Used in: various order item queries
            if (!$this->hasIndex('order_items', 'idx_order_items_order_id')) {
                $table->index('order_id', 'idx_order_items_order_id');
            }
        });

        // Indexes for tables table
        Schema::table('tables', function (Blueprint $table) {
            // Composite index for table lookups by branch and code
            // Used in: setTable(), openMergeTableModal()
            if (!$this->hasIndex('tables', 'idx_tables_branch_code')) {
                $table->index(['branch_id', 'table_code'], 'idx_tables_branch_code');
            }
            
            // Composite index for available table queries
            // Used in: table availability queries
            if (!$this->hasIndex('tables', 'idx_tables_branch_status')) {
                $table->index(['branch_id', 'available_status'], 'idx_tables_branch_status');
            }
        });

        // Indexes for kots table
        Schema::table('kots', function (Blueprint $table) {
            // Composite index for KOT queries by order and status
            // Used in: setupOrderItems(), deleteMergedTableOrders()
            if (!$this->hasIndex('kots', 'idx_kots_order_status')) {
                $table->index(['order_id', 'status'], 'idx_kots_order_status');
            }
            
            // Index for branch_id queries
            // Used in: generateKotNumber()
            if (!$this->hasIndex('kots', 'idx_kots_branch')) {
                $table->index('branch_id', 'idx_kots_branch');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kots', function (Blueprint $table) {
            $table->dropIndex('idx_kots_branch');
            $table->dropIndex('idx_kots_order_status');
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex('idx_tables_branch_status');
            $table->dropIndex('idx_tables_branch_code');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('idx_order_items_order_id');
            $table->dropIndex('idx_order_items_order_menu_variation');
        });

        Schema::table('kot_items', function (Blueprint $table) {
            $table->dropIndex('idx_kot_items_order_item');
            $table->dropIndex('idx_kot_items_menu_variation_qty');
            $table->dropIndex('idx_kot_items_kot_status');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex('idx_menu_items_menu_category');
            $table->dropIndex('idx_menu_items_menu');
            $table->dropIndex('idx_menu_items_category');
        });

        Schema::table('menu_item_prices', function (Blueprint $table) {
            $table->dropIndex('idx_prices_menu_item_id');
            $table->dropIndex('idx_prices_type_app_status');
            $table->dropIndex('idx_prices_item_status_type_app');
            $table->dropIndex('idx_prices_item_status_variation_type_app');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_delivery_app');
            $table->dropIndex('idx_orders_order_type_branch');
            $table->dropIndex('idx_orders_status_branch');
            $table->dropIndex('idx_orders_table_branch_status');
            $table->dropIndex('idx_orders_branch_table_status');
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $database = $connection->getDatabaseName();
            
            $result = $connection->select(
                "SELECT COUNT(*) as count FROM information_schema.statistics 
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$database, $table, $index]
            );
            
            return isset($result[0]) && $result[0]->count > 0;
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist and let the database handle duplicate errors
            return false;
        }
    }
};
