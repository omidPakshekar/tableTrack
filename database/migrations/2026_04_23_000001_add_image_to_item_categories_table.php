<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('item_categories', 'image')) {
                $table->string('image')->nullable()->after('category_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            if (Schema::hasColumn('item_categories', 'image')) {
                $table->dropColumn('image');
            }
        });
    }
};

