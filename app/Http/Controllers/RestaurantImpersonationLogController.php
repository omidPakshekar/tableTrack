<?php

namespace App\Http\Controllers;

class RestaurantImpersonationLogController extends Controller
{
    public function index()
    {
        abort_if((! user_can('Show Restaurant')), 403);

        return view('restaurants.impersonation-logs');
    }
}
