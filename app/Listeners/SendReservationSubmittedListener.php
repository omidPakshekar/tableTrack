<?php

namespace App\Listeners;

use App\Events\ReservationSubmitted;
use App\Notifications\ReservationSubmitted as ReservationSubmittedNotification;
use Illuminate\Support\Facades\Log;

class SendReservationSubmittedListener
{
    public function handle(ReservationSubmitted $event): void
    {
        try {
            $reservation = $event->reservation->loadMissing(['customer', 'branch.restaurant']);
            $customer = $reservation->customer;

            if ($customer && $customer->email) {
                $customer->notifyNow(new ReservationSubmittedNotification($reservation));
            }
        } catch (\Exception $e) {
            Log::error('Error sending reservation submitted email: ' . $e->getMessage());
        }
    }
}
