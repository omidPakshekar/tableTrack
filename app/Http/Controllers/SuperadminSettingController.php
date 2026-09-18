<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SuperadminSettingController extends Controller
{

    public function index()
    {
        abort_if((!user_can('Manage Superadmin Settings')), 403);
        return view('superadmin-settings.index');
    }

    public function users()
    {
        abort_if((!user_can('Show SuperAdmin')), 403);
        return view('superadmin-settings.super-admin-list');
    }

}
