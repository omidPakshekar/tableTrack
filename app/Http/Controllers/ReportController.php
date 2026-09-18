<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function itemReport()
    {
        return view('reports.items');
    }

    public function categoryReport()
    {
        return view('reports.category');
    }

    public function salesReport()
    {
        return view('reports.sales');
    }

    public function expenseReport()
    {
        return view('reports.expense-reports');
    }

    public function outstandingPaymentReport()
    {
        return view('reports.outstanding-payment');
    }

    public function duePaymentReceivedReport()
    {
        return view('reports.due-payments-received-report');
    }

    public function expenseSummaryReport()
    {
        return view('reports.expense-summary');
    }

    public function printLog()
    {
        return view('reports.print-log');
    }

    public function deliveryReport()
    {
        return view('reports.delivery-app-report');
    }

    public function codReport()
    {
        return view('reports.cod-report');
    }

    public function cancelledOrderReport()
    {
        return view('reports.cancelled-order');
    }

    public function removedKotItemReport()
    {
        return view('reports.removed-kot-item');
    }

    public function deletedOrderReport()
    {
        return view('reports.deleted-order');
    }

    public function taxReport()
    {
        return view('reports.tax');
    }

    public function refundReport()
    {
        return view('reports.refund');
    }

    public function orderReport()
    {
        return view('reports.order-report');
    }
}
