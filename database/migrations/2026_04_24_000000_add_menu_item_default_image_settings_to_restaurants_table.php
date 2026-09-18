<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants', 'menu_item_default_image_path')) {
                $table->string('menu_item_default_image_path')->nullable()->after('hide_menu_item_image_on_customer_site');
            }

            if (!Schema::hasColumn('restaurants', 'disable_menu_item_default_image')) {
                $table->boolean('disable_menu_item_default_image')->default(false)->after('menu_item_default_image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('restaurants', 'disable_menu_item_default_image')) {
                $table->dropColumn('disable_menu_item_default_image');
            }

            if (Schema::hasColumn('restaurants', 'menu_item_default_image_path')) {
                $table->dropColumn('menu_item_default_image_path');
            }
        });
    }
};

