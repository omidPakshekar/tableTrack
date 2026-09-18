<?php

namespace App\Livewire\Reports;

use App\Exports\ExpenseSummaryReportExport;
use Livewire\Component;
use App\Models\Expenses;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class ExpenseSummaryReport extends Component
{

    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $totalAmount;
    public $expenses;

    public function mount()
    {
           abort_if(!in_array('Report', restaurant_modules()), 403);
           abort_if((!user_can('Show Reports')), 403);

           $tz = timezone();
           $dateFormat = restaurant()->date_format ?? 'd-m-Y';

           $this->dateRangeType = 'currentWeek';
           $this->startDate = Carbon::now($tz)->startOfWeek()->displayDate($dateFormat);
           $this->endDate = Carbon::now($tz)->endOfWeek()->displayDate($dateFormat);
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
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
        }
        else {
            return Excel::download(new ExpenseSummaryReportExport($this->startDate, $this->endDate), 'item-report-' . now()->toDateTimeString() . '.xlsx');
        }
    }

    public function render()
    {
         $tz = timezone();
         $dateFormat = restaurant()->date_format ?? 'd-m-Y';

         $start = parseRestaurantDate($this->startDate, $dateFormat, $tz)->startOfDay();
        $end = parseRestaurantDate($this->endDate, $dateFormat, $tz)->endOfDay();

        $this->expenses = Expenses::with(['category'])
            ->whereBetween('expense_date', [$start, $end])
            ->selectRaw('expense_category_id, SUM(amount) as total_amount')
            ->groupBy('expense_category_id')
            ->get();

        $this->totalAmount = $this->expenses->sum('total_amount');
        return view('livewire.reports.expense-summary-report', [
        'expenses' => $this->expenses
        ]);
    }

}
