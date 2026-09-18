<?php

namespace App\Events;

use App\Models\PrintJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrintJobCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $printJob;

    /**
     * Create a new event instance.
     */
    public function __construct(PrintJob $printJob)
    {
        $this->printJob = $printJob;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('print-jobs'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'print_job_id' => $this->printJob->id,
            'restaurant_id' => $this->printJob->restaurant_id,
            'branch_id' => $this->printJob->branch_id,
            'printer_id' => $this->printJob->printer_id,
            'status' => $this->printJob->status,
            'payload' => $this->printJob->payload,
            'created_at' => $this->printJob->created_at,
            'timestamp' => now()->toISOString(),
            'type' => 'print_job_created',
            'printer_info' => [
                'printer_name' => $this->printJob->printer->name ?? null,
                'printer_type' => $this->printJob->printer->type ?? null,
                'print_format' => $this->printJob->printer->print_format ?? null,
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'print-job.created';
    }
}
