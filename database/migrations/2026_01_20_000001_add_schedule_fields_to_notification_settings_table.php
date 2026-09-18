<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_settings', 'send_time')) {
                // Daily send time (HH:MM:SS) for scheduled notifications
                $table->time('send_time')->nullable()->after('send_email');
            }

            if (!Schema::hasColumn('notification_settings', 'last_sent_at')) {
                // Track last time we sent (to avoid duplicate sends)
                $table->timestamp('last_sent_at')->nullable()->after('send_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            if (Schema::hasColumn('notification_settings', 'last_sent_at')) {
                $table->dropColumn('last_sent_at');
            }

            if (Schema::hasColumn('notification_settings', 'send_time')) {
                $table->dropColumn('send_time');
            }
        });
    }
};


