<?php

namespace App\Observers;

use App\Events\TodayReservationCreatedEvent;
use App\Models\Reservation;
use App\Services\Tables\TablesIndexCache;

class ReservationObserver
{
    public function creating(Reservation $reservation)
    {
        if (branch()) {
            $reservation->branch_id = branch()->id;
        }
    }

    public function saved(Reservation $reservation)
    {
        TablesIndexCache::forgetForBranch($reservation->branch_id);

        $count = Reservation::whereDate('reservation_date_time', '>=', now(timezone())->startOfDay()->toDateTimeString())
            ->whereDate('reservation_date_time', '<=', now(timezone())->endOfDay()->toDateTimeString())
            ->where('reservation_status', 'Confirmed')
            ->whereNull('table_id')
            ->count();

        event(new TodayReservationCreatedEvent($count));
    }

    public function deleted(Reservation $reservation): void
    {
        TablesIndexCache::forgetForBranch($reservation->branch_id);
    }
}
