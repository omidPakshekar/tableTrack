<?php

namespace App\Livewire\Reports;

use App\Exports\DuePaymentReceivedReportExport;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\Carbon;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class DuePaymentReceivedReport extends Component
{
    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $filterCustomer;
    public $payments;
    public $totalAmount;
    public $customers;

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $this->dateRangeType = 'currentWeek';
        $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
        $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
        $this->filterCustomer = '';

        // Load all customers from restaurant
        $this->customers = Customer::where('restaurant_id', restaurant()->id)
            ->orderBy('name')
            ->get();
    }

    public function setDateRange()
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        switch ($this->dateRangeType) {
            case 'today':
                $this->startDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentWeek':
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;

            case 'lastWeek':
                $this->startDate = Carbon::now($tz)->subWeek()->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subWeek()->endOfWeek()->displayDate($dateFormat);
                break;

            case 'last7Days':
                $this->startDate = Carbon::now($tz)->subDays(7)->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'currentMonth':
                $this->startDate = Carbon::now($tz)->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'lastMonth':
                $this->startDate = Carbon::now($tz)->subMonth()->startOfMonth()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subMonth()->endOfMonth()->displayDate($dateFormat);
                break;

            case 'currentYear':
                $this->startDate = Carbon::now($tz)->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->startOfDay()->displayDate($dateFormat);
                break;

            case 'lastYear':
                $this->startDate = Carbon::now($tz)->subYear()->startOfYear()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->subYear()->endOfYear()->displayDate($dateFormat);
                break;

            default:
                $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
                $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
                break;
        }
    }

    public function exportReport()
    {
        return Excel::download(
            new DuePaymentReceivedReportExport($this->startDate, $this->endDate, $this->filterCustomer),
            'due-payment-received-report-' . now()->toDateTimeString() . '.xlsx'
        );
    }

    public function render()
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $start = parseRestaurantDate($this->startDate, $dateFormat, $tz)->startOfDay();
        $end = parseRestaurantDate($this->endDate, $dateFormat, $tz)->endOfDay();

        $query = Payment::with(['order.customer'])
            ->whereNotNull('due_amount_received')
            ->where('due_amount_received', '>', 0)
            ->whereBetween('created_at', [$start, $end]);

        // Apply customer filter if selected
        if ($this->filterCustomer) {
            $query->whereHas('order', function($q) {
                $q->where('customer_id', $this->filterCustomer);
            });
        }

        $this->payments = $query->orderBy('created_at', 'desc')->get();

        $this->totalAmount = $this->payments->sum('due_amount_received');

        return view('livewire.reports.due-payment-received-report', [
            'payments' => $this->payments,
            'customers' => $this->customers
        ]);
    }
}
