<?php

namespace App\Services;

use App\Jobs\SendClientIssueEmailJob;
use App\Models\ClientIssue;
use App\Models\ClientIssueUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientIssueService
{
    public function issuesQuery(): Builder
    {
        return ClientIssue::query()
            ->with(['organization', 'creator'])
            ->withCount('updates')
            ->latest();
    }

    public function create(array $data, int $createdBy): ClientIssue
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $issue = ClientIssue::create([
                'organization_id' => $data['organization_id'],
                'created_by' => $createdBy,
                'reference' => $this->nextReference(),
                'subject' => $data['subject'],
                'description' => $data['description'],
                'status' => 'open',
                'priority' => $data['priority'] ?? 'normal',
            ]);

            $update = $issue->updates()->create([
                'created_by' => $createdBy,
                'status' => 'open',
                'note' => 'Issue received by the support team.',
            ]);

            SendClientIssueEmailJob::dispatch($issue->id, $update->id);

            return $issue->fresh(['organization', 'creator', 'updates.creator']);
        });
    }

    public function update(ClientIssue $issue, array $data, int $updatedBy): ClientIssue
    {
        return DB::transaction(function () use ($issue, $data, $updatedBy) {
            $oldStatus = $issue->status;
            $issue->fill(array_intersect_key($data, array_flip(['subject', 'description', 'status', 'priority'])));

            if (($data['status'] ?? null) === 'resolved' && !$issue->resolved_at) {
                $issue->resolved_at = now();
            } elseif (($data['status'] ?? null) && $data['status'] !== 'resolved') {
                $issue->resolved_at = null;
            }

            $issue->save();

            $statusChanged = $oldStatus !== $issue->status;
            $note = $data['note'] ?? null;
            if ($statusChanged || $note !== null) {
                $update = $issue->updates()->create([
                    'created_by' => $updatedBy,
                    'status' => $issue->status,
                    'note' => $note,
                ]);
                SendClientIssueEmailJob::dispatch($issue->id, $update->id);
            }

            return $issue->fresh(['organization', 'creator', 'updates.creator']);
        });
    }

    private function nextReference(): string
    {
        do {
            $reference = 'ISS-' . now()->format('Ymd') . '-' . Str::upper(Str::random(5));
        } while (ClientIssue::where('reference', $reference)->exists());

        return $reference;
    }
}
