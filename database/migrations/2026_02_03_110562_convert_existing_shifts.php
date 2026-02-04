<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Models\Organization;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Log::info('Starting multi-shift data integrity migration...');

        // ==========================================
        // STEP 1: Clean up orphaned current_shift_id
        // ==========================================
        // Find employees where current_shift_id is set but NOT in their active assignments
        $orphanedCurrentShifts = DB::table('employees as e')
            ->leftJoin('employee_shift_assignments as esa', function ($join) {
                $join->on('e.id', '=', 'esa.employee_id')
                    ->on('e.current_shift_id', '=', 'esa.shift_id')
                    ->where('esa.is_active', '=', true);
            })
            ->whereNotNull('e.current_shift_id')
            ->whereNull('esa.id')
            ->select('e.id', 'e.name', 'e.current_shift_id')
            ->get();

        \Log::info('Found orphaned current_shift_id', [
            'count' => $orphanedCurrentShifts->count(),
            'employees' => $orphanedCurrentShifts->pluck('name')->toArray()
        ]);

        // Clear orphaned current_shift_id
        DB::table('employees as e')
            ->leftJoin('employee_shift_assignments as esa', function ($join) {
                $join->on('e.id', '=', 'esa.employee_id')
                    ->on('e.current_shift_id', '=', 'esa.shift_id')
                    ->where('esa.is_active', '=', true);
            })
            ->whereNotNull('e.current_shift_id')
            ->whereNull('esa.id')
            ->update(['e.current_shift_id' => null]);

        // ==========================================
        // STEP 2: Migrate legacy shift_id to pivot table
        // ==========================================
        $employeesWithLegacyShifts = DB::table('employees')
            ->whereNotNull('shift_id')
            ->select('id', 'shift_id', 'organization_id', 'name', 'created_at', 'updated_at')
            ->get();

        \Log::info('Migrating legacy shift_id to pivot table', [
            'count' => $employeesWithLegacyShifts->count()
        ]);

        $migratedShifts = 0;
        $skippedExists = 0;

        foreach ($employeesWithLegacyShifts as $employee) {
            // Check if assignment already exists
            $exists = DB::table('employee_shift_assignments')
                ->where('employee_id', $employee->id)
                ->where('shift_id', $employee->shift_id)
                ->exists();

            if ($exists) {
                $skippedExists++;

                // Ensure it's active
                DB::table('employee_shift_assignments')
                    ->where('employee_id', $employee->id)
                    ->where('shift_id', $employee->shift_id)
                    ->update([
                        'is_active' => true,
                        'priority' => 0, // High priority for legacy
                    ]);
            } else {
                // Verify shift exists before inserting
                $shiftExists = DB::table('shifts')->where('id', $employee->shift_id)->exists();

                if ($shiftExists) {
                    DB::table('employee_shift_assignments')->insert([
                        'employee_id' => $employee->id,
                        'shift_id' => $employee->shift_id,
                        'priority' => 0, // High priority for existing/legacy assignments
                        'is_active' => true,
                        'effective_from' => null,
                        'effective_until' => null,
                        'created_at' => $employee->created_at ?? now(),
                        'updated_at' => $employee->updated_at ?? now(),
                    ]);
                    $migratedShifts++;
                } else {
                    \Log::warning('Shift does not exist for employee', [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'shift_id' => $employee->shift_id
                    ]);
                }
            }
        }

        \Log::info('Legacy shift migration complete', [
            'migrated' => $migratedShifts,
            'already_existed' => $skippedExists
        ]);

        // ==========================================
        // STEP 3: Set current_shift_id from shift_id (if not set)
        // ==========================================
        $updatedCurrentShifts = DB::table('employees')
            ->whereNotNull('shift_id')
            ->whereNull('current_shift_id')
            ->update(['current_shift_id' => DB::raw('shift_id')]);

        \Log::info('Set current_shift_id from shift_id', [
            'count' => $updatedCurrentShifts
        ]);

        // ==========================================
        // STEP 4: Auto-assign current_shift_id for employees with assignments but no current shift
        // ==========================================
        $employeesNeedingCurrentShift = DB::table('employees as e')
            ->join('employee_shift_assignments as esa', 'e.id', '=', 'esa.employee_id')
            ->whereNull('e.current_shift_id')
            ->where('esa.is_active', true)
            ->select('e.id', 'e.name')
            ->groupBy('e.id', 'e.name')
            ->get();

        \Log::info('Employees needing current_shift_id assignment', [
            'count' => $employeesNeedingCurrentShift->count()
        ]);

        $autoAssigned = 0;
        foreach ($employeesNeedingCurrentShift as $employee) {
            // Get highest priority active shift
            $highestPriorityShift = DB::table('employee_shift_assignments')
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->orderBy('priority', 'desc')
                ->first();

            if ($highestPriorityShift) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['current_shift_id' => $highestPriorityShift->shift_id]);
                $autoAssigned++;
            }
        }

        \Log::info('Auto-assigned current shifts', ['count' => $autoAssigned]);

        // ==========================================
        // STEP 5: Remove inactive/invalid assignments
        // ==========================================
        // Remove assignments where the shift no longer exists
        $deletedInvalid = DB::table('employee_shift_assignments as esa')
            ->leftJoin('shifts as s', 'esa.shift_id', '=', 's.id')
            ->whereNull('s.id')
            ->delete();

        if ($deletedInvalid > 0) {
            \Log::warning('Removed invalid shift assignments', [
                'count' => $deletedInvalid
            ]);
        }

        // ==========================================
        // STEP 6: Create/update default organization settings
        // ==========================================
        $organizations = DB::table('organizations')
            ->select('id', 'created_at', 'updated_at')
            ->get();

        $orgSettingsCreated = 0;
        $orgSettingsUpdated = 0;

        foreach ($organizations as $org) {
            $exists = DB::table('organization_shift_settings')
                ->where('organization_id', $org->id)
                ->exists();

            if ($exists) {
                // Update existing settings
                DB::table('organization_shift_settings')
                    ->where('organization_id', $org->id)
                    ->update([
                        'updated_at' => now(),
                    ]);
                $orgSettingsUpdated++;
            } else {
                // Create new settings
                DB::table('organization_shift_settings')->insert([
                    'organization_id' => $org->id,
                    'allow_auto_shift_detection' => false, // Disabled by default for existing clients
                    'shift_change_cooldown_minutes' => 240, // 4 hours default
                    'require_approval_for_manual_shift_change' => false,
                    'allow_manual_shift_selection' => false,
                    'auto_detection_minimum_score' => 40,
                    'created_at' => $org->created_at ?? now(),
                    'updated_at' => $org->updated_at ?? now(),
                ]);
                $orgSettingsCreated++;
            }
        }

        \Log::info('Organization settings processed', [
            'created' => $orgSettingsCreated,
            'updated' => $orgSettingsUpdated
        ]);

        // ==========================================
        // STEP 7: Final data integrity report
        // ==========================================
        $finalStats = [
            'total_employees' => DB::table('employees')->count(),
            'employees_with_current_shift' => DB::table('employees')->whereNotNull('current_shift_id')->count(),
            'total_shift_assignments' => DB::table('employee_shift_assignments')->count(),
            'active_shift_assignments' => DB::table('employee_shift_assignments')->where('is_active', true)->count(),
            'employees_with_assignments' => DB::table('employee_shift_assignments')
                ->where('is_active', true)
                ->distinct('employee_id')
                ->count('employee_id'),
            'orphaned_current_shifts_fixed' => $orphanedCurrentShifts->count(),
            'legacy_shifts_migrated' => $migratedShifts,
            'current_shifts_auto_assigned' => $autoAssigned,
        ];

        \Log::info('Multi-shift migration completed successfully', $finalStats);

        // ==========================================
        // STEP 8: Identify remaining issues (if any)
        // ==========================================
        $remainingIssues = DB::select("
            SELECT
                e.id,
                e.name,
                e.email,
                e.current_shift_id,
                COUNT(esa.id) as active_assignments,
                CASE
                    WHEN e.current_shift_id IS NOT NULL AND COUNT(esa.id) = 0
                        THEN 'Has current_shift but no assignments'
                    WHEN e.current_shift_id IS NULL AND COUNT(esa.id) > 0
                        THEN 'Has assignments but no current_shift'
                    ELSE 'OK'
                END as status
            FROM employees e
            LEFT JOIN employee_shift_assignments esa
                ON e.id = esa.employee_id AND esa.is_active = 1
            GROUP BY e.id, e.name, e.email, e.current_shift_id
            HAVING status != 'OK'
        ");

        if (count($remainingIssues) > 0) {
            \Log::warning('Remaining data integrity issues found', [
                'count' => count($remainingIssues),
                'issues' => collect($remainingIssues)->map(function ($issue) {
                    return [
                        'employee' => $issue->name,
                        'status' => $issue->status
                    ];
                })->toArray()
            ]);
        } else {
            \Log::info('✅ No remaining data integrity issues!');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Log::info('Rolling back multi-shift data integrity migration...');

        // ==========================================
        // REVERT: Set shift_id from current_shift_id if needed
        // ==========================================
        $revertedToLegacy = DB::table('employees')
            ->whereNotNull('current_shift_id')
            ->whereNull('shift_id')
            ->update(['shift_id' => DB::raw('current_shift_id')]);

        \Log::info('Reverted current_shift_id to shift_id', [
            'count' => $revertedToLegacy
        ]);

        // ==========================================
        // REVERT: If shift_id is still null, get from highest priority assignment
        // ==========================================
        $employeesWithoutShift = DB::table('employees')
            ->whereNull('shift_id')
            ->select('id', 'name')
            ->get();

        $restoredFromPivot = 0;
        foreach ($employeesWithoutShift as $employee) {
            $highestPriorityAssignment = DB::table('employee_shift_assignments')
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->orderBy('priority', 'desc')
                ->first();

            if ($highestPriorityAssignment) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['shift_id' => $highestPriorityAssignment->shift_id]);
                $restoredFromPivot++;
            }
        }

        \Log::info('Restored shift_id from pivot table', [
            'count' => $restoredFromPivot
        ]);

        \Log::info('Multi-shift migration rollback completed successfully');
    }
};
