<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateEmployeeLeaveDaysJob;
use App\Models\Employee;
use Illuminate\Console\Command;

class RecalculateLeaveDays extends Command
{
    protected $signature = 'leaves:recalculate
                            {--organization= : Limit to a single organization id}
                            {--year= : Limit to leaves starting in this year}
                            {--chunk=100 : Employees processed per queued job}
                            {--sync : Run inline instead of dispatching to the queue}
                            {--dry-run : Log the changes without writing them}';

    protected $description = 'Recalculate num_of_days / expected_resumption on existing leaves and rebuild leave balances';

    public function handle(): int
    {
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;
        $year = $this->option('year') ? (int) $this->option('year') : null;
        $chunkSize = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        $batches = 0;
        $employeeCount = 0;

        Employee::withTrashed()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereHas('leaves', fn ($q) => $q->when($year, fn ($sub) => $sub->whereYear('start_date', $year)))
            ->select('id')
            ->chunkById($chunkSize, function ($employees) use ($year, $sync, $dryRun, &$batches, &$employeeCount) {
                $ids = $employees->pluck('id')->all();
                $job = new RecalculateEmployeeLeaveDaysJob($ids, $year, $dryRun);

                $sync ? dispatch_sync($job) : dispatch($job);

                $batches++;
                $employeeCount += count($ids);
                $this->info("Batch {$batches}: " . count($ids) . ' employees ' . ($sync ? 'processed' : 'queued'));
            });

        $this->info("Done. {$employeeCount} employees across {$batches} batch(es).");

        return self::SUCCESS;
    }
}
