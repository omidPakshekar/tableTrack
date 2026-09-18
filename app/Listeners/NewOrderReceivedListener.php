<?php

namespace App\Listeners;

use App\Events\SendNewOrderReceived;
use App\Http\Controllers\DashboardController;
use App\Queue\UsesDatabaseQueue;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Scopes\BranchScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NewOrderReceivedListener implements ShouldQueue
{
    use InteractsWithQueue, UsesDatabaseQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SendNewOrderReceived $event): void
    {
        // De-dupe: multiple parts of the app can dispatch this event during checkout/payment flows.
        $dedupeKey = 'push:new_order_received:order:'.$event->order->id;
        if (! Cache::add($dedupeKey, 1, now()->addMinutes(10))) {
            return;
        }

        $restaurantId = (int) $event->order->branch->restaurant_id;

        // Notify both Admin and Waiter roles for the restaurant.
        $users = User::query()
            ->role(['Admin_'.$restaurantId, 'Waiter_'.$restaurantId])
            ->where('restaurant_id', $restaurantId)
            ->withoutGlobalScope(BranchScope::class)
            ->get();

        try {
            Notification::send($users, new NewOrderReceived($event->order));
        } catch (\Exception $e) {
            Log::error('Error sending new order received notification: ' . $e->getMessage());
        }

        $pushNotification = new DashboardController();
        $pushUsersIds = [$users->pluck('id')->toArray()];
        $pushNotification->sendPushNotifications($pushUsersIds, __('email.newOrder.subject'), $event->order->show_formatted_order_number, route('orders.index'));
    }
}
