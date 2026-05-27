<?php

namespace App\Console\Commands;

use App\Jobs\BackfillZKBioTransactions;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ZKBioBackfill extends Command
{
    protected $signature = 'zkbio:backfill
                            {--from= : Start datetime (Y-m-d H:i:s). Defaults to 30 minutes ago}
                            {--to=   : End datetime (Y-m-d H:i:s). Defaults to now}
                            {--org=  : Organization ID to limit backfill to a single org}
                            {--sync  : Run synchronously instead of dispatching to queue}';

    protected $description = 'Backfill ZKBio attendance transactions for a given date/time window';

    public function handle(): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subMinutes(30);
        $to   = $this->option('to')   ? Carbon::parse($this->option('to'))   : now();

        if ($from->gte($to)) {
            $this->error('--from must be earlier than --to.');
            return Command::FAILURE;
        }

        $startDate = $from->format('Y-m-d H:i:s');
        $endDate   = $to->format('Y-m-d H:i:s');
        $orgId     = $this->option('org') ? (int) $this->option('org') : null;

        $this->info("ZKBio backfill: {$startDate} → {$endDate}" . ($orgId ? " (org #{$orgId})" : ' (all orgs)'));

        $job = new BackfillZKBioTransactions($startDate, $endDate, $orgId);

        if ($this->option('sync')) {
            dispatch_sync($job);
            $this->info('Backfill completed synchronously.');
        } else {
            dispatch($job);
            $this->info('Backfill job dispatched to queue.');
        }

        return Command::SUCCESS;
    }
}
