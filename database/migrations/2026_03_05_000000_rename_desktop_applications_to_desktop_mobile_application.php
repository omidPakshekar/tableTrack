<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\DesktopApplication;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('desktop_applications') && !Schema::hasTable('desktop_mobile_application')) {
            Schema::rename('desktop_applications', 'desktop_mobile_application');
        }

        if (Schema::hasTable('desktop_mobile_application')) {
            Schema::table('desktop_mobile_application', function (Blueprint $table) {
                if (!Schema::hasColumn('desktop_mobile_application', 'partner_app_ios')) {
                    $table->string('partner_app_ios')->nullable()->after('linux_file_path');
                }
                if (!Schema::hasColumn('desktop_mobile_application', 'partner_app_android')) {
                    $table->string('partner_app_android')->nullable()->after('partner_app_ios');
                }
            });
        }

        // Update or create the desktop application
        $desktopApplication = DesktopApplication::first();

        if ($desktopApplication) {
            $desktopApplication->partner_app_ios = DesktopApplication::PARTNER_APP_IOS_URL;
            $desktopApplication->partner_app_android = DesktopApplication::PARTNER_APP_ANDROID_URL;
            $desktopApplication->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('desktop_mobile_application')) {
            Schema::table('desktop_mobile_application', function (Blueprint $table) {
                if (Schema::hasColumn('desktop_mobile_application', 'partner_app_ios')) {
                    $table->dropColumn('partner_app_ios');
                }
                if (Schema::hasColumn('desktop_mobile_application', 'partner_app_android')) {
                    $table->dropColumn('partner_app_android');
                }
            });
            Schema::rename('desktop_mobile_application', 'desktop_applications');
        }
    }
};
