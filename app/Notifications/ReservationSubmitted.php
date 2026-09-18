<?php

namespace App\Notifications;

use App\Models\NotificationSetting;
use App\Models\Reservation;
use Illuminate\Notifications\Messages\MailMessage;

class ReservationSubmitted extends BaseNotification
{
    protected $reservation;
    protected $settings;
    protected $notificationSetting;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
        $this->settings = $reservation->branch ? $reservation->branch->restaurant : null;
        $this->restaurant = $this->settings;
        $this->notificationSetting = NotificationSetting::where('type', 'reservation_submitted')
            ->where('restaurant_id', $reservation->branch->restaurant_id)
            ->first();
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

    public function toMail(object $notifiable): MailMessage
    {
        $build = parent::build($notifiable);

        return $build
            ->subject(__('email.reservation.reservationSubmitted', ['site_name' => $this->reservation->branch->restaurant->name]))
            ->markdown('emails.table-reservation-submitted', [
                'notifiable' => $notifiable,
                'reservation' => $this->reservation,
                'settings' => $this->settings,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
