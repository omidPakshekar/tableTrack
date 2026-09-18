<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (!Schema::hasTable('desktop_applications')) {
            Schema::create('desktop_applications', function (Blueprint $table) {
                $table->id();
                $table->string('windows_file_path')->nullable();
                $table->string('mac_intel_file_path')->nullable();
                $table->string('linux_file_path')->nullable();
                $table->string('mac_silicon_file_path')->nullable();
                $table->timestamps();
            });
        }

        // Seed default desktop application row WITHOUT using the Eloquent model.
        // The model points to `desktop_mobile_application`, which is created later
        // by the rename migration, so using it here breaks fresh migrations.
        if (!DB::table('desktop_applications')->first()) {
            DB::table('desktop_applications')->insert([
                'windows_file_path'     => DesktopApplication::WINDOWS_FILE_PATH,
                'linux_file_path'       => DesktopApplication::LINUX_FILE_PATH,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('desktop_applications');
    }
};
