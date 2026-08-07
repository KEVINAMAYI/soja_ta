<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Section;
use App\Models\Subsection;
use App\Models\Unit;

class OrgHierarchyResolver
{
    public function resolveUnit(Organization $org, ?string $name): ?int
    {
        $name = $this->clean($name);
        if (!$name) return null;

        $unit = Unit::where('organization_id', $org->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->first();

        return ($unit ?? Unit::create([
            'name' => $name,
            'organization_id' => $org->id,
        ]))->id;
    }

    public function resolveSection(int $organizationId, ?int $departmentId, ?string $name): ?int
    {
        $name = $this->clean($name);
        if (!$name || !$departmentId) return null;

        $section = Section::where('department_id', $departmentId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->first();

        return ($section ?? Section::create([
            'name' => $name,
            'department_id' => $departmentId,
            'organization_id' => $organizationId,
        ]))->id;
    }

    public function resolveSubsection(int $organizationId, ?int $sectionId, ?string $name): ?int
    {
        $name = $this->clean($name);
        if (!$name || !$sectionId) return null;

        $subsection = Subsection::where('section_id', $sectionId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->first();

        return ($subsection ?? Subsection::create([
            'name' => $name,
            'section_id' => $sectionId,
            'organization_id' => $organizationId,
        ]))->id;
    }

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));
        return $value === '' ? null : $value;
    }
}
