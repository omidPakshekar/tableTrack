<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Pos\PosWaitersCache;

/**
 * Invalidates POS waiter list cache when staff users that are (or were) waiters change.
 * Registered on {@see User} alongside {@see UserObserver}.
 */
class WaiterObserver
{
    public function saved(User $user): void
    {
        if (!$user->restaurant_id) {
            return;
        }

        if ($this->userIsWaiter($user)) {
            PosWaitersCache::forgetForRestaurant((int) $user->restaurant_id);
        }
    }

    public function deleted(User $user): void
    {
        if ($user->restaurant_id) {
            PosWaitersCache::forgetForRestaurant((int) $user->restaurant_id);
        }
    }

    private function userIsWaiter(User $user): bool
    {
        $restaurantId = (int) $user->restaurant_id;

        return $user->hasRole('waiter_' . $restaurantId)
            || $user->hasRole('Waiter_' . $restaurantId);
    }
}
