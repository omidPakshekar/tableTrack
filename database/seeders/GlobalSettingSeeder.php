<?php

namespace Database\Seeders;

use App\Models\GlobalSetting;
use App\Models\GlobalCurrency;
use Illuminate\Database\Seeder;
use App\Models\StorageSetting;
use App\Models\DesktopApplication;

class GlobalSettingSeeder extends Seeder
{

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $setting = new GlobalSetting();
        $setting->name = 'TableTrack';
        $setting->theme_hex = '#A78BFA';
        $setting->theme_rgb = '167, 139, 250';
        $setting->hash = md5(microtime());
        $setting->installed_url = config('app.url');
        $setting->facebook_link = 'https://www.facebook.com/';
        $setting->instagram_link = 'https://www.instagram.com/';
        $setting->twitter_link = 'https://www.twitter.com/';
        $setting->google_business_link = 'https://business.google.com/';
        $setting->default_currency_id = GlobalCurrency::first()->id;
        $setting->timezone = 'Asia/Kolkata';
        $setting->locale = 'fa';
        $setting->save();

        StorageSetting::firstOrCreate([
            'filesystem' => 'local',
            'status' => 'enabled',
        ]);

        // Update or create the desktop application
        $desktopApplication = DesktopApplication::first();

        if (!$desktopApplication) {
            DesktopApplication::create([
                'windows_file_path' => DesktopApplication::WINDOWS_FILE_PATH,
                'mac_file_path' => DesktopApplication::MAC_FILE_PATH,
            ]);
        }
    }
}
