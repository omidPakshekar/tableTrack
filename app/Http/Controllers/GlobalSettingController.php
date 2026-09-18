<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Helper\Reply;
use App\Models\GlobalSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Zip;

class GlobalSettingController extends Controller
{

    public function index()
    {
        if (!user_can('App Update')) {
            return view('app_update.index', [
                'permissionDenied' => true,
            ]);
        }

        try {
            $results = DB::select('select version()');
            $mysql_version = $results[0]->{'version()'};
            $databaseType = 'MySQL Version';

            if (str_contains($mysql_version, 'Maria')) {
                $databaseType = 'Maria Version';
            }
        } catch (\Exception $e) {
            $mysql_version = null;
            $databaseType = 'MySQL Version';
        }


        $serverOs = 'Unknown';


        try {
            if (function_exists('php_uname')) {
                $serverOs = php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
            } else {
                $serverOs = 'Unavailable (php_uname disabled)';
            }
        } catch (\Exception $e) {
            $serverOs = 'Unknown';
        }


        $reviewed = file_exists(storage_path('reviewed'));

        return view('app_update.index', [
            'permissionDenied' => false,
            'mysql_version' => $mysql_version,
            'databaseType' => $databaseType,
            'reviewed' => $reviewed,
            'serverOs' => $serverOs,
        ]);
    }

    public function store(Request $request)
    {
        abort_if(!user_can('App Update'), 403);

        config(['filesystems.default' => 'storage']);
        $path = storage_path('app') . '/Modules/' . $request->file->getClientOriginalName();

        if (file_exists($path)) {
            File::delete($path);
        }

        $request->file->storeAs('/', $request->file->getClientOriginalName());
    }

    public function deleteFile(Request $request)
    {
        abort_if(!user_can('App Update'), 403);

        $filePath = $request->filePath;
        File::delete($filePath);

        return Reply::success(__('messages.deleteSuccess'));
    }
}
