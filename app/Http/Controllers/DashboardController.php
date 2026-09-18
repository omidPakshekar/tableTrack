<?php

namespace App\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DashboardController extends Controller
{

    public function index()
    {
        if (user()->hasRole('Super Admin')) {
            return redirect(RouteServiceProvider::SUPERADMIN_HOME);
        }

        return view('dashboard.index');
    }

    public function superadmin()
    {
        // Check if URL contains "public" - important for production
        $currentUrl = request()->url();
        $urlPath = parse_url($currentUrl, PHP_URL_PATH);
        $urlHasPublic = str_contains($urlPath, '/public/') || str_ends_with($urlPath, '/public') || str_starts_with($urlPath, '/public');

        // Check if onboarding steps are completed
        $smtpConfigured = (smtp_setting()->mail_driver == 'smtp' && smtp_setting()->verified) || smtp_setting()->mail_driver != 'smtp';
        $cronConfigured = global_setting()->hide_cron_job == 1;
        $appNameChanged = global_setting()->name != 'TableTrack'; // Assuming 'TableTrack' is the default name

        // If any of the onboarding steps are not completed OR URL has "public", redirect to the onboarding page
        if (($urlHasPublic || !$smtpConfigured || !$cronConfigured || !$appNameChanged) && !app()->environment('development')) {
            return view('dashboard.onboarding', compact('smtpConfigured', 'cronConfigured', 'appNameChanged', 'urlHasPublic'));
        }

        return view('dashboard.superadmin');
    }

    public function beamAuth()
    {
        $user = Auth::user();
        if (!$user) {
            return response('Unauthorized', 401);
        }

        $userID = Str::slug(global_setting()->name) . '-' . $user->id;
        $userIDInQueryParam = request()->user_id;

        if ($userID != $userIDInQueryParam) {
            return response('Inconsistent request', 401);
        } else {
            $beamsClient = new \Pusher\PushNotifications\PushNotifications([
                'instanceId' => pusherSettings()->instance_id,
                'secretKey' => pusherSettings()->beam_secret,
            ]);

            $beamsToken = $beamsClient->generateToken($userID);
            return response()->json($beamsToken);
        }
    }


    public function sendPushNotifications($usersIDs, $title, $body, $link)
    {
        if (!App::environment('codecanyon') || !pusherSettings()->beamer_status) {
            return;
        }

        if (!is_array($usersIDs) || count($usersIDs) === 0) {
            return;
        }

        // Call sites sometimes pass `[ [1,2,3] ]` instead of `[1,2,3]`
        $ids = $usersIDs;
        if (isset($usersIDs[0]) && is_array($usersIDs[0])) {
            $ids = $usersIDs[0];
        }

        $pushIDs = collect($ids)
            ->filter(fn ($uid) => !empty($uid))
            ->unique()
            ->map(fn ($uid) => Str::slug(global_setting()->name) . '-' . $uid)
            ->values()
            ->all();

        // Pusher throws if we publish to zero users
        if (count($pushIDs) === 0) {
            return;
        }

        try {
            $beamsClient = new \Pusher\PushNotifications\PushNotifications([
                'instanceId' =>  pusherSettings()->instance_id,
                'secretKey' =>  pusherSettings()->beam_secret,
            ]);
            $beamsClient->publishToUsers(
                $pushIDs,
                array(
                    'web' => array(
                        'notification' => array(
                            'title' => $title,
                            'body' => $body,
                            'deep_link' => $link,
                            'icon' => global_setting()->logo_url
                        )
                    )
                )
            );
        } catch (\Throwable $e) {
            Log::error('Error sending push notification: ' . $e->getMessage());
        }
    }

    public function accountUnverified()
    {
        return view('dashboard.padding-approval');
    }
}
