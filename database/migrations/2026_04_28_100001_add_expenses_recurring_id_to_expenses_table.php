<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('expenses_recurring_id')->nullable()->after('branch_id');
            $table->foreign('expenses_recurring_id')
                ->references('id')
                ->on('expenses_recurring')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['expenses_recurring_id']);
            $table->dropColumn('expenses_recurring_id');
        });
    }
};
