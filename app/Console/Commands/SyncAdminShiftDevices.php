<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ZkbioArea;
use App\Services\ZKBioPersonService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncAdminShiftDevices extends Command
{
    protected $signature = 'employees:sync-admin-shift-device
                            {--shifts= : Comma-separated shift IDs, e.g. 22,28}
                            {--device=admin : Keyword to match the ZKBio device/area name}
                            {--dry-run : Show what would happen without calling ZKBio}';

    protected $description = 'Ensure employees on the given shift IDs have the Admin device in their ZKBio assignments (additive — existing devices are untouched).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deviceKeyword = Str::lower($this->option('device'));

        $shiftIds = collect(explode(',', (string) $this->option('shifts')))
            ->map(fn($id) => (int) trim($id))
            ->filter()
            ->values()
            ->all();

        if (empty($shiftIds)) {
            $this->error('Pass shift IDs, e.g. --shifts=28 or --shifts=22,28');
            return 1;
        }

        $employees = Employee::whereIn('shift_id', $shiftIds)
            ->where('active', 1)
            ->whereNotNull('organization_id')
            ->with(['organization', 'zkbioAreas', 'shift'])
            ->get()
            ->groupBy('organization_id');

        if ($employees->isEmpty()) {
            $this->warn('No active employees found on shift IDs [' . implode(',', $shiftIds) . '].');
            return 0;
        }

        $added = 0; $alreadyOk = 0; $skipped = 0; $failed = 0;

        foreach ($employees as $orgId => $orgEmployees) {
            $org = $orgEmployees->first()->organization;

            if (!$org?->zkbio_enabled || !$org->zkbio_base_url || !$org->zkbio_access_token) {
                $this->warn("Org #{$orgId} ({$org?->name}): ZKBio not configured, skipping {$orgEmployees->count()} employee(s).");
                $skipped += $orgEmployees->count();
                continue;
            }

            $service = app(ZKBioPersonService::class, ['organization' => $org]);

            if (!$dryRun) {
                $service->syncAreas();
            }

            $adminDevice = ZkbioArea::where('organization_id', $orgId)
                ->where('area_code', '>', 5)
                ->get()
                ->first(fn($d) => Str::contains(
                    Str::lower(str_replace(['_', '-'], ' ', $d->area_name)),
                    $deviceKeyword
                ));

            if (!$adminDevice) {
                $this->warn("Org #{$orgId} ({$org->name}): no device matching \"{$this->option('device')}\" found, skipping {$orgEmployees->count()} employee(s).");
                $skipped += $orgEmployees->count();
                continue;
            }

            $adminCode = (string) $adminDevice->area_code;
            $this->info("Org #{$orgId} ({$org->name}): admin device = {$adminDevice->area_name} (code {$adminCode}), checking {$orgEmployees->count()} employee(s).");

            foreach ($orgEmployees as $employee) {
                try {
                    $currentCodes = $employee->zkbioAreas
                        ->pluck('area_code')
                        ->map(fn($c) => (string) $c)
                        ->all();

                    if (in_array($adminCode, $currentCodes, true)) {
                        $this->line("SKIP {$employee->name} ({$employee->shift?->name}) — already has {$adminDevice->area_name}");
                        $alreadyOk++;
                        continue;
                    }

                    if ($dryRun) {
                        $pinNote = $employee->zkbio_pin ? "pin {$employee->zkbio_pin}" : 'NO PIN — will generate';
                        $this->line("DRY  {$employee->name} ({$employee->shift?->name}, {$pinNote}) += {$adminDevice->area_name}");
                        $added++;
                        continue;
                    }

                    if (!$employee->zkbio_pin) {
                        $employee->update(['zkbio_pin' => Employee::generateZKBioPin($orgId)]);
                        $employee = $employee->fresh();
                        $service->syncPerson($employee);
                    }

                    $assigned = $service->assignPersonToAreas($employee, [$adminCode]);

                    if (!$assigned) {
                        throw new \RuntimeException('ZKBio area assignment API returned failure — see logs.');
                    }

                    $employee->zkbioAreas()->syncWithoutDetaching([$adminDevice->id]);

                    $this->info("OK   {$employee->name} (pin {$employee->zkbio_pin}) += {$adminDevice->area_name}");
                    $added++;

                } catch (\Throwable $e) {
                    $this->error("FAIL {$employee->name} (id {$employee->id}): " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done. Added: {$added}, Already had it: {$alreadyOk}, Skipped: {$skipped}, Failed: {$failed}");

        return $failed > 0 ? 1 : 0;
    }
}
