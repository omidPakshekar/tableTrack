<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Kot;
use App\Models\KotSetting;
use App\Events\KotUpdated;
use App\Events\OrderSuccessEvent;
use App\Events\OrderUpdated;
use App\Services\KotStatusNotificationFeed;

class KotObserver
{
    public function created(Kot $kot): void
    {
        OrderObserver::forgetTodayKotCountMemoForBranch($kot->branch_id);

        event(new KotUpdated($kot, 'new_kot'));
    }

    public function deleted(Kot $kot): void
    {
        OrderObserver::forgetTodayKotCountMemoForBranch($kot->branch_id);
    }

    public function creating(Kot $kot)
    {
        if (branch() && $kot->branch_id == null) {
            $kot->branch_id = branch()->id;
        }

        // Always load settings for the KOT's branch explicitly (don't rely on global scopes here)
        $kotSettings = KotSetting::withoutGlobalScopes()
            ->where('branch_id', $kot->branch_id)
            ->first();

        $orderStatus = $kot->order?->order_status->value;
        $placedVia = $kot->order?->placed_via;

        // Determine if this is a customer order (shop/kiosk) or POS order
        $isCustomerOrder = in_array($placedVia, ['shop', 'kiosk', 'customer'], true);

        $defaultKotStatus = match (true) {
            $isCustomerOrder => $kotSettings?->default_status_customer ?? 'pending',
            $placedVia === 'pos' => $kotSettings?->default_status_pos ?? 'pending',
            default => $kotSettings?->default_status_pos ?? 'pending',
        };

        $kot->status = match (true) {
            // If default is pending, always require confirmation first
            $defaultKotStatus === 'pending' => 'pending_confirmation',
            // If default is cooking, it goes straight to kitchen
            $defaultKotStatus === 'cooking' => 'in_kitchen',
            default => 'pending_confirmation',
        };

    }

    public function updated(Kot $kot)
    {
        // Only proceed if status changed
        if (!$kot->wasChanged('status')) {
            return;
        }

        // Load order with all non-cancelled KOTs and their items eagerly
        $order = $kot->order()
            ->with(['kot' => fn($query) => $query
                ->where('status', '!=', 'cancelled')
                ->with('items:id,kot_id,status')
            ])
            ->first(['id', 'order_type', 'placed_via', 'order_status']);

        if ($order && !in_array($order->order_status->value, OrderStatus::terminalProgressValues(), true)) {
            if (!$order->kot->isEmpty()) {
                $aggregatedOrderStatus = $this->determineOrderStatusFromKots($order->kot, $order);

                // Update order status if it changed (compare with enum value)
                if ($aggregatedOrderStatus && $order->order_status->value !== $aggregatedOrderStatus) {
                    $order->updateQuietly(['order_status' => $aggregatedOrderStatus]);
                    // Quiet update skips OrderObserver — broadcast so shop/POS UIs refresh.
                    event(new OrderUpdated($order, 'updated'));
                    event(new OrderSuccessEvent($order));
                }
            }
        }

        // Always notify subscribers (Pusher) when KOT status changes — even if the
        // order has no active (non-cancelled) KOTs left for aggregation.
        event(new KotUpdated($kot, 'status_updated'));

        // Cache feed for staff when Pusher broadcast is disabled (global Livewire poll).
        if (!pusherSettings()->is_enabled_pusher_broadcast) {
            KotStatusNotificationFeed::pushFromKot($kot);
        }
    }

    /**
     * Determine order status based on all KOTs statuses.
     * Professional POS flow: Order status = lowest (earliest) status among all items.
     * Supports both forward and backward transitions.
     */
    private function determineOrderStatusFromKots($kots, $order)
    {
        $orderType = $order->order_type;

        $pendingStatus = ($order->placed_via === 'pos')
            ? 'confirmed'
            : (restaurant()->auto_confirm_orders ? 'confirmed' : 'placed');

        $statusPriority = [
            'pending_confirmation' => 1,
            'in_kitchen' => 2,
            'food_ready' => 3,
            'served' => 4,
        ];

        $minPriority = $kots->min(fn($kot) => $statusPriority[$kot->status] ?? 1);
        $lowestKotStatus = array_search($minPriority, $statusPriority);

        // Check if ANY item across ALL KOTs is in cooking status
        $hasAnyCookingItem = $kots->contains(function ($kot) {
            return $kot->items->contains('status', 'cooking');
        });

        $statusMap = [
            'pending_confirmation' => $pendingStatus,
            'in_kitchen' => $hasAnyCookingItem ? 'preparing' : 'confirmed',
            'food_ready' => match($orderType) {
                'pickup' => 'ready_for_pickup',
                default => 'food_ready',
            },
            'served' => match($orderType) {
                'pickup' => 'ready_for_pickup',
                'delivery' => 'food_ready',
                default => 'served',
            },
        ];


        return $statusMap[$lowestKotStatus] ?? null;
    }


    public function saved(Kot $kot)
    {
        // If status changed, updated() already emitted status_updated
        if ($kot->wasChanged('status')) {
            return;
        }

        // Generic refresh event (no toast notification on UI)
        event(new KotUpdated($kot, 'updated'));
    }
}
