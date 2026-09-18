<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_items')) {
            Schema::table('menu_items', function (Blueprint $table) {
                if (! Schema::hasColumn('menu_items', 'batch_recipe_id')) {
                    $column = $table->unsignedBigInteger('batch_recipe_id')->nullable();

                    if (Schema::hasColumn('menu_items', 'in_stock')) {
                        $column->after('in_stock');
                    }
                }

                if (! Schema::hasColumn('menu_items', 'batch_serving_size')) {
                    $column = $table->decimal('batch_serving_size', 16, 4)->nullable();

                    if (Schema::hasColumn('menu_items', 'batch_recipe_id')) {
                        $column->after('batch_recipe_id');
                    }
                }
            });

            if (
                Schema::hasTable('batch_recipes')
                && Schema::hasColumn('menu_items', 'batch_recipe_id')
                && ! $this->foreignKeyExists('menu_items', 'menu_items_batch_recipe_id_foreign')
            ) {
                Schema::table('menu_items', function (Blueprint $table) {
                    $table->foreign('batch_recipe_id')
                        ->references('id')
                        ->on('batch_recipes')
                        ->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('menu_item_variations')) {
            Schema::table('menu_item_variations', function (Blueprint $table) {
                if (! Schema::hasColumn('menu_item_variations', 'batch_recipe_id')) {
                    $table->unsignedBigInteger('batch_recipe_id')->nullable()->after('price');
                }

                if (! Schema::hasColumn('menu_item_variations', 'batch_serving_size')) {
                    $column = $table->decimal('batch_serving_size', 16, 4)->nullable();

                    if (Schema::hasColumn('menu_item_variations', 'batch_recipe_id')) {
                        $column->after('batch_recipe_id');
                    }
                }
            });

            if (
                Schema::hasTable('batch_recipes')
                && Schema::hasColumn('menu_item_variations', 'batch_recipe_id')
                && ! $this->foreignKeyExists('menu_item_variations', 'menu_item_variations_batch_recipe_id_foreign')
            ) {
                Schema::table('menu_item_variations', function (Blueprint $table) {
                    $table->foreign('batch_recipe_id')
                        ->references('id')
                        ->on('batch_recipes')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_items')) {
            Schema::table('menu_items', function (Blueprint $table) {
                if (
                    Schema::hasColumn('menu_items', 'batch_recipe_id')
                    && Schema::hasTable('batch_recipes')
                    && $this->foreignKeyExists('menu_items', 'menu_items_batch_recipe_id_foreign')
                ) {
                    $table->dropForeign(['batch_recipe_id']);
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn('menu_items', 'batch_serving_size') ? 'batch_serving_size' : null,
                    Schema::hasColumn('menu_items', 'batch_recipe_id') ? 'batch_recipe_id' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('menu_item_variations')) {
            Schema::table('menu_item_variations', function (Blueprint $table) {
                if (
                    Schema::hasColumn('menu_item_variations', 'batch_recipe_id')
                    && Schema::hasTable('batch_recipes')
                    && $this->foreignKeyExists('menu_item_variations', 'menu_item_variations_batch_recipe_id_foreign')
                ) {
                    $table->dropForeign(['batch_recipe_id']);
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn('menu_item_variations', 'batch_serving_size') ? 'batch_serving_size' : null,
                    Schema::hasColumn('menu_item_variations', 'batch_recipe_id') ? 'batch_recipe_id' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        $result = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY']
        );

        return $result !== null;
    }
};
