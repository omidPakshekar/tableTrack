<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

use App\Models\DesktopApplication;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update the desktop application

        if (Schema::hasTable('desktop_applications') && Schema::hasColumn('desktop_applications', 'mac_file_path')) {
            $query = DB::table('desktop_applications');

            $desktopApplication = $query->whereNotNull('mac_file_path')->first();

            if ($desktopApplication) {
                $query->where('id', $desktopApplication->id)->update([
                    'mac_file_path' => DesktopApplication::MAC_FILE_PATH,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
