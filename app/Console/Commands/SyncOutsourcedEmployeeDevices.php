<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ZkbioArea;
use App\Services\ZKBioPersonService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncOutsourcedEmployeeDevices extends Command
{
    protected $signature = 'employees:sync-outsourced-devices {--dry-run}';

    protected $description = 'Map every employee in the "Outsourced" department to all ZKBio devices except the Production Entry device, both on the device and locally.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $departmentIds = Department::whereRaw('LOWER(name) = ?', ['outsourced'])->pluck('id');

        if ($departmentIds->isEmpty()) {
            $this->error('No department named "Outsourced" was found. Nothing to do.');
            return 1;
        }

        $employees = Employee::whereIn('department_id', $departmentIds)
            ->whereNotNull('organization_id')
            ->get()
            ->groupBy('organization_id');

        if ($employees->isEmpty()) {
            $this->warn('No employees found in the "Outsourced" department.');
            return 0;
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($employees as $orgId => $orgEmployees) {
            $org = $orgEmployees->first()->organization;

            if (!$org?->zkbio_enabled || !$org->zkbio_base_url || !$org->zkbio_access_token) {
                $this->warn("Org #{$orgId} ({$org?->name}): ZKBio not configured/enabled, skipping " . $orgEmployees->count() . ' employee(s).');
                $skipped += $orgEmployees->count();
                continue;
            }

            $devices = ZkbioArea::where('organization_id', $orgId)
                ->where('area_code', '>', 5) // matches the app's existing "device" convention
                ->get();

            if ($devices->isEmpty()) {
                $this->warn("Org #{$orgId} ({$org->name}): no ZKBio devices found, skipping " . $orgEmployees->count() . ' employee(s).');
                $skipped += $orgEmployees->count();
                continue;
            }

            $targetDevices = $devices->reject(
                fn($d) => Str::contains(Str::lower(str_replace(['_', '-'], ' ', $d->area_name)), 'production')
            );

            if ($targetDevices->count() === $devices->count()) {
                $this->warn("Org #{$orgId} ({$org->name}): could not find a \"Production Entry\" device to exclude — assigning ALL " . $devices->count() . ' devices. Verify area names.');
            }

            $targetCodes = $targetDevices->pluck('area_code')->map(fn($c) => (string) $c)->all();

            $this->info("Org #{$orgId} ({$org->name}): target devices = " . $targetDevices->pluck('area_name')->implode(', '));

            $service = app(ZKBioPersonService::class, ['organization' => $org]);

            foreach ($orgEmployees as $employee) {
                try {
                    if (!$employee->zkbio_pin) {
                        $employee->update(['zkbio_pin' => Employee::generateZKBioPin($orgId)]);
                    }

                    if ($dryRun) {
                        $this->line("DRY  {$employee->name} (pin {$employee->zkbio_pin}) => [" . implode(',', $targetCodes) . ']');
                        $ok++;
                        continue;
                    }

                    $service->syncPerson($employee->fresh());
                    $service->syncEmployeeAreas($employee->fresh(), $targetCodes);

                    $this->info("OK   {$employee->name} (pin {$employee->zkbio_pin}) => [" . implode(',', $targetCodes) . ']');
                    $ok++;
                } catch (\Throwable $e) {
                    $this->error("FAIL {$employee->name} (id {$employee->id}): " . $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done. OK: {$ok}, Skipped: {$skipped}, Failed: {$failed}");

        return $failed > 0 ? 1 : 0;
    }
}
