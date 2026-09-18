<?php

namespace App\Listeners;

use App\Events\ReservationReceived;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use App\Notifications\NewReservationForRestaurant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendReservationListener
{
    public function handle(ReservationReceived $event): void
    {
        $reservation = $event->reservation->loadMissing(['customer', 'branch.restaurant']);

        $users = User::role('Admin_'.$reservation->branch->restaurant_id)
            ->where('restaurant_id', $reservation->branch->restaurant->id)
            ->get();

        try {
            Notification::sendNow($users, new NewReservationForRestaurant($reservation));
        } catch (\Exception $e) {
            Log::error('Error sending new reservation notification: ' . $e->getMessage());
        }

        if ($users->isEmpty()) {
            return;
        }

        $pushNotification = new DashboardController();
        $pushUsersIds = [$users->pluck('id')->toArray()];
        $restaurant = $reservation->branch->restaurant;
        $dateFormat = $restaurant->date_format ?? dateFormat();
        $timeFormat = $restaurant->time_format ?? timeFormat();
        $formattedDateTime = $reservation->reservation_date_time->translatedFormat($dateFormat . ', ' . $timeFormat);
        $pushNotification->sendPushNotifications($pushUsersIds, __('email.reservation.subject'), $formattedDateTime, route('reservations.index'));
    }
}
