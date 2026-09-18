<?php

namespace App\Console\Commands;

use App\Models\NotificationSetting;
use App\Models\Restaurant;
use App\Notifications\SendMenuPdf;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMenuPdfDaily extends Command
{
    protected $signature = 'app:send-menu-pdf-daily';
    protected $description = 'Send Menu PDF emails daily at the configured time per restaurant';

    public function handle()
    {
        // Fetch menu_pdf_sent notification settings that are enabled and have a time
        $settingsQuery = NotificationSetting::with('restaurant')
            ->where('type', 'menu_pdf_sent')
            ->where('send_email', 1)
            ->whereNotNull('send_time');

        $totalSettings = $settingsQuery->count();
        $this->info("Found {$totalSettings} notification settings with menu_pdf_sent enabled");

        if ($totalSettings === 0) {
            $this->warn('No notification settings found. Please check your notification settings.');
            return Command::SUCCESS;
        }

        $settingsQuery->chunk(200, function ($settings)     {
            foreach ($settings as $setting) {
                $restaurant = $setting->restaurant;

                if (!$restaurant) {
                    $this->warn("Setting ID {$setting->id} has no associated restaurant");
                    continue;
                }

                $tz = $restaurant->timezone ?? config('app.timezone');
                $nowTz = Carbon::now($tz);
                $scheduled = Carbon::createFromFormat('H:i:s', $setting->send_time, $tz);

                $this->info("Checking restaurant: {$restaurant->name} (ID: {$restaurant->id})");
                $this->info("  Current time (TZ: {$tz}): {$nowTz->format('Y-m-d H:i:s')}");
                $this->info("  Scheduled time: {$scheduled->format('H:i:s')}");

                    // Skip if already sent today in this timezone
                    if ($setting->last_sent_at) {
                        $lastSentTz = Carbon::parse($setting->last_sent_at)->timezone($tz);
                        $this->info("  Last sent at: {$lastSentTz->format('Y-m-d H:i:s')} (TZ: {$tz})");
                        if ($lastSentTz->isSameDay($nowTz)) {
                            $this->warn("  Skipping: Already sent today");
                            continue;
                        }
                    }

                    // Check if current time matches scheduled time
                    if ($nowTz->format('H:i') !== $scheduled->format('H:i')) {
                        $this->warn("  Skipping: Current time ({$nowTz->format('H:i')}) doesn't match scheduled time ({$scheduled->format('H:i')})");
                    continue;
                }

                $this->info("  Sending email...");
                try {
                    $this->sendForRestaurant($restaurant);
                    $setting->update(['last_sent_at' => Carbon::now()]);
                    $this->info("  ✓ Email sent successfully");
                } catch (\Exception $e) {
                    $this->error("  ✗ Error sending email: " . $e->getMessage());
                    $this->error("  Stack trace: " . $e->getTraceAsString());
                }
            }
        });

        return Command::SUCCESS;
    }

    private function sendForRestaurant(Restaurant $restaurant): void
    {
        // choose primary user (first admin) as notifiable
        $notifiable = Restaurant::restaurantAdmin($restaurant) ?? $restaurant->users()->first();
        if (!$notifiable) {
            $this->error("  ✗ No notifiable user found for restaurant ID: {$restaurant->id}");
            return;
        }

        if (empty($notifiable->email)) {
            $this->error("  ✗ User '{$notifiable->name}' (ID: {$notifiable->id}) has no email address");
            return;
        }

        $this->info("  Notifying user: {$notifiable->name} ({$notifiable->email})");

        try {
            $notifiable->notify(new SendMenuPdf(null, null, '', 'menu.pdf', $restaurant));
        } catch (\Exception $e) {
            $this->error("  ✗ Notification failed: " . $e->getMessage());
            throw $e;
        }
    }
}


