<?php

namespace App\Livewire\Dashboard;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TodayPaymentMethodEarnings extends Component
{

    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $paymentMethods = Payment::join('orders', 'payments.order_id', '=', 'orders.id')
            ->where('payments.payment_method', '<>', 'due')
            ->where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC)
            ->select('payments.payment_method', DB::raw('SUM(payments.amount) as total_amount'))
            ->groupBy('payments.payment_method')
            ->get()->sortBy('total_amount', SORT_REGULAR, true);

        return view('livewire.dashboard.today-payment-method-earnings', ['paymentMethods' => $paymentMethods]);
    }

}
