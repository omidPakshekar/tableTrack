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
        if (!Schema::hasColumn('packages', 'ai_monthly_token_limit')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->integer('ai_monthly_token_limit')->default(-1)->after('staff_limit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_token_limit');
        });
    }
};
