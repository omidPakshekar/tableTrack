<?php

namespace App\Notifications;

use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class SubscriptionExpire extends BaseNotification
{
    use Queueable;

    protected $restaurant;

    /**
     * The subscription end date at expiry (passed explicitly because license_expire_on may be updated before send).
     */
    protected ?Carbon $subscriptionExpiredOn = null;

    /**
     * Create a new notification instance.
     */
    public function __construct(Restaurant $restaurant, ?Carbon $subscriptionExpiredOn = null)
    {
        $this->restaurant = $restaurant;
        $this->subscriptionExpiredOn = $subscriptionExpiredOn;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }


    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $siteName = global_setting()->name;
        $build = parent::build($notifiable);

        $expiredOn = $this->subscriptionExpiredOn ?? $this->restaurant->license_expire_on;
        $formattedDate = $expiredOn
            ? Carbon::parse($expiredOn)->translatedFormat($this->restaurant->date_format ?? dateFormat())
            : '';

        return $build
            ->subject(__('email.subscriptionExpire.subject') . ' - ' . $siteName . '!')
            ->greeting(__('email.subscriptionExpire.greeting', ['name' => $notifiable->name]))
            ->line(__('email.subscriptionExpire.line1', [
                'restaurant_name' => $this->restaurant->name,
            ]))
            ->line(__('email.subscriptionExpire.line2', [
                'date' => $formattedDate,
            ]))
            ->action(__('email.subscriptionExpire.action'), route('dashboard'))
            ->line(__('email.subscriptionExpire.line3'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
