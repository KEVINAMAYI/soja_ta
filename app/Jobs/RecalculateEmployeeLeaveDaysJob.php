<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repairs historical leave records whose num_of_days / expected_resumption
 * were stored without applying the leave type's weekend/holiday rules, then
 * rebuilds the affected leave balances from scratch (used_days reset to 0 and
 * re-accumulated from approved leaves).
 */
class RecalculateEmployeeLeaveDaysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /** @param array<int,int> $employeeIds */
    public function __construct(
        public array $employeeIds,
        public ?int $year = null,
        public bool $dryRun = false,
    ) {
    }

    public function handle(): void
    {
        $employees = Employee::withTrashed()
            ->with('shift')
            ->whereIn('id', $this->employeeIds)
            ->get();

        foreach ($employees as $employee) {
            try {
                $this->processEmployee($employee);
            } catch (\Throwable $e) {
                Log::error('Leave recalculation failed for employee', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processEmployee(Employee $employee): void
    {
        $leaves = Leave::with('leaveType.organization')
            ->where('employee_id', $employee->id)
            ->when($this->year, fn ($q) => $q->whereYear('start_date', $this->year))
            ->orderBy('start_date')
            ->get();

        if ($leaves->isEmpty()) {
            return;
        }

        $typeCache = [];
        $years = [];

        DB::transaction(function () use ($employee, $leaves, &$typeCache, &$years) {
            foreach ($leaves as $leave) {
                if (!$leave->start_date || !$leave->end_date) {
                    continue;
                }

                $years[Carbon::parse($leave->start_date)->year] = true;

                $type = $this->resolveLeaveType($leave, $typeCache);
                if (!$type) {
                    continue;
                }

                $start = Carbon::parse($leave->start_date)->startOfDay();
                $end = Carbon::parse($leave->end_date)->startOfDay();

                $numOfDays = $type->calculateNumberOfDaysFromLeaveStartAndEndDates(
                    $start->copy(),
                    $end->copy()
                )['effective_leave_days'];

                $resumption = $employee->shift
                    ? $type->calculateReturnWithEndDate($end->copy(), $employee->shift)
                    : $end->copy()->addDay();

                $attributes = [
                    'num_of_days' => $numOfDays,
                    'expected_resumption' => $resumption->toDateString(),
                ];

                if (!$leave->leave_type_id) {
                    $attributes['leave_type_id'] = $type->id;
                }

                if ($this->dryRun) {
                    Log::info('Leave recalculation (dry run)', [
                        'leave_id' => $leave->id,
                        'from' => [
                            'num_of_days' => $leave->num_of_days,
                            'expected_resumption' => optional($leave->expected_resumption)->toDateString(),
                        ],
                        'to' => $attributes,
                    ]);
                    continue;
                }
                Log::info("FORCE FILLING!!!");
                $leave->forceFill($attributes)->save();
            }

            if (!$this->dryRun) {
                foreach (array_keys($years) as $year) {
                    $this->rebuildBalances($employee, $year);
                }
            }
        });
    }

    /**
     * Historical rows may only carry the leave_type name string, so fall back
     * to matching by code/name within the leave's organization.
     *
     * @param array<string,LeaveType|null> $cache
     */
    private function resolveLeaveType(Leave $leave, array &$cache): ?LeaveType
    {
        if ($leave->leaveType) {
            return $leave->leaveType;
        }

        $orgId = $leave->organization_id;
        $label = $leave->leave_type;

        if (!$orgId || !$label) {
            return null;
        }

        $key = $orgId . '|' . $label;

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = LeaveType::where('organization_id', $orgId)
                ->where(fn ($q) => $q->where('code', $label)->orWhere('name', $label))
                ->first();
        }

        return $cache[$key];
    }

    private function rebuildBalances(Employee $employee, int $year): void
    {
        LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->update(['used_days' => 0]);

        $usedPerType = Leave::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereNotNull('leave_type_id')
            ->whereYear('start_date', $year)
            ->groupBy('leave_type_id')
            ->selectRaw('leave_type_id, SUM(num_of_days) as total_days')
            ->pluck('total_days', 'leave_type_id');

        if ($usedPerType->isEmpty()) {
            return;
        }

        $types = LeaveType::whereIn('id', $usedPerType->keys())->get()->keyBy('id');

        foreach ($usedPerType as $leaveTypeId => $totalDays) {
            $type = $types->get($leaveTypeId);

            if (!$type || $type->annual_entitlement_days === null) {
                continue;
            }

            $balance = LeaveBalance::firstOrNew([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
            ]);

            // Preserve any existing (possibly admin-overridden) entitlement.
            if (!$balance->exists) {
                $balance->organization_id = $employee->organization_id;
                $balance->entitled_days = $type->annual_entitlement_days;
            }

            $balance->used_days = (float) $totalDays;
            $balance->save();
        }
    }
}
