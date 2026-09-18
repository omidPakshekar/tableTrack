<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DeliveryPortalController extends Controller
{
    public function dashboard(): View
    {
        return view('delivery-portal.dashboard', $this->context());
    }

    public function assignedOrders(): View
    {
        return view('delivery-portal.assigned-orders', $this->context());
    }

    public function orderDetails(string $uuid): View
    {
        return view('delivery-portal.order-details', array_merge($this->context(), [
            'orderUuid' => $uuid,
        ]));
    }

    public function history(): View
    {
        return view('delivery-portal.history', $this->context());
    }

    public function codSettlement(): View
    {
        return view('delivery-portal.cod-settlement', $this->context());
    }

    public function profile(): View
    {
        return view('delivery-portal.profile', $this->context());
    }

    private function context(): array
    {
        $executive = delivery_executive();
        abort_if(!$executive || !$executive->branch || !$executive->branch->restaurant, 403);

        return [
            'deliveryExecutive' => $executive,
            'shopBranch' => $executive->branch,
            'restaurant' => $executive->branch->restaurant,
        ];
    }
}
