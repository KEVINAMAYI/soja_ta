<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoDeactivateEmployees extends Command
{
    protected $signature = 'employees:auto-deactivate';
    protected $description = "Deactivate employees older than each organization's auto-deactivation policy (System Settings > Employee Lifecycle)";

    public function handle(): int
    {
        $orgIds = OrganizationSetting::where('key', 'auto_deactivate_enabled')
            ->pluck('value', 'organization_id')
            ->filter(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN))
            ->keys();

        if ($orgIds->isEmpty()) {
            $this->info("TIME ".now()->toDateTimeString()." - No organizations have the auto-deactivation policy enabled.");
            return Command::SUCCESS;
        }

        $totalDeactivated = 0;

        foreach ($orgIds as $orgId) {
            $org = Organization::find($orgId);
            if (!$org) {
                continue;
            }

            $settings = OrganizationSetting::where('organization_id', $orgId)
                ->whereIn('key', ['auto_deactivate_after_value', 'auto_deactivate_after_unit'])
                ->pluck('value', 'key');

            $value = (int) ($settings['auto_deactivate_after_value'] ?? 0);
            $unit = $settings['auto_deactivate_after_unit'] ?? 'months';

            if ($value <= 0) {
                $this->warn("TIME ".now()->toDateTimeString()." - Org {$orgId} ({$org->name}): policy enabled but no valid duration set - skipping.");
                continue;
            }

            $cutoff = $unit === 'days' ? now()->subDays($value) : now()->subMonths($value);

            $employees = Employee::where('organization_id', $orgId)
                ->where('active', 1)
                ->where('created_at', '<=', $cutoff)
                ->get();

            if ($employees->isEmpty()) {
                continue;
            }

            foreach ($employees as $employee) {
                $employee->active = false;
                $employee->save();
                $totalDeactivated++;
            }

            $this->info("TIME ".now()->toDateTimeString()." - Org {$orgId} ({$org->name}): deactivated {$employees->count()} employee(s) created before {$cutoff->toDateString()}.");
        }

        $this->info("TIME ".now()->toDateTimeString()." - Done. Total deactivated: {$totalDeactivated}.");

        return Command::SUCCESS;
    }
}