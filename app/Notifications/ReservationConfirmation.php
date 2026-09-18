<?php

namespace App\Notifications;

use App\Models\NotificationSetting;
use App\Models\Reservation;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationConfirmation extends BaseNotification
{
    protected $reservation;
    protected $settings;
    protected $notificationSetting;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
        $this->settings = $reservation->branch ? $reservation->branch->restaurant : null;
        $this->restaurant = $this->settings;
        $this->notificationSetting = NotificationSetting::where('type', 'reservation_confirmed')->where('restaurant_id', $reservation->branch->restaurant_id)->first();
    }

    public function via(object $notifiable): array
    {
        if ($notifiable->email == '') {
            return [];
        }

        if ($this->notificationSetting && $this->notificationSetting->send_email != 1) {
            return [];
        }

        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $build = parent::build($notifiable);
        return $build
            ->subject(__('email.reservation.reservationConfirmation', ['site_name' => $this->reservation->branch->restaurant->name]))
            ->markdown('emails.table-reservation', [
                'notifiable' => $notifiable,
                'reservation' => $this->reservation,
                'settings' => $this->settings,
            ]);
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
