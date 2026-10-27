<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConfigureElevateHrLeaveWebhook extends Command
{
    protected $signature = 'elevatehr:leave-webhook {organization : Organisation ID}';

    protected $description = 'Generate (or rotate) the secret ElevateHR leave webhook URL';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($token) {
            $organization = Organization::lockForUpdate()->findOrFail($this->argument('organization'));
            $organization->setSetting('elevatehr_leave_webhook_token_hash', hash('sha256', $token));
        });

        $this->warn('Keep this URL secret. Any previously generated URL for this organisation is now invalid.');
        $this->line(route('webhooks.elevatehr.leave', [
            'organization' => $this->argument('organization'),
            'token' => $token,
        ]));

        return self::SUCCESS;
    }
}
