<?php

namespace App\Livewire\Dashboard;

use App\Models\Order;
use Carbon\Carbon;
use Livewire\Component;

class AverageDailyEarning extends Component
{

    public $orderCount;
    public $percentChange;

    public function mount()
    {
        $tz = timezone();
        $nowLocal = Carbon::now($tz);
        $daysInMonth = max(1, (int) $nowLocal->format('d'));

        $previousMonth = $nowLocal->copy()->subMonth();
        $daysInPreviousMonth = max(1, $previousMonth->daysInMonth);

        $monthStartUtc = $nowLocal->copy()->startOfMonth()->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $monthEndUtc = $nowLocal->copy()->endOfDay()->setTimezone('UTC')->toDateTimeString();
        $prevMonthStartUtc = $previousMonth->copy()->startOfMonth()->startOfDay()->setTimezone('UTC')->toDateTimeString();
        $prevMonthEndUtc = $previousMonth->copy()->endOfMonth()->endOfDay()->setTimezone('UTC')->toDateTimeString();

        $totalEarnings = Order::where('status', 'paid')
            ->where('date_time', '>=', $monthStartUtc)
            ->where('date_time', '<=', $monthEndUtc)
            ->sum('total');

        $totalPreviousEarnings = Order::where('status', 'paid')
            ->where('date_time', '>=', $prevMonthStartUtc)
            ->where('date_time', '<=', $prevMonthEndUtc)
            ->sum('total');

        $this->orderCount = ($totalEarnings / $daysInMonth);

        $averageDailyPreviousEarnings = $totalPreviousEarnings / $daysInPreviousMonth;

        $this->percentChange = calculatePercentChange($this->orderCount, $averageDailyPreviousEarnings);
    }

    public function render()
    {
        return view('livewire.dashboard.average-daily-earning');
    }

}
