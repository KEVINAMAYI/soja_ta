<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Employee;
use App\Services\OrgHierarchyResolver;
use Illuminate\Console\Command;

class BackfillOrgHierarchy extends Command
{
    protected $signature = 'org-hierarchy:backfill';
    protected $description = 'Populate unit_id/section_id on existing employees from their legacy division/section text';

    public function handle(OrgHierarchyResolver $resolver): int
    {
        $employees = Employee::whereNotNull('department_id')
            ->where(fn ($q) => $q->whereNotNull('division')->orWhereNotNull('section'))
            ->get();

        $count = 0;

        foreach ($employees as $employee) {
            $org = $employee->organization;
            if (!$org) continue;

            $unitId = $resolver->resolveUnit($org, $employee->division);
            if ($unitId) {
                Department::whereKey($employee->department_id)->whereNull('unit_id')->update(['unit_id' => $unitId]);
            }

            $sectionId = $resolver->resolveSection($org->id, $employee->department_id, $employee->section);

            $employee->forceFill(['unit_id' => $unitId, 'section_id' => $sectionId])->saveQuietly();
            $count++;
        }

        $this->info("Backfilled {$count} employees. Subsection wasn't stored before, so it populates on each employee's next AD sync.");

        return self::SUCCESS;
    }
}
