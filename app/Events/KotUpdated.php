<?php

namespace App\Events;

use App\Models\Kot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class KotUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Ensure the event is only broadcast after the database
     * transaction has been committed, so that all KOT items
     * are visible to the listeners when they refresh.
     *
     * @var bool
     */
    public $afterCommit = true;

    /**
     * The KOT instance.
     *
     * @var \App\Models\Kot
     */
    public $kot;

    /**
     * Event type.
     * - updated: generic refresh-worthy change
     * - status_updated: KOT status changed (show notification)
     * - new_kot: new KOT created for an order (play sound on kitchen board)
     */
    public string $type;

    public function __construct(Kot $kot, string $type = 'updated')
    {
        $this->kot = $kot;
        $this->type = $type;
    }

    public function broadcastOn()
    {
        return new Channel('kots');
    }

    public function broadcastAs()
    {
        return 'kot.updated';
    }

    public function broadcastWith()
    {
        $kot = $this->kot->loadMissing(['order', 'order.table', 'order.branch']);

        return [
            'type' => $this->type,
            'kot_id' => $this->kot->id,
            'kot_number' => $kot->kot_number,
            'kot_status' => $kot->status,
            'order_id' => $kot->order_id,
            'order_number' => $kot->order?->show_formatted_order_number,
            'table_code' => $kot->order?->table?->table_code,
            'branch_id' => $kot->order?->branch_id ?? $kot->branch_id,
            'restaurant_id' => $kot->order?->branch?->restaurant_id,
            'updated_by_user_id' => Auth::id(),
        ];
    }
}
