<?php

namespace App\Http\Middleware;

use App\Models\DeliveryExecutive;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeliveryExecutiveAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $executiveId = session('delivery_executive_id');

        if (!$executiveId) {
            return redirect()->route('delivery.login');
        }

        $executive = DeliveryExecutive::with('branch.restaurant.currency')->find($executiveId);

        if (!$executive || $executive->status === 'inactive') {
            session()->forget(['delivery_executive_id', 'delivery_executive', 'restaurant', 'shop_branch']);
            return redirect()->route('delivery.login');
        }

        session([
            'delivery_executive' => $executive,
            'shop_branch' => $executive->branch,
            'restaurant' => $executive->branch?->restaurant,
        ]);

        return $next($request);
    }
}
