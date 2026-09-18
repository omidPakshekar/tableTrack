<?php

namespace App\Providers;

use Illuminate\Mail\MailServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * This class is used to set the SMTP configuration, push notifications, session , driver
 * and translate setting. This is done via provider so as it works during supervisor also.
 * otherwise During supervisor the database configuration in controller do not work
 */
class CustomConfigProvider extends ServiceProvider
{
    public const BOOTSTRAP_CACHE_KEY = 'custom_config_bootstrap';

    const ALL_ENVIRONMENT = ['demo', 'development'];

    /**
     * Forget cached bootstrap rows (mail, pusher, global_settings snapshot).
     * Called when EmailSetting, PusherSetting, or GlobalSetting change.
     */
    public static function forgetBootstrapCache(): void
    {
        if (app()->bound('cache')) {
            Cache::forget(self::BOOTSTRAP_CACHE_KEY);
        }
    }

    public function register()
    {
        try {
            $rows = $this->loadBootstrapRows();

            if ($rows !== null) {
                if (! empty($rows['email'])) {
                    $this->setMailConfig($rows['email'], $rows['global'] ?? null);
                }

                if (! empty($rows['pusher'])) {
                    $this->setPushNotificationConfig($rows['pusher']);
                }
            }
        } catch (\Exception $e) {
            info($e->getMessage());
        }

        $app = App::getInstance();
        $app->register(MailServiceProvider::class);
        $app->register(QueueServiceProvider::class);
        $app->register(SessionServiceProvider::class);
    }

    /**
     * Load email_settings, pusher_settings, and global_settings in one cached snapshot.
     */
    protected function loadBootstrapRows(): ?array
    {
        try {
            // During very early bootstrap (provider register phase), cache binding may not exist yet.
            // Fall back to direct DB reads to avoid "Target class [cache] does not exist." noise.
            if (!app()->bound('cache')) {
                return [
                    'email' => DB::table('email_settings')->first(),
                    'pusher' => DB::table('pusher_settings')->first(),
                    'global' => DB::table('global_settings')->first(),
                ];
            }

            return Cache::rememberForever(self::BOOTSTRAP_CACHE_KEY, function () {
                return [
                    'email' => DB::table('email_settings')->first(),
                    'pusher' => DB::table('pusher_settings')->first(),
                    'global' => DB::table('global_settings')->first(),
                ];
            });
        } catch (\Throwable $e) {
            info($e->getMessage());

            return null;
        }
    }

    public function setMailConfig($setting, $globalSetting = null): void
    {
        if ($globalSetting === null) {
            $globalSetting = DB::table('global_settings')->first();
        }

        if (! in_array(app()->environment(), self::ALL_ENVIRONMENT)) {
            $driver = ($setting->mail_driver != 'mail') ? $setting->mail_driver : 'sendmail';

            $password = $setting->mail_password;

            Config::set('mail.default', $driver);
            Config::set('mail.mailers.smtp.host', $setting->smtp_host);
            Config::set('mail.mailers.smtp.port', $setting->smtp_port);
            Config::set('mail.mailers.smtp.username', $setting->mail_username);
            Config::set('mail.mailers.smtp.password', $password);
            Config::set('mail.mailers.smtp.encryption', $setting->smtp_encryption);

            Config::set('queue.default', ($setting->enable_queue == 'yes' ? 'database' : 'sync'));
        }

        Config::set('mail.from.name', $setting->mail_from_name);
        Config::set('mail.from.address', $setting->mail_from_email);

        if ($globalSetting) {
            Config::set('app.name', $globalSetting->name);

            Config::set('app.logo', $globalSetting->logo ? asset_url_local_s3('logo/' . $globalSetting->logo) : asset('img/logo.png'));
            Config::set('session.driver', $globalSetting->session_driver ?? Config::get('session.driver'));
        }
    }

    public function setPushNotificationConfig($setting): void
    {
        if ($setting->pusher_broadcast && $setting->pusher_app_id && $setting->pusher_key && $setting->pusher_secret) {
            Config::set('broadcasting.default', 'pusher');
            Config::set('broadcasting.connections.pusher.key', $setting->pusher_key);
            Config::set('broadcasting.connections.pusher.secret', $setting->pusher_secret);
            Config::set('broadcasting.connections.pusher.app_id', $setting->pusher_app_id);
            Config::set('broadcasting.connections.pusher.options.cluster', $setting->pusher_cluster ?? '');
            Config::set('broadcasting.connections.pusher.options.host', 'api-' . ($setting->pusher_cluster ?? 'mt1') . '.pusher.com');
            Config::set('broadcasting.connections.pusher.options.port', 443);
            Config::set('broadcasting.connections.pusher.options.useTLS', true);
            // Pusher PHP only auto-upgrades to https when both scheme and port are unset; we set port
            // above, so a local .env with PUSHER_SCHEME=http would otherwise send HTTP to 443 and fail
            // with "The plain HTTP request was sent to HTTPS port".
            Config::set('broadcasting.connections.pusher.options.scheme', 'https');
            Config::set('broadcasting.connections.pusher.options.encrypted', true);
            Config::set('broadcasting.connections.pusher.options.curl_options', [
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_TIMEOUT => 30,
            ]);
        } else {
            Config::set('broadcasting.default', 'null');
        }
    }
}
