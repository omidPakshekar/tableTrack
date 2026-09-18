<?php

namespace App\Livewire\Dashboard;

use App\Models\Kot;
use App\Models\Order;
use App\Services\OrderWaiterResponseService;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\Log;

class TodayOrders extends Component
{

    use LivewireAlert;

    public function render()
    {
        // Get business day boundaries for today
        $boundaries = getBusinessDayBoundaries(branch(), now());
        $startUTC = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
        $endUTC = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

        $orderQuery = Order::where('orders.date_time', '>=', $startUTC)
            ->where('orders.date_time', '<=', $endUTC)
            ->where('status', '<>', 'canceled')
            ->where('status', '<>', 'draft');

        // Filter by waiter if user is a waiter
        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $orderQuery->where('waiter_id', user()->id);
        }

        $count = $orderQuery->count();

        $kotQuery = Kot::join('orders', 'kots.order_id', '=', 'orders.id')
            ->where('kots.created_at', '>=', $startUTC)
            ->where('kots.created_at', '<=', $endUTC)
            ->where('orders.status', '<>', 'canceled')
            ->where('orders.status', '<>', 'draft');

        // Filter by waiter if user is a waiter
        if (user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $kotQuery->where('orders.waiter_id', user()->id);
        }

        $todayKotCount = $kotQuery->count();

        $playSound = false;

        $recentOrder = $orderQuery->latest()->first();

        if (session()->has('today_order_count') && session('today_order_count') < $todayKotCount) {
            if ($recentOrder) {
                OrderWaiterResponseService::autoAcceptWhenPlacedByWaiterOnPos($recentOrder);
                $recentOrder->refresh();
            }

            $isSelfPlacedPosOrder = $recentOrder
                && OrderWaiterResponseService::wasPlacedByWaiterOnPos($recentOrder);

            $playSound = ! $isSelfPlacedPosOrder;

            if (! $isSelfPlacedPosOrder) {
                $this->alert('success', __('messages.newOrderReceived'), [
                    'toast' => true,
                    'position' => 'top-end',
                ]);
            }

            if ($recentOrder) {

                // Default to `pending` until the waiter explicitly accepts/declines.
                // We use `waiter_response_at` to determine whether the waiter has made a decision.
                if (is_null($recentOrder->waiter_response_at)) {
                    $update = [
                        'waiter_response' => 'pending',
                    ];

                    if (!$recentOrder->waiter_notification_sent_at) {
                        $update['waiter_notification_sent_at'] = now();
                    }

                    Order::where('id', $recentOrder->id)->update($update);
                    $recentOrder->refresh();
                }

                if (OrderWaiterResponseService::shouldPromptWaiterAcceptDecline($recentOrder)) {
                    $this->confirm(__('messages.newOrderReceived'), [
                        'position' => 'center',
                        'confirmButtonText' => __('app.acceptOrder'),
                        'confirmButtonColor' => '#16a34a',
                        'showDenyButton' => true,
                        'showCancelButton' => false,
                        'denyButtonText' => __('app.declineOrder'),
                        'onConfirmed' => 'acceptOrder',
                        'onDenied' => 'declineOrder',
                        'data' => [
                            'orderID' => $recentOrder->id,
                        ],
                    ]);
                }
            }

            // uncomment to show new order notification in orders list if needed
            // session(['new_order_notification_pending' => true]);
            $this->dispatch('refreshOrders');
        } elseif ($count > session('last_order_count', 0) && !session('new_order_notification_pending')) {
            session(['new_order_notification_pending' => true]);
        }

        session([
            'last_order_count' => $count,
            'today_order_count' => $todayKotCount
        ]);

        // Notify non-waiter users when an assigned waiter declines an order
        if (!user()->hasRole('Waiter_' . user()->restaurant_id)) {
            $lastChecked = session('last_waiter_decline_checked_at');

            $declinedOrderQuery = Order::where('orders.date_time', '>=', $startUTC)
                ->where('orders.date_time', '<=', $endUTC)
                ->where('status', '<>', 'canceled')
                ->where('status', '<>', 'draft')
                ->where('waiter_response', 'declined');

            if ($lastChecked) {
                $declinedOrderQuery->where('waiter_response_at', '>', $lastChecked);
            }

            $declinedOrder = $declinedOrderQuery->latest('waiter_response_at')->first();

            if ($declinedOrder) {
                $orderLabel = $declinedOrder->show_formatted_order_number ?? '#' . ($declinedOrder->order_number ?? $declinedOrder->id);
                $this->confirm(__('messages.waiterDeclinedOrder', ['order_number' => $orderLabel]), [
                    'position' => 'center',
                    'confirmButtonText' => __('app.close'),
                    'showCancelButton' => false,
                    'data' => [
                        'orderID' => $declinedOrder->id,
                    ],
                ]);

                session(['last_waiter_decline_checked_at' => $declinedOrder->waiter_response_at]);
            }
        }

        return view('livewire.dashboard.today-orders', [
            'count' => $count,
            'playSound' => $playSound,
        ]);
    }

    #[On('acceptOrder')]
    public function acceptOrder($data)
    {
        if (is_array($data) && isset($data['orderID'])) {
            $orderId = $data['orderID'];

            // Update order with accepted response
            Order::where('id', $orderId)->update([
                'waiter_response' => 'accepted',
                'waiter_response_at' => now(),
            ]);

            // Redirect to order details page
            $order = Order::find($orderId);
            if ($order) {
                return $this->redirect(route('orders.show', $order->uuid), navigate: true);
            }
        }

        Log::warning('acceptOrder: Invalid data format', ['data' => $data]);
    }

    #[On('declineOrder')]
    public function declineOrder($data)
    {
        if (is_array($data) && isset($data['orderID'])) {
            $orderId = $data['orderID'];

            // Update order with declined response
            Order::where('id', $orderId)->update([
                'waiter_response' => 'declined',
                'waiter_response_at' => now(),
            ]);
        }
    }




    /**
     * Handle refresh from Pusher event
     */
    public function refreshOrders()
    {
        // This method will be called when Pusher sends data
        // The component will automatically re-render with fresh data
        $this->dispatch('$refresh');
    }
}
