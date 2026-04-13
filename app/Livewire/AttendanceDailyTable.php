<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
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

    public function mount($status = null): void
    {
        $this->status    = $status;
        $this->startDate = now()->toDateString();
        $this->endDate   = now()->toDateString();

        $this->maybeSeed();
    }

    #[On('date-range-updated')]
    public function filterByDateRange($startDate, $endDate, $status, $grade = null): void
    {
        $this->startDate   = $startDate;
        $this->endDate     = $endDate;
        $this->status      = $status;
        $this->filterGrade = $grade;

        $this->maybeSeed();
        $this->dispatch('refreshDatatable');
    }

    /**
     * Seed missing attendance records for staff orgs only.
     * School orgs don't use seeded absent records — they query employees directly.
     */
    private function maybeSeed(): void
    {
        $isSchool = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);
        $orgId    = auth()->user()->employee->organization_id ?? null;

        if (!$orgId || $isSchool) return;

        if (in_array($this->status, ['unchecked_in', 'absent', 'on_leave', 'off_shift', 'sick_off'])) {
            app(AttendanceSeeder::class)->seedMissingAttendanceRecords($orgId);
        }
    }

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId    = auth()->user()->employee->organization_id ?? null;
        $isSchool = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);

        $startDate = $this->startDate ?: now()->toDateString();
        $endDate   = $this->endDate   ?: $startDate;
        $status    = $this->status;
        $search    = $this->search;
        $grade     = $this->filterGrade ?? null;

        // ── School org: absent = students with NO scan on selected date ──
        // We fake an attendance-like query by querying employees directly
        // and returning their last attendance record (or a dummy placeholder).
        // Actually the cleanest approach: return attendance records for the
        // date range and let school absent be handled via a LEFT JOIN approach.
        if ($isSchool && $status === 'absent') {
            return $this->buildSchoolAbsentQuery($orgId, $startDate, $grade, $search);
        }

        // ── Inactive ─────────────────────────────────────────────────────
        if ($status === 'inactive') {
            $query = Attendance::query()
                ->select('attendances.*')
                ->with(['employee', 'employee.shift'])
                ->whereBetween('date', [$startDate, $endDate])
                ->whereHas('employee', function ($q) use ($orgId, $grade, $isSchool) {
                    $q->where('organization_id', $orgId)
                        ->where('active', 0)
                        ->where('is_student', $isSchool ? 1 : 0);
                    if ($grade) $q->where('grade', $grade);
                });

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('status', 'like', "%$search%")
                        ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%"));
                });
            }

            return $query->orderByDesc('date')
                ->orderByRaw('check_in_time IS NULL')
                ->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
        }

        // ── Normal active query ───────────────────────────────────────────
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereBetween('date', [$startDate, $endDate])
            ->whereHas('employee', function ($q) use ($orgId, $grade, $isSchool) {
                $q->where('organization_id', $orgId)
                    ->where('active', 1)
                    ->where('is_student', $isSchool ? 1 : 0);
                if ($grade) $q->where('grade', $grade);
            });

        if (!empty($status)) {
            match ($status) {
                'absent'  => $query->whereIn('status', ['absent', 'unchecked_in']),
                'present' => $query->whereIn('status', ['clocked_in', 'clocked_out']),
                default   => $query->where('status', $status),
            };
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%"));
            });
        }

        return $query->orderByDesc('date')
            ->orderByRaw('check_in_time IS NULL')
            ->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
    }

    /**
     * For school orgs, "absent" = students who have NO clocked_in or clocked_out
     * record on the selected date. We find those students and return their most
     * recent attendance record (any date) so the table row still has context,
     * OR return a minimal attendance stub if they've never been scanned.
     *
     * Strategy: query attendances using a subquery exclusion — much simpler
     * than a left join with Rappasoft's builder constraint.
     */
    private function buildSchoolAbsentQuery(
        ?int $orgId,
        string $date,
        ?string $grade,
        ?string $search
    ): \Illuminate\Database\Eloquent\Builder {

        // Step 1: find student IDs who DID scan on this date
        $scannedIds = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1))
            ->whereDate('date', $date)
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')
            ->unique()
            ->toArray();

        // Step 2: get unscanned students
        $unscannedQuery = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->whereNotIn('id', $scannedIds);

        if ($grade) $unscannedQuery->where('grade', $grade);
        if ($search) $unscannedQuery->where('name', 'like', "%$search%");

        $unscannedIds = $unscannedQuery->pluck('id')->toArray();

        // Step 3: return their most recent attendance record (any date),
        // filtered to one per employee using a subquery.
        // If they have no attendance at all, we need a fallback —
        // so we also seed a stub absent record for today for those employees.
        $this->seedAbsentRecordsForStudents($unscannedIds, $date, $orgId);

        // Step 4: now query today's absent records for those students
        return Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereIn('employee_id', $unscannedIds)
            ->whereDate('date', $date)
            ->whereHas('employee', function ($q) use ($orgId, $grade) {
                $q->where('organization_id', $orgId)
                    ->where('active', 1)
                    ->where('is_student', 1);
                if ($grade) $q->where('grade', $grade);
            })
            ->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));
    }

    /**
     * Seed a minimal absent record for students who have no record today.
     * This ensures the datatable has a row to display for each unscanned student.
     */
    private function seedAbsentRecordsForStudents(array $studentIds, string $date, ?int $orgId): void
    {
        if (empty($studentIds)) return;

        // Find which ones already have ANY record today
        $alreadyHaveRecord = Attendance::whereIn('employee_id', $studentIds)
            ->whereDate('date', $date)
            ->pluck('employee_id')
            ->unique()
            ->toArray();

        $needsRecord = array_diff($studentIds, $alreadyHaveRecord);

        foreach ($needsRecord as $studentId) {
            Attendance::firstOrCreate(
                ['employee_id' => $studentId, 'date' => $date],
                [
                    'status'         => 'absent',
                    'check_in_time'  => null,
                    'check_out_time' => null,
                    'organization_id'=> $orgId,
                ]
            );
        }
    }

    /**
     * Get the last known attendance record for an employee before the target date.
     */
    protected function getLastAttendance($employeeId, $targetDate)
    {
        if (!$employeeId) return null;

        return Attendance::where('employee_id', $employeeId)
            ->where('date', '<', $targetDate)
            ->where(function ($q) {
                $q->whereNotNull('check_in_time')
                    ->orWhereNotNull('check_out_time');
            })
            ->orderByDesc('date')
            ->first();
    }

    public function formatMinutes($minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) return $minutes . 'm';
        $hours = floor($minutes / 60);
        $mins  = $minutes % 60;
        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }

    public function columns(): array
    {
        $isSchool   = (bool)(auth()->user()->employee?->organization?->is_student_record ?? false);
        $targetDate = $this->startDate ?: now()->toDateString();

        return [

            Column::make($isSchool ? 'Student' : 'Employee')
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            Column::make($isSchool ? 'Grade' : 'Shift')
                ->label(function ($row) use ($isSchool) {
                    if ($isSchool) {
                        $grade = $row->employee->grade ?? null;
                        return $grade
                            ? "<strong>{$grade}</strong>"
                            : '<span class="text-muted">-</span>';
                    }
                    if (!$row->employee->shift) return '<span class="text-muted">-</span>';
                    $shift = $row->employee->shift;
                    $start = Carbon::parse($shift->start_time)->format('g:i A');
                    $end   = Carbon::parse($shift->end_time)->format('g:i A');
                    return "<strong>{$shift->name}</strong><br><small>{$start} - {$end}</small>";
                })
                ->html(),

            Column::make('Clock In', 'check_in_time')
                ->format(function ($value, $row) use ($targetDate, $isSchool) {
                    $label = '';
                    $badge = '';

                    // Last-record fallback: SCHOOL ORGS ONLY
                    if ($isSchool && in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last  = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_in_time;
                        if ($value) $label = "<br><small class='text-muted'>(Last Clock-In)</small>";
                    }

                    if (empty($value)) {
                        $formatted = "<span class='fw-semibold text-primary'>Didn't Clock In</span>";
                    } else {
                        $formatted = "<span class='fw-semibold text-success'>"
                            . Carbon::parse($value)->format('M d, Y g:i A')
                            . "</span>";

                        if (!$isSchool && $row->employee->shift) {
                            if ($row->is_late_checkin && $row->employee->shift->track_late_checkin) {
                                $badge = $row->within_grace_period
                                    ? "<br><span style='background:#ffc107;color:#000;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>⏰ {$this->formatMinutes($row->minutes_late)} Late (Grace)</span>"
                                    : "<br><span style='background:#dc3545;color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>🔴 {$this->formatMinutes($row->minutes_late)} Late</span>";
                            } elseif ($row->within_grace_period && $row->minutes_late > 0) {
                                $badge = "<span style='background:#17a2b8;color:#fff;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:500;'>✓ On time</span>";
                            }
                        }
                    }

                    return "{$formatted}{$badge}{$label}";
                })
                ->html(),

            Column::make('Clock Out', 'check_out_time')
                ->format(function ($value, $row) use ($targetDate, $isSchool) {
                    $label = '';
                    $badge = '';

                    // Last-record fallback: SCHOOL ORGS ONLY
                    if ($isSchool && in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last  = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_out_time;
                        if ($value) $label = "<br><small class='text-muted'>(Last Clock-Out)</small>";
                    }

                    if ($row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        return "<span style='background:green;color:#fff;padding:4px 12px;border-radius:4px;font-size:.75rem;'>Still In</span>";
                    }

                    $formatted = $row->check_out_time
                        ? "<span class='fw-semibold text-success'>" . Carbon::parse($row->check_out_time)->format('M d, Y g:i A') . "</span>"
                        : (empty($value)
                            ? "<span class='fw-semibold text-primary'>Didn't Clock Out</span>"
                            : "<span class='fw-semibold text-success'>" . Carbon::parse($value)->format('M d, Y g:i A') . "</span>");

                    if (!$isSchool && $row->employee->shift && $row->is_early_checkout && $row->employee->shift->track_early_checkout) {
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
        return Excel::download(
            new AttendanceDailyExcelExport(
                selectedIds: $this->getSelected(),
                startDate: $this->startDate,
                endDate: $this->endDate,
                status: $this->status
            ),
            'attendance.xlsx'
        );
    }

    #[On('export-pivot-daily-excel')]
    public function exportPivotExcel()
    {
        return Excel::download(
            new AttendancePivotDailyExcelExport(
                selectedIds: $this->getSelected(),
                startDate: $this->startDate,
                endDate: $this->endDate,
                status: $this->status
            ),
            'attendance.xlsx'
        );
    }

    #[On('export-daily-pdf')]
    public function exportPdf()
    {
        $url = route('attendance-daily.export.pdf', [
            'ids'        => $this->getSelected(),
            'start_date' => $this->startDate,
            'end_date'   => $this->endDate,
            'status'     => $this->status,
        ]);

        return redirect()->to($url);
    }
}
