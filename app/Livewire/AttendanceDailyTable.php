<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\AttendanceFullExport;
use App\Exports\AttendancePivotDailyExcelExport;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceSeeder;
use Illuminate\Support\Facades\DB;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;
    public $status;
    public $startDate;
    public $endDate;
    public $filterGrade;
    public $unitId = null;
    public $departmentId = null;
    public $sectionId = null;
    public $subsectionId = null;
    public $includeOutsourced = false;

    public $employeeType = 'all';



    public function mount($status = null): void
    {
        $this->status = $status;
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
        $this->maybeSeed();
    }

    #[On('date-range-updated')]
    public function filterByDateRange(
        $startDate, $endDate, $status, $grade = null, $employee_type = null,
        $unit_id = null, $department_id = null, $section_id = null, $subsection_id = null, $include_outsourced = null
    ): void {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->status = $status;
        $this->filterGrade = $grade;
        $this->employeeType = $employee_type ?? 'all';
        $this->unitId = $unit_id;
        $this->departmentId = $department_id;
        $this->sectionId = $section_id;
        $this->subsectionId = $subsection_id;
        $this->includeOutsourced = (bool) $include_outsourced;
        $this->maybeSeed();
        $this->dispatch('refreshDatatable');
    }

    /**
     * Applies the Unit>Department>Section>Subsection filter to an employee-scoped query.
     * Outsourced employees have null unit/department/section/subsection by definition,
     * so they're excluded by an active filter unless includeOutsourced ORs them back in.
     */
    private function applyHierarchyFilter($q): void
    {
        $active = $this->unitId || $this->departmentId || $this->sectionId || $this->subsectionId;
        if (!$active) return;

        $q->where(function ($h) {
            $h->where(function ($levels) {
                if ($this->unitId) $levels->where('unit_id', $this->unitId);
                if ($this->departmentId) $levels->where('department_id', $this->departmentId);
                if ($this->sectionId) $levels->where('section_id', $this->sectionId);
                if ($this->subsectionId) $levels->where('subsection_id', $this->subsectionId);
            });
            if ($this->includeOutsourced) {
                $h->orWhere('employee_type', 'Outsourced');
            }
        });
    }

    private function maybeSeed(): void
    {
        $isSchool = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);
        $orgId = auth()->user()->employee->organization_id ?? null;
        if (!$orgId || $isSchool) return;
        if (in_array($this->status, ['unchecked_in', 'absent', 'on_leave', 'off_shift', 'sick_off'])) {
            app(AttendanceSeeder::class)->seedMissingAttendanceRecords($orgId);
        }
    }

    public function configure(): void
    {
        $this->setPrimaryKey('id');

        // Keep selections when the user searches or changes filters
        $this->setClearSelectedOnSearchDisabled();
        $this->setClearSelectedOnFilterDisabled();
    }

    public function bulkActions(): array
    {
        return [
            'exportExcel' => 'Export Selected (Excel)',
            'exportPivotExcel' => 'Export Selected (Pivot Excel)',
            'exportFullExcel' => 'Export Selected (Full Excel T&A)',
            'exportPdf' => 'Export Selected (PDF)',
        ];
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $isSchool = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);
        $startDate = $this->startDate ?: now()->toDateString();
        $endDate = $this->endDate ?: $startDate;
        $status = $this->status;
        $search = $this->search;
        $grade = $this->filterGrade ?? null;

        if ($isSchool && $status === 'absent') return $this->buildSchoolAbsentQuery($orgId, $startDate, $grade, $search);

        if ($status === 'inactive') {
            $query = Attendance::query()->select('attendances.*')
                ->with(['employee', 'employee.shifts', 'shift'])
                ->whereBetween('date', [$startDate, $endDate])
                ->whereHas('employee', function ($q) use ($orgId, $grade, $isSchool) {
                    $q->where('organization_id', $orgId)->where('active', 0)->where('is_student', $isSchool ? 1 : 0);
                    if ($grade) $q->where('grade', $grade);
                    $this->applyHierarchyFilter($q);
                });
            if ($search) $query->where(fn($q) => $q->where('status', 'like', "%$search%")->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%")));
            return $query->orderByDesc('date')->orderByRaw('check_in_time IS NULL')->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
        }

        $query = Attendance::query()->select('attendances.*')
            ->with(['employee', 'employee.shifts', 'shift'])
            ->whereBetween('date', [$startDate, $endDate])
            ->whereHas('employee', function ($q) use ($orgId, $grade, $isSchool) {
                $q->where('organization_id', $orgId)->where('active', 1)->where('is_student', $isSchool ? 1 : 0);
                if ($grade) $q->where('grade', $grade);
                $this->applyHierarchyFilter($q);
            });

        if ($isSchool) {
            $query->where(fn($q) => $q->whereBetween(DB::raw('DATE(check_in_time)'), [$startDate, $endDate])->orWhereBetween(DB::raw('DATE(check_out_time)'), [$startDate, $endDate]));
        } else {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        if (!empty($status)) {
            match ($status) {
                'absent' => $query->whereIn('status', ['absent', 'unchecked_in']),
                'present' => $query->whereIn('status', ['clocked_in', 'clocked_out']),
                default => $query->where('status', $status),
            };
        }

        if ($search) $query->where(fn($q) => $q->where('status', 'like', "%$search%")->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%")));

        return $query->orderByDesc('date')->orderByRaw('check_in_time IS NULL')->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
    }

    private function buildSchoolAbsentQuery(?int $orgId, string $date, ?string $grade, ?string $search): \Illuminate\Database\Eloquent\Builder
    {
        $scannedIds = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId)->where('active', 1)->where('is_student', 1))
            ->whereDate('date', $date)->whereIn('status', ['clocked_in', 'clocked_out'])->pluck('employee_id')->unique()->toArray();
        $unscannedQuery = Employee::where('organization_id', $orgId)->where('active', 1)->where('is_student', 1)->whereNotIn('id', $scannedIds);
        if ($grade) $unscannedQuery->where('grade', $grade);
        if ($search) $unscannedQuery->where('name', 'like', "%$search%");
        $unscannedIds = $unscannedQuery->pluck('id')->toArray();
        $this->seedAbsentRecordsForStudents($unscannedIds, $date, $orgId);
        return Attendance::query()->select('attendances.*')->with(['employee', 'employee.shifts', 'shift'])
            ->whereIn('employee_id', $unscannedIds)->whereDate('date', $date)
            ->whereHas('employee', function ($q) use ($orgId, $grade) {
                $q->where('organization_id', $orgId)->where('active', 1)->where('is_student', 1);
                if ($grade) $q->where('grade', $grade);
            })->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
    }

    private function seedAbsentRecordsForStudents(array $studentIds, string $date, ?int $orgId): void
    {
        if (empty($studentIds)) return;
        $alreadyHaveRecord = Attendance::whereIn('employee_id', $studentIds)->whereDate('date', $date)->pluck('employee_id')->unique()->toArray();
        $needsRecord = array_diff($studentIds, $alreadyHaveRecord);
        if (empty($needsRecord)) return;
        $stillOnCampus = Attendance::whereIn('employee_id', $needsRecord)->where('status', 'clocked_in')->whereDate('check_in_time', '<', $date)->pluck('employee_id')->unique()->toArray();
        $needsRecord = array_diff($needsRecord, $stillOnCampus);
        foreach ($needsRecord as $studentId) {
            Attendance::firstOrCreate(['employee_id' => $studentId, 'date' => $date], ['status' => 'absent', 'check_in_time' => null, 'check_out_time' => null, 'organization_id' => $orgId]);
        }
    }

    protected function getLastAttendance($employeeId, $targetDate)
    {
        if (!$employeeId) return null;
        return Attendance::where('employee_id', $employeeId)->where('date', '<', $targetDate)
            ->where(fn($q) => $q->whereNotNull('check_in_time')->orWhereNotNull('check_out_time'))
            ->orderByDesc('date')->first();
    }

    public function formatMinutes($minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) return $minutes . 'm';
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }

    /**
     * Check if the shift for a given attendance row has ended.
     * Returns true if shift end has passed, false if still running.
     */
    private function shiftHasEnded($row): bool
    {
        $resolvedShift = $row->shift
            ?? $row->employee->shifts?->firstWhere('pivot.is_primary', true)
            ?? $row->employee->shifts?->first();

        if (!$resolvedShift) return true; // no shift info → assume ended

        try {
            $shiftEnd = Carbon::parse($resolvedShift->end_time);
            $shiftStart = Carbon::parse($resolvedShift->start_time);
            if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay(); // overnight shift
            return now()->gt($shiftEnd);
        } catch (\Throwable) {
            return true;
        }
    }

    public function columns(): array
    {
        $isSchool = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);
        $targetDate = $this->startDate ?: now()->toDateString();

        return [

            Column::make($isSchool ? 'Student' : 'Employee')
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            // ── SHIFT COLUMN ──────────────────────────────────────────────────
            Column::make($isSchool ? 'Grade' : 'Shift')
                ->label(function ($row) use ($isSchool) {
                    if ($isSchool) {
                        $grade = $row->employee->grade ?? null;
                        return $grade ? "<strong>{$grade}</strong>" : '<span class="text-muted">-</span>';
                    }

                    $shift = $row->shift
                        ?? $row->employee->shifts?->firstWhere('pivot.is_primary', true)
                        ?? $row->employee->shifts?->first();

                    if (!$shift) return '<span class="text-muted">—</span>';

                    $start = Carbon::parse($shift->start_time)->format('g:i A');
                    $end = Carbon::parse($shift->end_time)->format('g:i A');
                    $isNight = ($shift->shift_type ?? '') === 'night';
                    $typeBadge = $isNight
                        ? "<span style='background:#1e1b4b;color:#fff;font-size:0.6rem;padding:1px 6px;border-radius:99px;margin-left:4px;'>Night</span>"
                        : "<span style='background:#d1fae5;color:#065f46;font-size:0.6rem;padding:1px 6px;border-radius:99px;margin-left:4px;'>Day</span>";

                    return "<strong>{$shift->name}</strong>{$typeBadge}<br><small class='text-muted'>{$start} – {$end}</small>";
                })
                ->html(),

            // ── CLOCK IN ──────────────────────────────────────────────────────
            Column::make('Clock In', 'check_in_time')
                ->format(function ($value, $row) use ($targetDate, $isSchool) {
                    $label = '';
                    $badge = '';

                    if ($isSchool && in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_in_time;
                        if ($value) $label = "<br><small class='text-muted'>(Last Clock-In)</small>";
                    }

                    // Missed clock-in — only show if it's NOT a still-in situation
                    $scenario = $row->scenario ?? '';
                    if (str_starts_with($scenario, 'missed_clockin')) {
                        return "<span style='background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:4px;font-size:.75rem;font-weight:600;'>⚠ Missed Clock-IN</span>";
                    }

                    if (empty($value)) {
                        $formatted = "<span class='fw-semibold text-muted'>—</span>";
                    } else {
                        $formatted = "<span class='fw-semibold text-success'>" . Carbon::parse($value)->format('M d, Y g:i A') . "</span>";
                        if (!$isSchool) {
                            if ($row->is_late_checkin && !$row->within_grace_period) {
                                $badge = "<br><span style='background:#dc3545;color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>🔴 {$this->formatMinutes($row->minutes_late)} Late</span>";
                            } elseif ($row->is_late_checkin && $row->within_grace_period) {
                                $badge = "<br><span style='background:#ffc107;color:#000;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>⏰ {$this->formatMinutes($row->minutes_late)} (Grace)</span>";
                            }
                        }
                    }

                    return "{$formatted}{$badge}{$label}";
                })
                ->html(),

            // ── CLOCK OUT ─────────────────────────────────────────────────────
            Column::make('Clock Out', 'check_out_time')
                ->format(function ($value, $row) use ($targetDate, $isSchool) {
                    $label = '';
                    $badge = '';

                    if ($isSchool && in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_out_time;
                        if ($value) $label = "<br><small class='text-muted'>(Last Clock-Out)</small>";
                    }

                    // ── STILL IN check — must come BEFORE missed_clockout ─────
                    // The stored scenario may say missed_clockout (from a prior sync)
                    // but if it's today and the shift hasn't ended, show "Still In".
                    if (!$isSchool && $row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        $isToday = Carbon::parse($row->date)->isToday();
                        if ($isToday && !$this->shiftHasEnded($row)) {
                            return "<span style='background:green;color:#fff;padding:4px 12px;border-radius:4px;font-size:.75rem;'>Still In</span>";
                        }
                        // Past date OR shift has ended → fall through
                    }

                    // ── MISSED CLOCK-OUT (shift has ended, no clock-out punch) ─
                    $scenario = $row->scenario ?? '';
                    if (str_starts_with($scenario, 'missed_clockout')) {
                        $checkin = $row->check_in_time
                            ? "<br><small class='text-muted'>In: " . Carbon::parse($row->check_in_time)->format('g:i A') . "</small>"
                            : '';
                        return "<span style='background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:4px;font-size:.75rem;font-weight:600;'>⚠ Missed Clock-OUT</span>{$checkin}";
                    }

                    // ── Generic still-in (school / no shift info) ────────────
                    if ($row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        return "<span style='background:green;color:#fff;padding:4px 12px;border-radius:4px;font-size:.75rem;'>Still In</span>";
                    }

                    $formatted = $row->check_out_time
                        ? "<span class='fw-semibold text-success'>" . Carbon::parse($row->check_out_time)->format('M d, Y g:i A') . "</span>"
                        : (empty($value)
                            ? "<span class='fw-semibold text-muted'>—</span>"
                            : "<span class='fw-semibold text-success'>" . Carbon::parse($value)->format('M d, Y g:i A') . "</span>");

                    if (!$isSchool && $row->is_early_checkout && $row->minutes_early > 0) {
                        $badge = "<br><span style='background:#ff6b6b;color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>⚠️ {$this->formatMinutes($row->minutes_early)} Early</span>";
                    }

                    return "{$formatted}{$badge}{$label}";
                })
                ->html(),

            Column::make($isSchool ? 'Status' : 'Work Summary')
                ->label(fn($row) => view('livewire.admin.attendance.hours', ['attendance' => $row])),
        ];
    }

    #[On('export-daily-excel')]
    public function exportExcel()
    {
        return Excel::download(new AttendanceDailyExcelExport(selectedIds: $this->getSelected(), startDate: $this->startDate, endDate: $this->endDate, status: $this->status), 'attendance.xlsx');
    }

    #[On('export-pivot-daily-excel')]
    public function exportPivotExcel()
    {
        return Excel::download(new AttendancePivotDailyExcelExport(selectedIds: $this->getSelected(), startDate: $this->startDate, endDate: $this->endDate, status: $this->status), 'attendance.xlsx');
    }

    #[On('export-full-excel')]
    public function exportFullExcel()
    {
        ini_set('memory_limit', '512M');

        $orgId = auth()->user()->employee->organization_id ?? null;
        $filename = 'T_A_Report_' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(
            new AttendanceFullExport(
                orgId: $orgId,
                ids: $this->getSelected(),
                startDate: $this->startDate,
                endDate: $this->endDate,
                // AttendanceReportService::getMaster() still takes departmentIds as an
                // array (kept for backward compatibility) — adapt the singular filter.
                departmentIds: $this->departmentId ? [$this->departmentId] : [],
                employeeType: $this->employeeType ?? null,
                unitId: $this->unitId,
                sectionId: $this->sectionId,
                subsectionId: $this->subsectionId,
                includeOutsourced: $this->includeOutsourced,
            ),
            $filename
        );
    }

    #[On('export-daily-pdf')]
    public function exportPdf()
    {
        $url = route('attendance-daily.export.pdf', ['ids' => $this->getSelected(), 'start_date' => $this->startDate, 'end_date' => $this->endDate, 'status' => $this->status]);
        return redirect()->to($url);
    }
}
