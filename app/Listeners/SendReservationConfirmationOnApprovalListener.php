<?php

namespace App\Listeners;

use App\Events\ReservationConfirmationSent;
use App\Events\ReservationStatusUpdated;

class SendReservationConfirmationOnApprovalListener
{
    public function handle(ReservationStatusUpdated $event): void
    {
        if ($event->reservation->reservation_status !== 'Confirmed') {
            return;
        }

        if ($event->previousStatus === 'Confirmed') {
            return;
        }

        $reservation = $event->reservation->loadMissing(['customer', 'branch.restaurant']);

        ReservationConfirmationSent::dispatch($reservation);
    }
}
