<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\JobTitle;
use Illuminate\Support\Collection;

class OrganizationHierarchyService
{
    /**
     * Build the complete organization hierarchy.
     *
     * The hierarchy is derived from Employee records:
     *
     * employee.job_title_id
     *          ↓
     * employee.reports_to_job_title_id
     *
     * Returns:
     * - trees
     * - roots
     * - dangling titles
     * - broken relationships
     * - cycles
     * - duplicate/inconsistent parent relationships
     * - orphaned employees
     */
    public function build(int $organizationId): array
    {
        $jobTitles = $this->loadJobTitles($organizationId);

        $employees = $this->loadEmployees($organizationId);

        $result = [
            'organization_id' => $organizationId,

            'trees' => [],

            'roots' => [],

            'dangling' => [],

            'broken_relationships' => [],

            'cycles' => [],

            'inconsistent_relationships' => [],

            'orphaned_employees' => [],

            'statistics' => [],
        ];

        /*
         * Build the graph from employee records.
         */
        $graphResult = $this->buildGraph(
            $jobTitles,
            $employees
        );

        $graph = $graphResult['graph'];

        $result['broken_relationships'] =
            $graphResult['broken_relationships'];

        $result['inconsistent_relationships'] =
            $graphResult['inconsistent_relationships'];

        $result['orphaned_employees'] =
            $graphResult['orphaned_employees'];

        /*
         * Find circular references before building trees.
         */
        $result['cycles'] = $this->detectCycles($graph);

        /*
         * Find root nodes.
         */
        $roots = $this->findRoots($graph);

        /*
         * Build all trees.
         */
        $visited = [];

        foreach ($roots as $rootId) {
            /*
             * A root involved in a cycle should not be recursively
             * processed as a normal tree.
             */
            if ($this->rootBelongsToCycle($rootId, $result['cycles'])) {
                continue;
            }

            $result['trees'][] = $this->buildTree(
                $rootId,
                $graph,
                $visited
            );
        }

        /*
         * Find completely isolated job titles.
         *
         * No parent + no children.
         */
        $result['dangling'] = $this->findDanglingTitles($graph);

        /*
         * Add convenient root information.
         */
        foreach ($roots as $rootId) {
            if (!isset($graph[$rootId])) {
                continue;
            }

            $result['roots'][] = $this->formatJobTitleSummary(
                $graph[$rootId]
            );
        }

        /*
         * Statistics.
         */
        $result['statistics'] = [
            'job_titles' => count($graph),

            'employees' => $employees->count(),

            'trees' => count($result['trees']),

            'roots' => count($result['roots']),

            'dangling_titles' => count($result['dangling']),

            'broken_relationships' =>
                count($result['broken_relationships']),

            'cycles' =>
                count($result['cycles']),

            'inconsistent_relationships' =>
                count($result['inconsistent_relationships']),

            'orphaned_employees' =>
                count($result['orphaned_employees']),
        ];

        return $result;
    }

    /**
     * Load all job titles belonging to the organization.
     */
    protected function loadJobTitles(int $organizationId): Collection
    {
        return JobTitle::query()
            ->where('organization_id', $organizationId)
            ->get([
                'id',
                'name',
                'description',
                'organization_id',
                'is_active',
            ]);
    }

    /**
     * Load employees belonging to the organization.
     */
    protected function loadEmployees(int $organizationId): Collection
    {
        return Employee::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('job_title_id')
            ->get([
                'id',
                'name',
                'organization_id',
                'job_title_id',
                'reports_to_job_title_id',
            ]);
    }

