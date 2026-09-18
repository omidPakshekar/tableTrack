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
        Schema::table('delivery_executives', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_executives', 'email')) {
                $table->string('email')->nullable()->after('name');
                $table->unique('email', 'delivery_executives_email_unique');
            }

            if (!Schema::hasColumn('delivery_executives', 'is_online')) {
                $table->boolean('is_online')->default(false)->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_executives', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_executives', 'email')) {
                $table->dropUnique('delivery_executives_email_unique');
                $table->dropColumn('email');
            }

            if (Schema::hasColumn('delivery_executives', 'is_online')) {
                $table->dropColumn('is_online');
            }
        });
    }
};
