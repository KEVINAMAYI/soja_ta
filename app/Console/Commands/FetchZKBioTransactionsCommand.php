<?php

namespace App\Console\Commands;

use App\Jobs\FetchZKBioTransactions;
use Illuminate\Console\Command;

class FetchZKBioTransactionsCommand extends Command
{
    protected $signature   = 'zkbio:fetch';
    protected $description = 'Fetch transactions from ZKBio access control and map to attendance';

    public function handle(): void
    {
        $this->info('Dispatching ZKBio fetch job...');
        FetchZKBioTransactions::dispatch();
        $this->info('Done.');
    }
}
