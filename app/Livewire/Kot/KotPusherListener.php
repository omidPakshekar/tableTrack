<?php

namespace App\Livewire\Kot;

use Livewire\Component;
use App\Services\KotStatusNotificationFeed;
use Illuminate\Support\Facades\Auth;
use Jantinnerezo\LivewireAlert\LivewireAlert;

/**
 * Layout-mounted: KOT status toasts on every page via Pusher (JS in pusher-script)
 * or via cache polling when Pusher broadcast is disabled.
 */
class KotPusherListener extends Component
{
    use LivewireAlert;

    public int $kotFeedLastSeq = 0;

    public bool $kotFeedPrimed = false;

    /**
     * Called from global Pusher JS when kot.updated is received.
     */
    public function showKotStatusToast(array $data = []): void
    {
        $this->notifyKotStatusIfNeeded($data, false);
    }

    /**
     * Polled when Pusher is off; reads {@see KotStatusNotificationFeed}.
     */
    public function pollKotStatusFeed(): void
    {
        if (!Auth::check() || !user()->restaurant_id) {
            return;
        }

        $rid = (int) user()->restaurant_id;

        if (!$this->kotFeedPrimed) {
            $this->kotFeedLastSeq = KotStatusNotificationFeed::latestSequence($rid);
            $this->kotFeedPrimed = true;

            return;
        }

        $result = KotStatusNotificationFeed::eventsAfterSequence($this->kotFeedLastSeq, $rid);

        foreach ($result['events'] as $event) {
            $this->notifyKotStatusIfNeeded($event, true);
        }

        $this->kotFeedLastSeq = $result['next_seq'];
    }

    private function notifyKotStatusIfNeeded(array $data, bool $playSound): void
    {
        if (!Auth::check()) {
            return;
        }

        if (($data['type'] ?? null) !== 'status_updated') {
            return;
        }

        $updatedBy = isset($data['updated_by_user_id']) ? (int) $data['updated_by_user_id'] : null;
        if ($updatedBy !== null && $updatedBy === (int) Auth::id()) {
            return;
        }

        // Global "kots" channel is shared; ensure event belongs to current restaurant/branch.
        $restaurantId = (int) (user()->restaurant_id ?? 0);
        $eventRestaurantId = isset($data['restaurant_id']) ? (int) $data['restaurant_id'] : 0;
        if ($eventRestaurantId > 0 && $restaurantId > 0 && $eventRestaurantId !== $restaurantId) {
            return;
        }

        $currentBranchId = (int) (branch()->id ?? 0);
        $eventBranchId = isset($data['branch_id']) ? (int) $data['branch_id'] : 0;
        if ($eventBranchId > 0 && $currentBranchId > 0 && $eventBranchId !== $currentBranchId) {
            return;
        }

        $kotNumber = $data['kot_number'] ?? null;
        $status = $data['kot_status'] ?? null;
        $orderNumber = $data['order_number'] ?? null;
        $tableCode = $data['table_code'] ?? null;

        $this->alert('success', __('messages.kotStatusUpdated', [
            'kot_number' => $kotNumber ? ('#' . $kotNumber) : '',
            'status' => $status ? __('modules.order.' . $status) : '',
            'order_number' => $orderNumber ?? '',
            'table_code' => $tableCode ?? '',
        ]), [
            'toast' => true,
            'position' => 'top-end',
        ]);

        if ($playSound) {
            $url = asset('sound/new_order.wav');
            $this->js('try{new Audio(' . json_encode($url) . ').play()}catch(e){}');
        }
    }

    public function render()
    {
        return view('livewire.kot.kot-pusher-listener');
    }
}
