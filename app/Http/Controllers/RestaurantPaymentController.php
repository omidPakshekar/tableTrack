<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RestaurantPaymentController extends Controller
{
    
    public function index()
    {
        abort_if((!user_can('Show Superadmin Payments')), 403);
        return view('restaurant-payments.index');
    }

}
