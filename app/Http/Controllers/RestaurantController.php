<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RestaurantController extends Controller
{

    public function index()
    {
        abort_if((!user_can('Show Restaurant')), 403);
        return view('restaurants.index');
    }

    public function show($id)
    {
        abort_if((!user_can('Show Restaurant')), 403);
        return view('restaurants.show', compact('id'));
    }
}
