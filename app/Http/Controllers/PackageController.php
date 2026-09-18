<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{

    public function index()
    {
        abort_if((!user_can('Show Package')), 403);
        return view('packages.index');
    }

    public function create()
    {
        abort_if((!user_can('Create Package')), 403);
        return view('packages.create');
    }

    public function edit(Package $package)
    {
        abort_if((!user_can('Update Package')), 403);
        return view('packages.edit', compact('package'));
    }
}
