<?php

namespace App\Jobs;

use App\Services\CheckInApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Run periodically (recommended: every minute) via the scheduler:
 *
 *   $schedule->job(new ProcessExpiredCheckInApprovals)->everyMinute();
 */
class ProcessExpiredCheckInApprovals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CheckInApprovalService $service): void
    {
        $service->processExpiredWindows();
    }
}
