<?php

namespace App\Services;

use App\Models\ElevateHrWebhook;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ElevateHrWebhookService
{
    public function generate(int $organizationId, ?User $creator = null): string
    {
        $token = DB::transaction(function () use ($organizationId, $creator) {
            $organization = Organization::lockForUpdate()->findOrFail($organizationId);
            $token = bin2hex(random_bytes(32));
            $now = now();

            ElevateHrWebhook::where('organization_id', $organization->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            ElevateHrWebhook::create([
                'organization_id' => $organization->id,
                'token_hash' => hash('sha256', $token),
                'created_by' => $creator?->id,
                'creator_name' => $creator?->name ?? 'Command line',
            ]);

            $organization->setSetting('elevatehr_leave_webhook_token_hash', hash('sha256', $token));

            return $token;
        });

        return route('webhooks.elevatehr.leave', [
            'organization' => $organizationId,
            'token' => $token,
        ]);
    }
}
