<?php

namespace App\Events;

use App\Models\Branch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBranchCreatedEvent
{

    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $branch;

    public function __construct(Branch $branch)
    {
        $this->branch = $branch;
    }
}
