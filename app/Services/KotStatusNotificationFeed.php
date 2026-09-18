<?php

namespace App\Services;

use App\Models\Kot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

/**
 * Cache-backed KOT status events for staff when Pusher is off.
 * {@see \App\Livewire\Kot\KotPusherListener} polls with wire:poll.
 */
class KotStatusNotificationFeed
{
    private const MAX_EVENTS = 80;

    private const TTL_SECONDS = 86400;

    public static function listCacheKey(int $restaurantId): string
    {
        return 'kot_status_feed_list:' . $restaurantId;
    }

    public static function seqCacheKey(int $restaurantId): string
    {
        return 'kot_status_feed_seq:' . $restaurantId;
    }

    public static function pushFromKot(Kot $kot): void
    {
        $kot->loadMissing(['order.table', 'order.branch']);

        $restaurantId = $kot->order?->branch?->restaurant_id;
        if (!$restaurantId) {
            return;
        }

        $rid = (int) $restaurantId;
        $seqKey = self::seqCacheKey($rid);
        $seq = (int) Cache::get($seqKey, 0) + 1;
        Cache::put($seqKey, $seq, now()->addSeconds(self::TTL_SECONDS));

        $event = [
            'seq' => $seq,
            'type' => 'status_updated',
            'kot_id' => $kot->id,
            'kot_number' => $kot->kot_number,
            'kot_status' => $kot->status,
            'order_id' => $kot->order_id,
            'order_number' => $kot->order?->show_formatted_order_number,
            'table_code' => $kot->order?->table?->table_code,
            'branch_id' => $kot->order?->branch_id ?? $kot->branch_id,
            'restaurant_id' => $rid,
            'updated_by_user_id' => Auth::id(),
        ];

        $key = self::listCacheKey($rid);
        $list = Cache::get($key, []);
        $list[] = $event;

        if (count($list) > self::MAX_EVENTS) {
            $list = array_slice($list, -self::MAX_EVENTS);
        }

        Cache::put($key, $list, now()->addSeconds(self::TTL_SECONDS));
    }

    public static function latestSequence(int $restaurantId): int
    {
        return (int) Cache::get(self::seqCacheKey($restaurantId), 0);
    }

    /**
     * @return array{events: array<int, array>, next_seq: int}
     */
    public static function eventsAfterSequence(int $lastSeenSeq, int $restaurantId): array
    {
        $list = Cache::get(self::listCacheKey($restaurantId), []);
        $new = [];

        foreach ($list as $e) {
            if (($e['seq'] ?? 0) > $lastSeenSeq) {
                $new[] = $e;
            }
        }

        $next = $lastSeenSeq;
        if ($new !== []) {
            $next = (int) end($new)['seq'];
        }

        return [
            'events' => $new,
            'next_seq' => $next,
        ];
    }
}