    /**
     * Build the job-title graph.
     *
     * Each job title becomes a node.
     *
     * Employee records determine:
     *
     * Job Title A
     *      ↓
     * Job Title B
     *
     * where A reports to B.
     */
    protected function buildGraph(
        Collection $jobTitles,
        Collection $employees
    ): array {
        $graph = [];

        $brokenRelationships = [];

        $inconsistentRelationships = [];

        $orphanedEmployees = [];

        /*
         * Initialize every job title.
         *
         * This is important because a job title can exist in the
         * JobTitle table without currently having any employees.
         */
        foreach ($jobTitles as $jobTitle) {
            $graph[$jobTitle->id] = [
                'id' => $jobTitle->id,

                'name' => $jobTitle->name,

                'description' => $jobTitle->description,

                'organization_id' => $jobTitle->organization_id,

                'is_active' => (bool) $jobTitle->is_active,

                /*
                 * Parent determined from employee records.
                 */
                'parent_id' => null,

                /*
                 * Children are populated later.
                 */
                'children' => [],

                /*
                 * Employees holding this title.
                 */
                'employees' => [],

                /*
                 * Track all parent IDs found in employee records.
                 */
                'reported_parent_ids' => [],
            ];
        }

        /*
         * Process employee relationships.
         */
        foreach ($employees as $employee) {
            $jobTitleId = $employee->job_title_id;

            $parentId = $employee->reports_to_job_title_id;

            /*
             * Employee points to a job title that does not exist
             * in this organization.
             */
            if (!isset($graph[$jobTitleId])) {
                $orphanedEmployees[] = [
                    'employee_id' => $employee->id,

                    'employee_name' => $employee->name,

                    'job_title_id' => $jobTitleId,

                    'reports_to_job_title_id' => $parentId,

                    'reason' =>
                        'Employee references a job title that does not exist in this organization.',
                ];

                continue;
            }

            /*
             * Add employee to their job title.
             */
            $graph[$jobTitleId]['employees'][] = [
                'id' => $employee->id,

                'employee_name' => $employee->name,

                'employee_title' => $employee->employee_title,
            ];

            /*
             * No parent means this employee's job title may be a root.
             */
            if ($parentId === null) {
                $graph[$jobTitleId]['reported_parent_ids']['null'] = true;

                continue;
            }

            /*
             * Prevent self-reporting.
             *
             * Example:
             *
             * CEO → CEO
             */
            if ((int) $jobTitleId === (int) $parentId) {
                $brokenRelationships[] = [
                    'type' => 'self_reference',

                    'job_title_id' => $jobTitleId,

                    'job_title_name' =>
                        $graph[$jobTitleId]['name'],

                    'reports_to_job_title_id' => $parentId,

                    'employee_id' => $employee->id,

                    'employee_name' => $employee->name,

                    'reason' =>
                        'A job title cannot report to itself.',
                ];

                continue;
            }

            /*
             * Parent does not exist in this organization.
             */
            if (!isset($graph[$parentId])) {
                $brokenRelationships[] = [
                    'type' => 'missing_parent',

                    'job_title_id' => $jobTitleId,

                    'job_title_name' =>
                        $graph[$jobTitleId]['name'],

                    'reports_to_job_title_id' => $parentId,

                    'employee_id' => $employee->id,

                    'employee_name' => $employee->name,

                    'reason' =>
                        'The reports_to_job_title_id does not exist in this organization.',
                ];

                /*
                 * Do not add this as a valid parent.
                 */
                continue;
            }

            /*
             * Track parent relationships.
             */
            $graph[$jobTitleId]['reported_parent_ids'][(string) $parentId] = true;

            /*
             * If another employee with the same job title reports
             * to a different job title, the data is inconsistent.
             */
            $existingParents = array_keys(
                $graph[$jobTitleId]['reported_parent_ids']
            );

            $existingNonNullParents = array_filter(
                $existingParents,
                fn ($id) => $id !== 'null'
            );

            if (
                count(array_unique($existingNonNullParents)) > 1
            ) {
                $inconsistentRelationships[] = [
                    'job_title_id' => $jobTitleId,

                    'job_title_name' =>
                        $graph[$jobTitleId]['name'],

                    'employee_id' => $employee->id,

                    'employee_name' => $employee->name,

                    'parent_ids' =>
                        array_values($existingNonNullParents),

                    'reason' =>
                        'Employees holding the same job title report to different job titles.',
                ];
            }
        }

        /*
         * Determine the actual parent for each job title.
         *
         * We only use valid parent IDs.
         */
        foreach ($graph as $jobTitleId => &$node) {
            $parentIds = array_keys(
                $node['reported_parent_ids']
            );

            $validParentIds = array_filter(
                $parentIds,
                fn ($id) =>
                    $id !== 'null' &&
                    isset($graph[(int) $id])
            );

            /*
             * If exactly one valid parent exists, use it.
             */
            if (count($validParentIds) === 1) {
                $node['parent_id'] = (int) reset($validParentIds);
            }

            /*
             * If there are multiple parents, we deliberately do not
             * choose one automatically.
             *
             * This prevents building an incorrect hierarchy.
             */
            if (count($validParentIds) > 1) {
                $node['parent_id'] = null;
            }
        }

        unset($node);

        /*
         * Build children.
         */
        foreach ($graph as $jobTitleId => &$node) {
            $parentId = $node['parent_id'];

            if ($parentId === null) {
                continue;
            }

            if (!isset($graph[$parentId])) {
                continue;
            }

            $graph[$parentId]['children'][] = $jobTitleId;
        }

        unset($node);

        /*
         * Remove internal validation data from final graph.
         */
        foreach ($graph as &$node) {
            unset($node['reported_parent_ids']);
        }

        unset($node);

        return [
            'graph' => $graph,

            'broken_relationships' =>
                $brokenRelationships,

            'inconsistent_relationships' =>
                $inconsistentRelationships,

            'orphaned_employees' =>
                $orphanedEmployees,
        ];
    }

