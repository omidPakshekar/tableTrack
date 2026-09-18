<?php

namespace App\Http\Controllers;

class RecurringExpenseController extends Controller
{
    public function index()
    {
        return view('payments.recurring-expenses');
    }
}
