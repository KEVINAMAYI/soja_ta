<?php

namespace App\Jobs;

use App\Mail\ClientIssueUpdateMail;
use App\Models\ClientIssue;
use App\Models\ClientIssueUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendClientIssueEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $issueId, public int $updateId)
    {
    }

    public function handle(): void
    {
        $issue = ClientIssue::with('organization')->findOrFail($this->issueId);
        $update = ClientIssueUpdate::with('creator')->findOrFail($this->updateId);

        if ($issue->organization?->email) {
            Mail::to($issue->organization->email)->send(new ClientIssueUpdateMail($issue, $update));
        }
    }
}
