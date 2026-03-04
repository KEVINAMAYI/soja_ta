<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ZKBioSyncCompleted implements ShouldBroadcast
{
    public string $startDate;
    public string $endDate;

    public function __construct(string $startDate, string $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('attendance');
    }

    public function broadcastAs(): string
    {
        return 'sync.completed';
    }
}