    /**
     * Find root nodes.
     *
     * A root has:
     *
     * - parent_id = null
     * - at least one child
     *
     * Completely isolated titles (no parent + no children) are
     * treated as dangling and excluded from roots.
     */
    protected function findRoots(array $graph): array
    {
        $roots = [];

        foreach ($graph as $jobTitleId => $node) {
            if (
                $node['parent_id'] === null &&
                !empty($node['children'])
            ) {
                $roots[] = $jobTitleId;
            }
        }

        return $roots;
    }

    /**
     * Find completely isolated/dangling job titles.
     *
     * A dangling title:
     *
     * - has no parent
     * - has no children
     */
    protected function findDanglingTitles(array $graph): array
    {
        $dangling = [];

        foreach ($graph as $node) {
            if (
                $node['parent_id'] === null &&
                empty($node['children'])
            ) {
                $dangling[] = $this->formatJobTitleSummary($node);
            }
        }

        return $dangling;
    }

    /**
     * Detect circular references.
     *
     * Example:
     *
     * A → B
     * B → C
     * C → A
     */
    protected function detectCycles(array $graph): array
    {
        $cycles = [];

        $visited = [];

        $recursionStack = [];

        foreach (array_keys($graph) as $jobTitleId) {
            if (isset($visited[$jobTitleId])) {
                continue;
            }

            $path = [];

            $this->detectCycleFromNode(
                $jobTitleId,
                $graph,
                $visited,
                $recursionStack,
                $path,
                $cycles
            );
        }

        /*
         * Remove duplicate cycles.
         */
        $uniqueCycles = [];

        foreach ($cycles as $cycle) {
            $key = implode(
                '->',
                $cycle['job_title_ids']
            );

            if (!isset($uniqueCycles[$key])) {
                $uniqueCycles[$key] = $cycle;
            }
        }

        return array_values($uniqueCycles);
    }

