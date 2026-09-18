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
            $table->enum('waiter_response', ['pending', 'accepted', 'declined'])->default('pending')->after('waiter_id');
            $table->timestamp('waiter_notification_sent_at')->nullable()->after('waiter_response');
            $table->timestamp('waiter_response_at')->nullable()->after('waiter_notification_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['waiter_response', 'waiter_notification_sent_at', 'waiter_response_at']);
        });
    }
};

