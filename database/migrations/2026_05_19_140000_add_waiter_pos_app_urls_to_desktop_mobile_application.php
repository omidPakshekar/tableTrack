<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desktop_mobile_application')) {
            return;
        }

        Schema::table('desktop_mobile_application', function (Blueprint $table) {
            if (! Schema::hasColumn('desktop_mobile_application', 'waiter_pos_app_ios')) {
                $table->string('waiter_pos_app_ios')->nullable()->after('partner_app_android');
            }
            if (! Schema::hasColumn('desktop_mobile_application', 'waiter_pos_app_android')) {
                $table->string('waiter_pos_app_android')->nullable()->after('waiter_pos_app_ios');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('desktop_mobile_application')) {
            return;
        }

        Schema::table('desktop_mobile_application', function (Blueprint $table) {
            if (Schema::hasColumn('desktop_mobile_application', 'waiter_pos_app_ios')) {
                $table->dropColumn('waiter_pos_app_ios');
            }
            if (Schema::hasColumn('desktop_mobile_application', 'waiter_pos_app_android')) {
                $table->dropColumn('waiter_pos_app_android');
            }
        });
    }
};
