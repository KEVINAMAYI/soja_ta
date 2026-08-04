<?php

namespace App\Console\Commands;

use App\Models\Department;
use Illuminate\Console\Command;

class DeriveDepartmentGroups extends Command
{
    protected $signature = 'departments:derive';
    protected $description = 'Backfill departments.department_derived: apply the confirmed canonical-name mapping for known AD-sync duplicate clusters, then group everything else by its name prefix (text before the first " - ")';

    /**
     * organization_id => [canonical department_derived name => [department names to merge into it]]
     *
     * Confirmed against the live departments table for organization 2 (Cosmos Pharmaceuticals) —
     * see docs/department-name-inconsistency.md and docs/pmo-summary-ad-department-findings.md.
     * Matched by exact current `name`, not by id, so this stays valid if AD sync re-creates
     * one of these rows under the same name after this command has already run once.
     */
    private const CLUSTERS = [
        2 => [
            'Finance' => [
                'Finance',
                'Finance - Controlling - Billing',
                'Finance - Controlling - Costing',
                'Finance - Controlling - Credit Control',
                'Finance - Financial Accounting',
                'Finance - Financial Accounting - General Ledger',
                'Finance - Financial Accounting - Payables',
                'Finance - Treasury, Tax & Investments - Treasury',
                'FFinance - Financial Accounting',
            ],
            'Sales and Marketing' => [
                'SALES',
                'Sales & Marketing',
                'Sales & Marketing -Vet',
                'Sales & Marketing Operations',
            ],
            'Human Resources and Administration' => [
                'HR',
                'HR & Admin',
                'Human Resource',
                'Human Resources and Administration',
                'Human Resources and Administration - Admin',
                'Human Resources and Administration - HR',
            ],
            'Information Technology' => [
                'IT Department',
                'Information Technology',
            ],
            'Formulation & Development' => [
                'Formulation & Development',
                'Formulation and Development',
            ],
            'Regulatory Affairs' => [
                'Regulatory',
                'Regulatory Affairs',
                'Regulatory Affairs - Registration',
                'Regulatory Affairs - Regulatory',
            ],
            'Engineering and Projects' => [
                'Engineering',
                'Engineering and Projects',
                'Engineering and Projects - Electrical',
                'Engineering and Projects - Mechanical',
                'Engineering and Projects - Projects',
                'Engineering and Projects - Utility',
            ],
        ],
    ];

    public function handle(): int
    {
        $mapped = 0;

        foreach (self::CLUSTERS as $organizationId => $groups) {
            foreach ($groups as $canonical => $names) {
                $updated = Department::where('organization_id', $organizationId)
                    ->whereIn('name', $names)
                    ->update(['department_derived' => $canonical]);

                $mapped += $updated;
                $this->info("[{$canonical}] matched {$updated} department row(s)");
            }
        }

        // Everything not covered by a cluster above: group by the text before the first
        // " - " in the name (e.g. "Production - Packing" and "Production - Tablets" both
        // become "Production"). Names with no " - " become their own single-row group.
        // Unlike the clusters above, this is an unambiguous, purely mechanical split —
        // no risk of merging two genuinely different departments together.
        $remaining = Department::whereNull('department_derived')
            ->orWhere('department_derived', '')
            ->get(['id', 'name']);

        foreach ($remaining as $department) {
            $prefix = trim(preg_split('/\s*-\s*/', trim($department->name), 2)[0]);
            $department->update(['department_derived' => $prefix]);
        }

        $this->newLine();
        $this->info("Clusters applied: {$mapped} row(s)");
        $this->info("Prefix-grouped: {$remaining->count()} row(s)");

        return Command::SUCCESS;
    }
}
