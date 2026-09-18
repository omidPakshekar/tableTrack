<?php

namespace App\Livewire\Dashboard;

use App\Models\Reservation;
use Carbon\Carbon;
use Livewire\Component;

class TodayReservations extends Component
{

    public function render()
    {
        $tz = timezone();
        $startUtc = Carbon::now($tz)->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $endUtc = Carbon::now($tz)->endOfDay()->setTimezone('UTC')->toDateTimeString();

        $count = Reservation::where('reservation_date_time', '>=', $startUtc)
            ->where('reservation_date_time', '<=', $endUtc)
            ->where('reservation_status', 'Pending')
            ->whereNull('table_id')
            ->count();

        return view('livewire.dashboard.today-reservations', ['count' => $count]);
    }

    public function refreshReservations()
    {
        $this->dispatch('$refresh');
    }
}
