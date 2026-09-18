<?php

namespace App\Http\Middleware;

use App\Services\RestaurantImpersonationLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogRestaurantImpersonationActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $logId = session('impersonate_log_id');

        if (! $logId || ! session('impersonate_user_id')) {
            return;
        }

        app(RestaurantImpersonationLogService::class)->logRequest((int) $logId, $request);
    }
}
