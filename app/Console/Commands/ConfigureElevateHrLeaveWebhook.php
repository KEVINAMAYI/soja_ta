<?php

namespace App\Console\Commands;

use App\Services\ElevateHrWebhookService;
use Illuminate\Console\Command;

class ConfigureElevateHrLeaveWebhook extends Command
{
    protected $signature = 'elevatehr:leave-webhook {organization : Organisation ID}';

    protected $description = 'Generate (or rotate) the secret ElevateHR leave webhook URL';

    public function handle(ElevateHrWebhookService $webhooks): int
    {
        $url = $webhooks->generate((int) $this->argument('organization'));

        $this->warn('Keep this URL secret. Any previously generated URL for this organisation is now invalid.');
        $this->line($url);

        return self::SUCCESS;
    }
}
