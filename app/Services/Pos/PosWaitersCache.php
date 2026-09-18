<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cached waiter list for POS and related UIs (branch-scoped, same query as PosController).
 * Cleared via WaiterObserver and explicit calls when roles change without a user row update.
 */
class PosWaitersCache
{
    public const CACHE_VERSION = 2;

    public const TTL_SECONDS = 86400;

    public static function cacheKey(int $restaurantId, int $branchId): string
    {
        return sprintf('pos.waiters.list.v%d.%d.%d', self::CACHE_VERSION, $restaurantId, $branchId);
    }

    /**
     * @return Collection<int, User>
     */
    public static function remember(int $restaurantId, int $branchId): Collection
    {
        $key = self::cacheKey($restaurantId, $branchId);

        return Cache::remember($key, self::TTL_SECONDS, function () use ($restaurantId, $branchId) {
            return self::queryWaiters($restaurantId, $branchId);
        });
    }

    /**
     * @return Collection<int, User>
     */
    public static function queryWaiters(int $restaurantId, int $branchId): Collection
    {
        $roleWaiter = 'Waiter_' . $restaurantId;
        $roleWaiterLegacy = 'waiter_' . $restaurantId;

        return User::withoutGlobalScope(BranchScope::class)
            ->where(function ($q) use ($branchId) {
                return $q->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', $restaurantId)
            ->whereHas('roles', function ($q) use ($roleWaiter, $roleWaiterLegacy) {
                $q->where(function ($q2) use ($roleWaiter, $roleWaiterLegacy) {
                    $q2->where('name', $roleWaiter)
                        ->orWhere('name', $roleWaiterLegacy);
                });
            })
            ->get();
    }

    /**
     * Whether the actor is a restaurant-scoped waiter (POS should only list/select themselves).
     */
    public static function actorIsRestrictedPosWaiter(User $actor, int $restaurantId): bool
    {
        return $actor->hasRole('Waiter_' . $restaurantId)
            || $actor->hasRole('waiter_' . $restaurantId);
    }

    /**
     * Restrict the cached waiter list to the logged-in waiter when applicable.
     *
     * @param  Collection<int, User>  $waiters
     * @return Collection<int, User>
     */
    public static function forPosActor(Collection $waiters, User $actor, int $restaurantId): Collection
    {
        if (!self::actorIsRestrictedPosWaiter($actor, $restaurantId)) {
            return $waiters;
        }

        $self = $waiters->firstWhere('id', $actor->id);
        if ($self) {
            return collect([$self])->values();
        }

        if ((int) $actor->restaurant_id === $restaurantId) {
            return collect([$actor])->values();
        }

        return collect();
    }

    /**
     * Force waiter selection to an allowed id for restricted waiter sessions.
     *
     * @param  Collection<int, User>  $allowedWaiters
     */
    public static function normalizeWaiterSelection(?int $selectWaiter, User $actor, int $restaurantId, Collection $allowedWaiters): ?int
    {
        if (!self::actorIsRestrictedPosWaiter($actor, $restaurantId)) {
            return $selectWaiter;
        }

        $allowed = $allowedWaiters->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($selectWaiter !== null && in_array((int) $selectWaiter, $allowed, true)) {
            return (int) $selectWaiter;
        }

        return (int) $actor->id;
    }

    /**
     * Forget canonical keys for every branch of the restaurant, plus legacy cache keys.
     */
    public static function forgetForRestaurant(?int $restaurantId): void
    {
        if (!$restaurantId) {
            return;
        }

        $branchIds = Branch::where('restaurant_id', $restaurantId)->pluck('id');
        foreach ($branchIds as $branchId) {
            $bid = (int) $branchId;
            Cache::forget(self::cacheKey($restaurantId, $bid));
            Cache::forget('waiters_' . $restaurantId . '_' . $bid);
            Cache::forget('waiters_' . $bid);
        }

        Cache::forget('waiters_' . $restaurantId);
    }
}
