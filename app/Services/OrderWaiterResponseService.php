<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

class OrderWaiterResponseService
{
    /**
     * Waiter placed this order on POS (assigned to themselves) — no accept/decline prompt.
     */
    public static function wasPlacedByWaiterOnPos(Order $order, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user || ! $order->waiter_id) {
            return false;
        }

        if ((string) $order->placed_via !== 'pos') {
            return false;
        }

        $restaurantId = (int) ($order->branch?->restaurant_id ?? $user->restaurant_id ?? 0);
        if ($restaurantId <= 0 || ! $user->hasRole('Waiter_'.$restaurantId)) {
            return false;
        }

        if ((int) $order->waiter_id !== (int) $user->id) {
            return false;
        }

        if ($order->added_by && (int) $order->added_by !== (int) $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Auto-accept when the assigned waiter created the order on POS.
     */
    public static function autoAcceptWhenPlacedByWaiterOnPos(Order $order, ?User $user = null): void
    {
        if (! self::wasPlacedByWaiterOnPos($order, $user)) {
            return;
        }

        if ($order->waiter_response_at) {
            return;
        }

        $order->update([
            'waiter_response' => 'accepted',
            'waiter_response_at' => now(),
        ]);
    }

    public static function shouldPromptWaiterAcceptDecline(Order $order, ?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user || ! $order->waiter_id) {
            return false;
        }

        $restaurantId = (int) ($order->branch?->restaurant_id ?? $user->restaurant_id ?? 0);
        if ($restaurantId <= 0 || ! $user->hasRole('Waiter_'.$restaurantId)) {
            return false;
        }

        if ((int) $order->waiter_id !== (int) $user->id) {
            return false;
        }

        if (self::wasPlacedByWaiterOnPos($order, $user)) {
            return false;
        }

        return is_null($order->waiter_response_at);
    }
}
