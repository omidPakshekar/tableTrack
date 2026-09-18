<?php

namespace App\Notifications;

use App\Models\NotificationSetting;

class NewReservationForRestaurant extends BaseNotification
{
    protected $reservation;
    protected $notificationSetting;

    /**
     * Create a new notification instance.
     *
     * @param $reservation
     */
    public function __construct($reservation)
    {
        $this->reservation = $reservation;
        $this->restaurant = $reservation->branch?->restaurant;
        $this->notificationSetting = NotificationSetting::where('type', 'new_reservation')->where('restaurant_id', $reservation->branch->restaurant_id)->first();
    }

    public function via($notifiable)
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
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $build = parent::build($notifiable);
        $restaurant = $this->reservation->branch->restaurant;
        $dateFormat = $restaurant->date_format ?? dateFormat();
        $timeFormat = $restaurant->time_format ?? timeFormat();

        return $build
            ->subject(__('email.reservation.subject') . $this->reservation->reservation_date_time->translatedFormat('D, ' . $dateFormat . ', ' . $timeFormat))
            ->greeting(__('app.hello') . ' ' . $notifiable->name . ',')
            ->line(__('email.reservation.text1'))
            ->line(__('email.reservation.text2'))
            ->line(__('modules.customer.name') . ': ' . $this->reservation->customer->name)
            ->line(__('app.date') . ': ' . $this->reservation->reservation_date_time->translatedFormat($dateFormat . ' (l)'))
            ->line(__('app.time') . ': ' . $this->reservation->reservation_date_time->translatedFormat($this->reservation->branch->restaurant->time_format ?? timeFormat()))
            ->line(__('modules.reservation.guests') . ': ' . $this->reservation->party_size)
            ->action(__('email.reservation.action'), route('reservations.index'))
            ->line(__('email.reservation.text3'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'customer_name' => $this->reservation->customer->name,
            'date' => $this->reservation->date,
            'time_slot' => $this->reservation->time_slot,
            'number_of_guests' => $this->reservation->number_of_guests,
            'slot_type' => $this->reservation->slot_type,
        ];
    }
}
