<?php

namespace App\Listeners;

use App\Events\ReservationConfirmationSent;
use App\Notifications\ReservationConfirmation;
use Illuminate\Support\Facades\Log;

class SendReservationConfirmationListener
{
    public function handle(ReservationConfirmationSent $event): void
    {
        try {
            $reservation = $event->reservation->loadMissing(['customer', 'branch.restaurant']);
            $customer = $reservation->customer;

            if ($customer && $customer->email) {
                $customer->notifyNow(new ReservationConfirmation($reservation));
            }
        } catch (\Exception $e) {
            Log::error('Error sending reservation confirmation email: ' . $e->getMessage());
        }
    }
}