    /**
     * Recursive cycle detection.
     */
    protected function detectCycleFromNode(
        int $jobTitleId,
        array $graph,
        array &$visited,
        array &$recursionStack,
        array &$path,
        array &$cycles
    ): void {
        if (isset($recursionStack[$jobTitleId])) {
            /*
             * We found a cycle.
             */
            $cycleStart = array_search(
                $jobTitleId,
                $path,
                true
            );

            if ($cycleStart !== false) {
                $cycleIds = array_slice(
                    $path,
                    $cycleStart
                );

                $cycleNames = [];

                foreach ($cycleIds as $id) {
                    if (isset($graph[$id])) {
                        $cycleNames[] = $graph[$id]['name'];
                    }
                }

                $cycles[] = [
                    'job_title_ids' => $cycleIds,

                    'job_title_names' => $cycleNames,

                    'reason' =>
                        'Circular reporting relationship detected.',
                ];
            }

            return;
        }

        if (isset($visited[$jobTitleId])) {
            return;
        }

        $visited[$jobTitleId] = true;

        $recursionStack[$jobTitleId] = true;

        $path[] = $jobTitleId;

        $parentId = $graph[$jobTitleId]['parent_id'] ?? null;

        if (
            $parentId !== null &&
            isset($graph[$parentId])
        ) {
            $this->detectCycleFromNode(
                $parentId,
                $graph,
                $visited,
                $recursionStack,
                $path,
                $cycles
            );
        }

        array_pop($path);

        unset($recursionStack[$jobTitleId]);
    }

    /**
     * Check whether a root is part of a detected cycle.
     */
    protected function rootBelongsToCycle(
        int $rootId,
        array $cycles
    ): bool {
        foreach ($cycles as $cycle) {
            if (
                in_array(
                    $rootId,
                    $cycle['job_title_ids'],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively build a tree.
     */
    protected function buildTree(
        int $jobTitleId,
        array &$graph,
        array &$visited,
        array $level = []
    ): array {
        /*
         * Safety check against infinite recursion.
         */
        if (isset($visited[$jobTitleId])) {
            return [
                'id' => $jobTitleId,

                'name' => $graph[$jobTitleId]['name'] ?? null,

                'error' => 'circular_reference',

                'children' => [],
            ];
        }

        /*
         * Another safety check.
         */
        if (!isset($graph[$jobTitleId])) {
            return [
                'id' => $jobTitleId,

                'name' => null,

                'error' => 'job_title_not_found',

                'children' => [],
            ];
        }

        $visited[$jobTitleId] = true;

        $node = $graph[$jobTitleId];

        $currentLevel = count($level);

        $tree = [
            'id' => $node['id'],

            'name' => $node['name'],

            'description' => $node['description'],

            'organization_id' =>
                $node['organization_id'],

            'is_active' =>
                $node['is_active'],

            'level' => $currentLevel,

            'parent_id' => $node['parent_id'],

            'employee_count' =>
                count($node['employees']),

            'employees' =>
                $node['employees'],

            'children' => [],
        ];

        /*
         * Process children.
         */
        foreach ($node['children'] as $childId) {
            $tree['children'][] = $this->buildTree(
                $childId,
                $graph,
                $visited,
                [...$level, $jobTitleId]
            );
        }

        /*
         * Remove from current traversal path.
         *
         * We do not remove it permanently from all traversal,
         * because separate trees/branches should be able to use
         * their own traversal state.
         */
        unset($visited[$jobTitleId]);

        return $tree;
    }

    /**
     * Format a job title for summary responses.
     */
    protected function formatJobTitleSummary(array $node): array
    {
        return [
            'id' => $node['id'],

            'name' => $node['name'],

            'description' => $node['description'],

            'organization_id' =>
                $node['organization_id'],

            'is_active' =>
                $node['is_active'],

            'parent_id' =>
                $node['parent_id'],

            'employee_count' =>
                count($node['employees']),

            'employee_ids' =>
                collect($node['employees'])
                    ->pluck('id')
                    ->values()
                    ->all(),

            'children_ids' =>
                array_values($node['children']),
        ];
    }
}