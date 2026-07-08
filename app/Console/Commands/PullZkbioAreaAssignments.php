<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ZkbioArea;
use App\Services\ZKBioPersonService;
use Illuminate\Console\Command;

class PullZkbioAreaAssignments extends Command
{
    protected $signature = 'zkbio:pull-area-assignments {--org=3}';
    protected $description = 'Pull actual area assignments from ZKBio and reconcile local pivot table';

    public function handle(): int
    {
        $org = Organization::findOrFail((int) $this->option('org'));
        $service = app(ZKBioPersonService::class, ['organization' => $org]);

        $employees = Employee::where('organization_id', $org->id)
            ->where('active', 1)
            ->whereNotNull('zkbio_pin')
            ->get();

        $this->info("Pulling area assignments for {$employees->count()} employees...");

        $ok = 0; $failed = 0; $mismatched = 0;

        foreach ($employees as $emp) {
            $actualCodes = $service->getPersonAreas($emp->zkbio_pin);

            if (empty($actualCodes)) {
                $this->warn("EMPTY pin {$emp->zkbio_pin} ({$emp->name}) — no areas returned or call failed");
                $failed++;
                continue;
            }

            $localCodes = $emp->zkbioAreas->pluck('area_code')->map(fn($c) => (string) $c)->sort()->values()->all();
            $actualSorted = collect($actualCodes)->sort()->values()->all();

            if ($localCodes !== $actualSorted) {
                $this->line("MISMATCH pin {$emp->zkbio_pin} ({$emp->name}): local=[" . implode(',', $localCodes) . "] actual=[" . implode(',', $actualSorted) . "]");
                $mismatched++;
            }

            // Update local pivot to match reality
            $areaIds = ZkbioArea::where('organization_id', $org->id)
                ->whereIn('area_code', $actualCodes)
                ->pluck('id')
                ->toArray();

            $emp->zkbioAreas()->sync($areaIds);
            $ok++;

            usleep(150000);
        }

        $this->newLine();
        $this->info("Done. Synced: {$ok}, Mismatched (before update): {$mismatched}, Failed: {$failed}");
        return 0;
    }
}
