<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceSeeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use App\Jobs\FetchZKBioTransactions;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;


new class extends Component {

    public $status;

    #[Url]
    public $filterStatus;

    #[Url]
    public $filterGrade;

    public $startDate;
    public $endDate;

    // Employee Type + Org hierarchy filters (Filter by Organization panel)
    public $filterEmployeeType = 'all';
    public $employeeTypes = [];
    public $unit_id = null;
    public $department_id = null;
    public $section_id = null;
    public $subsection_id = null;
    public $includeOutsourced = false;

    // Summary stats
    public $totalEmployees = 0;
    public $presentCount = 0;
    public $absentCount = 0;
    public $sickOffCount = 0;
    public $onLeaveCount = 0;
    public $offShiftCount = 0;
    public $inactiveCount = 0;

    // School-specific
    public bool $isSchoolOrg = false;
    public array $gradeStats = [];   // [['grade'=>'Grade 1','present'=>22,'total'=>26], ...]
    public array $recentActivity = [];  // last 10 attendance events today
    public array $availableGrades = [];
    public $leftSchoolCount = 0; // Add this property at the top of your class

    public bool $zkbioEnabled = false;
    public bool $syncing = false;

    public function mount()
    {
        $this->status = $this->filterStatus;
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;

        $org = auth()->user()->employee->organization;
        $this->isSchoolOrg = (bool)($org->is_student_record ?? false);
        $this->zkbioEnabled = $org->zkbio_enabled ?? false;

        $this->employeeTypes = Employee::where('organization_id', $org->id)
            ->whereNotNull('employee_type')
            ->distinct()
            ->orderBy('employee_type')
            ->pluck('employee_type')
            ->toArray();

        $this->loadSummaryStats();

        if ($this->isSchoolOrg) {
            $this->loadGradeStats();
            $this->loadRecentActivity();
            $this->loadAvailableGrades();
        }
    }

    public function units()
    {
        return \App\Models\Unit::where('organization_id', auth()->user()->employee->organization_id)
            ->orderBy('name')->get();
    }

    public function departmentsForUnit()
    {
        if (!$this->unit_id) return collect();
        return \App\Models\Department::where('unit_id', $this->unit_id)->orderBy('name')->get();
    }

    public function sectionsForDepartment()
    {
        if (!$this->department_id) return collect();
        return \App\Models\Section::where('department_id', $this->department_id)->orderBy('name')->get();
    }

    public function subsectionsForSection()
    {
        if (!$this->section_id) return collect();
        return \App\Models\Subsection::where('section_id', $this->section_id)->orderBy('name')->get();
    }

    public function prevDay(): void
    {
        $this->startDate = \Carbon\Carbon::parse($this->startDate)->subDay()->toDateString();
        $this->endDate = $this->startDate;
        $this->loadSummaryStats();
        $this->loadGradeStats();
        $this->loadRecentActivity();
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, status: $this->filterStatus, grade: $this->filterGrade,
            employee_type: $this->filterEmployeeType, unit_id: $this->unit_id, department_id: $this->department_id,
            section_id: $this->section_id, subsection_id: $this->subsection_id, include_outsourced: $this->includeOutsourced);
    }

    public function nextDay(): void
    {
        $this->startDate = \Carbon\Carbon::parse($this->startDate)->addDay()->toDateString();
        $this->endDate = $this->startDate;
        $this->loadSummaryStats();
        $this->loadGradeStats();
        $this->loadRecentActivity();
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, status: $this->filterStatus, grade: $this->filterGrade,
            employee_type: $this->filterEmployeeType, unit_id: $this->unit_id, department_id: $this->department_id,
            section_id: $this->section_id, subsection_id: $this->subsection_id, include_outsourced: $this->includeOutsourced);
    }

    public function refresh(): void
    {
        $this->loadSummaryStats();
        if ($this->isSchoolOrg) {
            $this->loadGradeStats();
            $this->loadRecentActivity();
        }
        $this->dispatch('date-range-updated',
            startDate: $this->startDate,
            endDate: $this->endDate,
            status: $this->filterStatus,
            grade: $this->filterGrade,
            employee_type: $this->filterEmployeeType,
            unit_id: $this->unit_id,
            department_id: $this->department_id,
            section_id: $this->section_id,
            subsection_id: $this->subsection_id,
            include_outsourced: $this->includeOutsourced,
        );
    }


    public function downloadAttendanceExcel(): mixed
    {
        $records = $this->getExportRecords();
        $rows = $this->buildSchoolExportRows($records);
        $filename = 'attendance_' . $this->startDate . '_to_' . $this->endDate . '.xlsx';

        $export = new class($rows) implements
            \Maatwebsite\Excel\Concerns\FromArray,
            \Maatwebsite\Excel\Concerns\ShouldAutoSize,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithTitle {

            public function __construct(private readonly array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }

            public function title(): string
            {
                return 'Attendance';
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastCol = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF0F172A']],
                    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);
                $sheet->getStyle('A1:' . $lastCol . $lastRow)
                    ->getAlignment()
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                $sheet->getDefaultRowDimension()->setRowHeight(20);
            }
        };

        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    }

    public function downloadAttendancePdf(): mixed
    {
        $records = $this->getExportRecords();
        $rows = $this->buildSchoolExportRows($records);
        $headers = array_shift($rows);

        $data = [
            'rows' => $rows,
            'headers' => $headers,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'filterStatus' => $this->filterStatus,
            'filterGrade' => $this->filterGrade,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.attendance.records', compact('data'))
            ->setPaper('a4', 'landscape');
        $filename = 'attendance_' . $this->startDate . '_to_' . $this->endDate . '.pdf';

        return response()->streamDownload(
            fn() => print($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Mirrors exactly what the attendance table view shows —
     * same date range, same filterStatus, same filterGrade.
     */
    private function getExportRecords(): \Illuminate\Support\Collection
    {
        $orgId = auth()->user()->employee?->organization_id;

        $studentIds = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->when($this->filterGrade, fn($q) => $q->where('grade', $this->filterGrade))
            ->pluck('id');

        return match ($this->filterStatus) {

            // On Campus — last attendance status is clocked_in
            'present' => Attendance::with('employee')
                ->whereIn('employee_id', $studentIds)
                ->whereDate('date', $this->startDate)
                ->where('status', 'clocked_in')
                ->orderBy('check_in_time')
                ->get(),

            // Off Campus — last attendance status is clocked_out
            'clocked_out' => Attendance::with('employee')
                ->whereIn('employee_id', $studentIds)
                ->whereDate('date', $this->startDate)
                ->where('status', 'clocked_out')
                ->orderBy('check_out_time')
                ->get(),

            // Unscanned — no record today at all
            'absent' => Employee::with(['lastAttendance'])
                ->whereIn('id', $studentIds)
                ->whereDoesntHave('attendances', fn($q) => $q->whereDate('date', $this->startDate)
                    ->whereIn('status', ['clocked_in', 'clocked_out'])
                )
                ->orderBy('grade')
                ->orderBy('name')
                ->get()
                ->map(fn($e) => (object)[
                    'employee' => $e,
                    'status' => 'absent',
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'date' => $this->startDate,
                ]),

            // No filter — everything for the date range
            default => Attendance::with('employee')
                ->whereIn('employee_id', $studentIds)
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->orderBy('date')
                ->orderBy('check_in_time')
                ->get(),
        };
    }

    private function buildSchoolExportRows(\Illuminate\Support\Collection $records): array
    {
        $statusLabel = [
            'clocked_in' => 'On Campus',
            'clocked_out' => 'Off Campus',
            'absent' => 'Unscanned',
        ];

        $rows = [['Student', 'Grade', 'Date', 'Status', 'Check In', 'Check Out']];

        foreach ($records as $a) {
            $emp = $a->employee;
            $rows[] = [
                $emp->name ?? '—',
                $emp->grade ?? '—',
                $a->date,
                $statusLabel[$a->status] ?? ucfirst(str_replace('_', ' ', $a->status)),
                $a->check_in_time ? \Carbon\Carbon::parse($a->check_in_time)->format('h:i A') : '—',
                $a->check_out_time ? \Carbon\Carbon::parse($a->check_out_time)->format('h:i A') : '—',
            ];
        }

        return $rows;
    }

    public function syncNow(): void
    {
        try {
            $org = auth()->user()->employee->organization;
            if (!$org->zkbio_enabled || !$org->zkbio_device_sn) return;

            $zkbio = app(\App\Services\ZKBioService::class);
            $startDate = now()->subDays(1)->format('Y-m-d H:i:s'); // manual sync looks back 1 day
            $endDate = now()->format('Y-m-d H:i:s');

            $deviceSns = array_filter(array_map('trim', explode(',', $org->zkbio_device_sn)));

            foreach ($deviceSns as $deviceSn) {

                $transactions = $zkbio->getTransactions(
                    deviceSn: $deviceSn,
                    pageNo: 1,
                    pageSize: 100,
                    startDate: $startDate,
                    endDate: $endDate,
                    baseUrl: $org->zkbio_base_url,
                    accessToken: $org->zkbio_access_token,
                );

                if (empty($transactions)) continue;

                // ── Filter to only real scan events ──
                $incomingLogIds = array_filter(array_column($transactions, 'logId'));
                $alreadyProcessed = \App\Models\ZkbioProcessedLog::whereIn('log_id', $incomingLogIds)
                    ->pluck('log_id')->toArray();

                $newTransactions = array_values(array_filter(
                    $transactions,
                    fn($tx) => !empty($tx['pin'])               // must have a person
                        && ($tx['logId'] ?? -1) !== -1   // skip disconnect events
                        && ($tx['eventNo'] ?? -1) === 0  // only Normal Verify Open
                        && !in_array($tx['logId'], $alreadyProcessed) // not already done
                ));

                if (empty($newTransactions)) continue;

                // Sort oldest → newest so toggle logic is correct
                usort($newTransactions, fn($a, $b) => strcmp($a['eventTime'], $b['eventTime']));

                // ── One job per person ──
                $grouped = collect($newTransactions)->groupBy('pin');

                foreach ($grouped as $pin => $pinTransactions) {
                    if (!$pin) continue;

                    try {
                        \App\Jobs\ProcessZKBioTransactions::dispatchSync(
                            $pinTransactions->values()->all(),
                            $org->id
                        );

                        // ✅ Only mark as processed AFTER job succeeds
                        \App\Models\ZkbioProcessedLog::insert(
                            $pinTransactions->map(fn($tx) => [
                                'log_id' => $tx['logId'],
                                'device_sn' => $deviceSn,
                                'pin' => $tx['pin'] ?? null,
                                'organization_id' => $org->id,
                                'processed_at' => now(),
                            ])->all()
                        );

                    } catch (\Throwable $e) {
                        // ❌ Job failed — NOT marked as processed so it retries next sync
                        \Illuminate\Support\Facades\Log::error('syncNow job failed for pin', [
                            'pin' => $pin,
                            'device' => $deviceSn,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $this->loadSummaryStats();
            if ($this->isSchoolOrg) {
                $this->loadGradeStats();
                $this->loadRecentActivity();
            }
            $this->dispatch('date-range-updated',
                startDate: $this->startDate,
                endDate: $this->endDate,
                status: $this->filterStatus,
                grade: $this->filterGrade,
                employee_type: $this->filterEmployeeType,
                unit_id: $this->unit_id,
                department_id: $this->department_id,
                section_id: $this->section_id,
                subsection_id: $this->subsection_id,
                include_outsourced: $this->includeOutsourced,
            );

            LivewireAlert::title('Synced!')
                ->text('Latest records pulled successfully.')
                ->success()->toast()->position('top-end')->show();

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Manual sync failed: ' . $e->getMessage());
            LivewireAlert::title('Sync Failed!')
                ->text($e->getMessage())
                ->error()->toast()->position('top-end')->show();
        }
    }


    public function loadSummaryStats(): void
    {
        $orgId = auth()->user()->employee?->organization_id;
        if (!$orgId) return;

        if ($this->isSchoolOrg) {
            $this->loadSchoolSummary($orgId);
        } else {
            $this->loadStaffSummary($orgId);
        }
    }

    public function syncToday(): void
    {
        try {
            $org = auth()->user()->employee->organization;
            if (!$org->zkbio_enabled) return;

            $startDate = now()->startOfDay()->format('Y-m-d H:i:s');
            $endDate   = now()->format('Y-m-d H:i:s');

            \Illuminate\Support\Facades\Artisan::call('zkbio:sync', [
                '--from' => $startDate,
                '--to'   => $endDate,
            ]);

            $this->loadSummaryStats();
            $this->dispatch('date-range-updated',
                startDate: $this->startDate,
                endDate: $this->endDate,
                status: $this->filterStatus,
                grade: $this->filterGrade,
                employee_type: $this->filterEmployeeType,
                unit_id: $this->unit_id,
                department_id: $this->department_id,
                section_id: $this->section_id,
                subsection_id: $this->subsection_id,
                include_outsourced: $this->includeOutsourced,
            );

            LivewireAlert::title('Synced!')
                ->text('Attendance synced from midnight to now.')
                ->success()->toast()->position('top-end')->show();

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Manual sync failed: ' . $e->getMessage());
            LivewireAlert::title('Sync Failed!')
                ->text($e->getMessage())
                ->error()->toast()->position('top-end')->show();
        }
    }

    private function loadSchoolSummary(int $orgId): void
    {
        $students = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->get();

        $this->totalEmployees = $students->count();
        $studentIds = $students->pluck('id');

        $attendances = Attendance::whereIn('employee_id', $studentIds)
            ->whereDate('date', $this->startDate)
            ->get();

        // On campus right now = last known status is clocked_in
        $this->presentCount = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->whereHas('lastAttendance', fn($q) => $q->where('status', 'clocked_in'))
            ->count();

        // Off campus = had a clocked_out record today
        $this->leftSchoolCount = $attendances
            ->where('status', 'clocked_out')
            ->pluck('employee_id')->unique()->count();

        // Unscanned = no clocked_in or clocked_out record today at all
        $scannedIds = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')->unique();

        $this->absentCount = max(0, $this->totalEmployees - $scannedIds->count());

        // Not used in school view but reset to avoid stale data
        $this->sickOffCount = 0;
        $this->onLeaveCount = 0;
        $this->offShiftCount = 0;
        $this->inactiveCount = Employee::where('organization_id', $orgId)
            ->where('active', 0)
            ->where('is_student', 1)
            ->count();
    }


    private function loadStaffSummary(int $orgId): void
    {
        // Refresh today's night-shift-aware statuses before counting — same
        // fix as the Dashboard's loadStaffStats().
        app(AttendanceSeeder::class)->seedIfDue($orgId, false);

        // All active NON-student employees
        $employees = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 0)
            ->get();

        $this->totalEmployees = $employees->count();
        $employeeIds = $employees->pluck('id');

        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$this->startDate, $this->endDate])
            ->get();

        $presentIds = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')->unique();

        $this->presentCount = $presentIds->count();
        $this->leftSchoolCount = 0; // not used for staff

        $this->sickOffCount = $attendances->where('status', 'sick_off')->pluck('employee_id')->unique()->count();
        $this->onLeaveCount = $attendances->where('status', 'on_leave')->pluck('employee_id')->unique()->count();
        $this->offShiftCount = $attendances->where('status', 'off_shift')->pluck('employee_id')->unique()->count();
        $this->inactiveCount = Employee::where('organization_id', $orgId)
            ->where('active', 0)
            ->where('is_student', 0)
            ->count();

        // Absent = not present AND no other status record
        $accountedForIds = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out', 'on_leave', 'sick_off', 'off_shift', 'on_break', 'not_scheduled'])
            ->pluck('employee_id')->unique();

        $this->absentCount = max(0, $this->totalEmployees - $accountedForIds->count());
    }


    public function loadAvailableGrades(): void
    {
        $orgId = auth()->user()->employee->organization_id;
        $this->availableGrades = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->whereNotNull('grade')
            ->distinct()
            ->orderBy('grade')
            ->pluck('grade')
            ->toArray();
    }

    public function loadGradeStats(): void
    {
        $orgId = auth()->user()->employee->organization_id;

        $students = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->whereNotNull('grade')
            ->with('lastAttendance')  // for current physical state
            ->get()
            ->groupBy('grade');

        $stats = [];

        foreach ($students as $grade => $gradeStudents) {
            $ids = $gradeStudents->pluck('id');

            // Currently on campus right now (lastAttendance state)
            $onCampusNow = $gradeStudents->filter(
                fn($s) => $s->lastAttendance?->status === 'clocked_in'
            )->count();

            // Scanned at any point on selected date (for the progress bar)
            $scannedToday = Attendance::whereIn('employee_id', $ids)
                ->whereDate('date', $this->startDate)
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->distinct('employee_id')
                ->count('employee_id');

            $total = $ids->count();
            $rate = $total > 0 ? round(($scannedToday / $total) * 100) : 0;

            $stats[] = [
                'grade' => $grade,
                'present' => $onCampusNow,   // currently inside
                'scannedToday' => $scannedToday,  // attended at any point today
                'total' => $total,
                'rate' => $rate,
                'color' => $rate >= 80 ? '#22c55e' : ($rate >= 60 ? '#f59e0b' : '#ef4444'),
            ];
        }

        $this->gradeStats = $stats;
    }

    public function loadRecentActivity(): void
    {
        $orgId = auth()->user()->employee->organization_id;
        $employeeIds = Employee::where('organization_id', $orgId)->pluck('id');

        $records = Attendance::with('employee')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $this->startDate)
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->whereNotNull('check_in_time')
            ->orderByDesc('check_in_time')
            ->limit(10)
            ->get();

        $this->recentActivity = $records->map(function ($a) {
            $emp = $a->employee;
            $isStaff = !$emp->is_student;
            $status = $a->status;

            $icon = match ($status) {
                'clocked_in' => ['icon' => 'mdi:check-circle', 'color' => '#22c55e', 'bg' => '#dcfce7'],
                'clocked_out' => ['icon' => 'mdi:exit-run', 'color' => '#ef4444', 'bg' => '#fee2e2'],
                'absent' => ['icon' => 'mdi:close-circle', 'color' => '#ef4444', 'bg' => '#fee2e2'],
                default => ['icon' => 'mdi:clock-outline', 'color' => '#f59e0b', 'bg' => '#fef9c3'],
            };

            $label = match ($status) {
                'clocked_in' => 'checked in',
                'clocked_out' => 'checked out',
                default => $status,
            };

            $sub = $isStaff
                ? 'Staff · ' . ($emp->employee_title ?? 'Staff')
                : ($emp->grade ?? '') . ($emp->employee_title ? ' · ' . $emp->employee_title : '');

            return [
                'name' => $emp->name,
                'sub' => trim($sub, ' · '),
                'label' => $label,
                'time' => $a->check_in_time ? \Carbon\Carbon::parse($a->check_in_time)->format('h:i A') : ($a->check_out_time ? \Carbon\Carbon::parse($a->check_out_time)->format('h:i A') : ''),
                'icon' => $icon,
            ];
        })->toArray();
    }

    #[On('filter-updated')]
    public function dateChanged($unit_id = null, $department_id = null, $section_id = null, $subsection_id = null, $includeOutsourced = null): void
    {
        if ($unit_id !== null) $this->unit_id = $unit_id;
        if ($department_id !== null) $this->department_id = $department_id;
        if ($section_id !== null) $this->section_id = $section_id;
        if ($subsection_id !== null) $this->subsection_id = $subsection_id;
        if ($includeOutsourced !== null) $this->includeOutsourced = $includeOutsourced;

        $this->loadSummaryStats();
        if ($this->isSchoolOrg) {
            $this->loadGradeStats();
            $this->loadRecentActivity();
        }
        $this->dispatch('date-range-updated',
            startDate: $this->startDate,
            endDate: $this->endDate,
            status: $this->filterStatus,
            grade: $this->filterGrade,
            employee_type: $this->filterEmployeeType,
            unit_id: $this->unit_id,
            department_id: $this->department_id,
            section_id: $this->section_id,
            subsection_id: $this->subsection_id,
            include_outsourced: $this->includeOutsourced,
        );
    }

}; ?>

@push('styles')
    <style>
        /* ── Shared summary card ── */
        .summary-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.4rem 1.5rem 1.2rem;
            height: 100%;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
        }

        .summary-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 0.9rem;
        }

        .summary-card-title {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.35rem;
        }

        .summary-card-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }

        .summary-card-value .of-total {
            font-size: 1.1rem;
            font-weight: 400;
            color: #94a3b8;
        }

        .summary-card-subtitle {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }

        .summary-card-bar {
            height: 4px;
            border-radius: 99px;
            background: #f1f5f9;
            margin-top: 0.85rem;
            overflow: hidden;
        }

        .summary-card-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.6s ease;
        }

        /* ── School panel cards ── */
        .school-panel {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            height: 100%;
        }

        .school-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .school-panel-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .school-panel-link {
            font-size: 0.8rem;
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }

        .school-panel-link:hover {
            text-decoration: underline;
        }

        /* Grade rows */
        .grade-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 0;
            border-bottom: 1px solid #f8fafc;
        }

        .grade-row:last-child {
            border-bottom: none;
        }

        .grade-label {
            width: 64px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #475569;
            flex-shrink: 0;
        }

        .grade-bar-wrap {
            flex: 1;
            height: 7px;
            background: #f1f5f9;
            border-radius: 99px;
            overflow: hidden;
        }

        .grade-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.5s ease;
        }

        .grade-count {
            font-size: 0.82rem;
            font-weight: 700;
            width: 52px;
            text-align: right;
            flex-shrink: 0;
        }

        /* Activity feed */
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid #f8fafc;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon-wrap {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 15px;
        }

        .activity-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
        }

        .activity-sub {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 1px;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-left: auto;
            flex-shrink: 0;
            padding-left: 8px;
            white-space: nowrap;
        }

        /* Filter bar */
        .attendance-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 1.25rem;
        }

        .attendance-filter-bar .form-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .attendance-filter-bar .form-control, .attendance-filter-bar .form-select {
            font-size: 0.85rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            height: 36px;
            padding: 0 10px;
        }

        /* Date nav (school) */
        .date-nav {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }

        .date-nav-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #475569;
            transition: all 0.15s;
        }

        .date-nav-btn:hover {
            background: #f1f5f9;
        }

        .date-nav-label {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e293b;
        }

        .date-nav-sub {
            font-size: 0.78rem;
            color: #94a3b8;
        }
    </style>
@endpush

<div class="row">
    <div class="col-12">

        @php
            $statusLabel = auth()->user()->employee?->organization?->is_student_record ? 'Student Logs' : 'Attendance';
            $breadcrumbItems = [
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>'],
                [
                    'label' => $statusLabel,
                    'url'   => Route::currentRouteName() === 'timesheets.index' ? route('timesheets.index') : route('attendance.index'),
                    'icon'  => Route::currentRouteName() === 'timesheets.index'
                        ? '<iconify-icon icon="mdi:calendar-clock" class="fs-5 text-primary"></iconify-icon>'
                        : '<iconify-icon icon="mdi:clipboard-text-check-outline" class="fs-5"></iconify-icon>',
                ],
            ];
        @endphp

        <livewire:admin.system-settings.bread-crumb :title="$statusLabel" :items="$breadcrumbItems"/>

        {{-- ============================================================
             SCHOOL ORG VIEW
        ============================================================ --}}
        @if($isSchoolOrg)

            {{-- Date nav --}}
            <div class="date-nav">
                <button class="date-nav-btn" wire:click="prevDay" title="Previous day">
                    <iconify-icon icon="mdi:chevron-left"></iconify-icon>
                </button>
                <div>
                    <div class="date-nav-label">{{ \Carbon\Carbon::parse($startDate)->format('l, d F Y') }}</div>
                    <div class="date-nav-sub">Week {{ \Carbon\Carbon::parse($startDate)->weekOfYear }}</div>
                </div>
                <button class="date-nav-btn" wire:click="nextDay" title="Next day">
                    <iconify-icon icon="mdi:chevron-right"></iconify-icon>
                </button>
            </div>

            {{-- 3 summary cards --}}
            <div class="row g-3 mb-4">
                {{-- Total Enrolled --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#ede9fe; color:#7c3aed;">
                            <iconify-icon icon="mdi:account-group"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Total Enrolled</p>
                        <div class="summary-card-value">{{ $totalEmployees }}</div>
                        <p class="summary-card-subtitle">Active students</p>
                    </div>
                </div>

                {{-- Present (In School) --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#dcfce7; color:#16a34a;">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                        <p class="summary-card-title">On Campus</p>
                        <div class="summary-card-value">
                            {{ $presentCount }}
                        </div>
                        <p class="summary-card-subtitle">Currently in school</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $totalEmployees > 0 ? ($presentCount/$totalEmployees)*100 : 0 }}%; background:#22c55e;"></div>
                        </div>
                    </div>
                </div>

                {{-- Left School --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#e0f2fe; color:#0284c7;">
                            <iconify-icon icon="mdi:exit-run"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Off Campus</p>
                        <div class="summary-card-value">
                            {{ $leftSchoolCount }}
                        </div>
                        <p class="summary-card-subtitle">Clocked out today</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $totalEmployees > 0 ? ($leftSchoolCount/$totalEmployees)*100 : 0 }}%; background:#0ea5e9;"></div>
                        </div>
                    </div>
                </div>

                {{-- Not Reported --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#fee2e2; color:#dc2626;">
                            <iconify-icon icon="mdi:account-alert"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Unscanned</p>
                        <div class="summary-card-value">
                            {{ $absentCount }}
                        </div>
                        <p class="summary-card-subtitle">No logs yet today</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $totalEmployees > 0 ? ($absentCount/$totalEmployees)*100 : 0 }}%; background:#ef4444;"></div>
                        </div>
                    </div>
                </div>
            </div>


            {{-- Grade breakdown + Recent Activity --}}
            <div class="row g-3 mb-4">

                {{-- Attendance by Grade --}}
                <div class="col-lg-7 col-12">
                    <div class="school-panel">
                        <div class="school-panel-header">
                            <h6 class="school-panel-title">Attendance by Grade</h6>
                            <a href="{{ route('attendance.index', ['filterGrade' => '']) }}" class="school-panel-link">View
                                All →</a>
                        </div>

                        {{-- Grade filter --}}
                        @if(count($availableGrades))
                            <div class="mb-3">
                                <select class="form-select form-select-sm" wire:model="filterGrade"
                                        wire:change="$dispatch('filter-updated')"
                                        style="width:160px; border-radius:8px; font-size:0.82rem;">
                                    <option value="">All Grades</option>
                                    @foreach($availableGrades as $g)
                                        <option value="{{ $g }}">{{ $g }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @forelse($gradeStats as $row)
                            @if(empty($filterGrade) || $row['grade'] === $filterGrade || $row['grade'] === 'Staff')
                                <div class="grade-row">
                                    <span class="grade-label">{{ $row['grade'] }}</span>
                                    <div class="grade-bar-wrap">
                                        <div class="grade-bar-fill"
                                             style="width:{{ $row['rate'] }}%; background:var(--primary-color) !important;"></div>
                                    </div>
                                    <span class="grade-count" style="color:var(--primary-color) !important;">
    {{ $row['present'] }} on campus · {{ $row['scannedToday'] }}/{{ $row['total'] }} today
</span>
                                </div>
                            @endif
                        @empty
                            <p class="text-muted small">No grade data available.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Recent Activity --}}
                <div class="col-lg-5 col-12">
                    <div class="school-panel">
                        <div class="school-panel-header">
                            <h6 class="school-panel-title">Recent Activity</h6>
                            <a href="{{ route('attendance.index') }}" class="school-panel-link">View All →</a>
                        </div>

                        @forelse($recentActivity as $item)
                            <div class="activity-item">
                                <div class="activity-icon-wrap"
                                     style="background:{{ $item['icon']['bg'] }}; color:{{ $item['icon']['color'] }};">
                                    <iconify-icon icon="{{ $item['icon']['icon'] }}"></iconify-icon>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div class="activity-name">{{ $item['name'] }} <span
                                            style="font-weight:400; color:#64748b;">{{ $item['label'] }}</span></div>
                                    <div class="activity-sub">{{ $item['sub'] }}</div>
                                </div>
                                <span class="activity-time">{{ $item['time'] }}</span>
                            </div>
                        @empty
                            <p class="text-muted small">No activity recorded today.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Attendance table with sync + filters --}}
            <div class="card card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <h6 class="mb-0 fw-bold" style="color:#1e293b;">Attendance Records</h6>
                    <div class="d-flex gap-2 align-items-center">

                        <div class="dropdown">
                            <button class="btn btn-sm dropdown-toggle d-flex align-items-center gap-2"
                                    type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                    style="height:36px; background-color:#072639; color:#fff; border-color:#072639;">
                                <iconify-icon icon="mdi:download" style="font-size:15px;"></iconify-icon>
                                Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:160px;">
                                <li>
                                    <button class="dropdown-item d-flex align-items-center gap-2"
                                            wire:click="downloadAttendanceExcel">
                                        <iconify-icon icon="mdi:microsoft-excel"
                                                      style="color:#16a34a;font-size:16px;"></iconify-icon>
                                        Export Excel
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item d-flex align-items-center gap-2"
                                            wire:click="downloadAttendancePdf">
                                        <iconify-icon icon="mdi:file-pdf-box"
                                                      style="color:#dc2626;font-size:16px;"></iconify-icon>
                                        Export PDF
                                    </button>
                                </li>
                            </ul>
                        </div>

                        @if($zkbioEnabled)
                            <button wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                                    class="btn btn-warning btn-sm d-flex align-items-center gap-2" style="height:36px;">
                <span wire:loading.class="d-none" wire:target="syncNow"
                      class="d-flex align-items-center gap-2">
                    <iconify-icon icon="mdi:refresh" style="font-size:15px;"></iconify-icon> Sync Now
                </span>
                                <span wire:loading.class.remove="d-none" wire:target="syncNow"
                                      class="d-none d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm"></span> Syncing...
                </span>
                            </button>
                        @endif

                    </div>
                </div>

                <div class="row align-items-end mb-4 mt-4">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" wire:model="startDate"
                               wire:change="$dispatch('filter-updated')"/>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" wire:model="endDate"
                               wire:change="$dispatch('filter-updated')"/>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Grade</label>
                        <select class="form-control" wire:model="filterGrade" wire:change="$dispatch('filter-updated')">
                            <option value="">All Grades</option>
                            @foreach($availableGrades as $g)
                                <option value="{{ $g }}">{{ $g }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Attendance Status</label>
                        <select class="form-control" wire:model="filterStatus"
                                wire:change="$dispatch('filter-updated')">
                            @if($isSchoolOrg)
                                {{-- Simplified School Filters --}}
                                <option value="clocked_in">On Campus [Clocked In]</option>
                                <option value="clocked_out">Off Campus [Clocked Out]</option>
                                <option value="absent">Not Enrolled</option>
                            @else
                                {{-- Standard Employee Filters --}}
                                <option value="present">Present [Clocked In + Clocked Out]</option>
                                <option value="clocked_in">Clocked In</option>
                                <option value="clocked_out">Clocked Out</option>
                                <option value="absent">Absent</option>
                                <option value="on_leave">On Leave</option>
                                <option value="off_shift">Off Shift</option>
                                <option value="sick_off">Sick Off</option>
                                <option value="on_break">On Break</option>
                            @endif
                        </select>
                    </div>
                </div>

                <livewire:attendance-daily-table :status="$status ?? null" theme="bootstrap-4"/>
            </div>

            {{-- ============================================================
                 NON-SCHOOL ORG VIEW  (unchanged from original)
            ============================================================ --}}
        @else

            {{-- Summary Stats --}}
            <div class="row g-3 mb-4">
                <div class="col-lg-6 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('attendance.index', ['filterStatus' => 'present']) }}" class="stat-card-link"
                           style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#dcfce7; color:#16a34a;">
                                <iconify-icon icon="mdi:account-check"></iconify-icon>
                            </div>
                            <p class="summary-card-title">Present Today</p>
                            <div class="summary-card-value">{{ $presentCount }} <span
                                    class="of-total">/ {{ $totalEmployees }}</span></div>
                            <p class="summary-card-subtitle">{{ $totalEmployees > 0 ? number_format(($presentCount/$totalEmployees)*100,1) : 0 }}
                                %</p>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('attendance.index', ['filterStatus' => 'absent']) }}" class="stat-card-link"
                           style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#fee2e2; color:#dc2626;">
                                <iconify-icon icon="mdi:account-remove"></iconify-icon>
                            </div>
                            <p class="summary-card-title">Absent Today</p>
                            <div class="summary-card-value">{{ $absentCount }} <span
                                    class="of-total">/ {{ $totalEmployees }}</span></div>
                            <p class="summary-card-subtitle">{{ $totalEmployees > 0 ? number_format(($absentCount/$totalEmployees)*100,1) : 0 }}
                                %</p>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('attendance.index', ['filterStatus' => 'sick_off']) }}" class="stat-card-link"
                           style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#dbeafe; color:#2563eb;">
                                <iconify-icon icon="mdi:medical-bag"></iconify-icon>
                            </div>
                            <p class="summary-card-title">Sick Off Today</p>
                            <div class="summary-card-value">{{ $sickOffCount }} <span
                                    class="of-total">/ {{ $totalEmployees }}</span></div>
                            <p class="summary-card-subtitle">{{ $totalEmployees > 0 ? number_format(($sickOffCount/$totalEmployees)*100,1) : 0 }}
                                %</p>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('attendance.index', ['filterStatus' => 'on_leave']) }}" class="stat-card-link"
                           style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#fef9c3; color:#d97706;">
                                <iconify-icon icon="mdi:airplane-takeoff"></iconify-icon>
                            </div>
                            <p class="summary-card-title">On Leave</p>
                            <div class="summary-card-value">{{ $onLeaveCount }} <span
                                    class="of-total">/ {{ $totalEmployees }}</span></div>
                            <p class="summary-card-subtitle">{{ $totalEmployees > 0 ? number_format(($onLeaveCount/$totalEmployees)*100,1) : 0 }}
                                %</p>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('attendance.index', ['filterStatus' => 'off_shift']) }}"
                           class="stat-card-link" style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#cffafe; color:#0891b2;">
                                <iconify-icon icon="mdi:clock-remove-outline"></iconify-icon>
                            </div>
                            <p class="summary-card-title">Off Shift</p>
                            <div class="summary-card-value">{{ $offShiftCount }} <span
                                    class="of-total">/ {{ $totalEmployees }}</span></div>
                            <p class="summary-card-subtitle">{{ $totalEmployees > 0 ? number_format(($offShiftCount/$totalEmployees)*100,1) : 0 }}
                                %</p>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <a href="{{ route('employees.index', ['active' => '0']) }}" class="stat-card-link"
                           style="text-decoration:none;">
                            <div class="summary-card-icon" style="background:#f1f5f9; color:#64748b;">
                                <iconify-icon icon="mdi:account-off"></iconify-icon>
                            </div>
                            <p class="summary-card-title">Inactive</p>
                            <div class="summary-card-value">{{ $inactiveCount }}</div>
                            <p class="summary-card-subtitle">Deactivated accounts excluded from headcount</p>
                        </a>
                    </div>
                </div>
            </div>

            <div class="card card-body">
                <div class="row align-items-end mb-4 justify-content-end">
                    <div class="col-12 d-flex align-items-center justify-content-end gap-2 flex-wrap">
                        @if($zkbioEnabled)
                            <button wire:click="syncToday"
                                    wire:loading.attr="disabled"
                                    wire:target="syncToday"
                                    type="button"
                                    class="btn d-flex align-items-center gap-2"
                                    style="height:38px; background:#fff; border:1.5px solid #f59e0b; color:#b45309; font-weight:600; border-radius:8px; font-size:0.82rem; padding:6px 16px;">
        <span wire:loading.class="d-none" wire:target="syncToday"
              class="d-flex align-items-center gap-2">
            <iconify-icon icon="mdi:database-sync-outline" style="font-size:17px;"></iconify-icon>
            Sync Today
        </span>
                                <span wire:loading.class.remove="d-none" wire:target="syncToday"
                                      class="d-none d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-sm"></span> Syncing...
        </span>
                            </button>
                        @endif
                        @if (str_contains($filterStatus ?? '', 'sick_off'))
                            <a href="{{ route('leaves.create') }}" class="btn btn-primary btn-sm"
                               style="height:38px;display:flex;align-items:center;">+ Create Sick Off</a>
                        @elseif (str_contains($filterStatus ?? '', 'off_shift'))
                            <a href="{{ route('leaves.create') }}" class="btn btn-primary btn-sm"
                               style="height:38px;display:flex;align-items:center;">+ Create Off Shifts</a>
                        @elseif (str_contains($filterStatus ?? '', 'on_leave'))
                            <a href="{{ route('leaves.create') }}" class="btn btn-primary btn-sm"
                               style="height:38px;display:flex;align-items:center;">+ Create Leave Request</a>
                        @endif
                    </div>
                </div>

                <div class="row align-items-end mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" wire:model="startDate"
                               wire:change="$dispatch('filter-updated')"/>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" wire:model="endDate"
                               wire:change="$dispatch('filter-updated')"/>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Attendance Status</label>
                        <select class="form-control" wire:model="filterStatus"
                                wire:change="$dispatch('filter-updated')">
                            <option value="">All</option>
                            <option value="present">Present [Clocked In + Clocked Out]</option>
                            <option value="clocked_in">Clocked In</option>
                            <option value="clocked_out">Clocked Out</option>
                            <option value="absent">Absent</option>
                            <option value="on_leave">On Leave</option>
                            <option value="off_shift">Off Shift</option>
                            <option value="sick_off">Sick Off</option>
                            <option value="on_break">On Break</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employee Type</label>
                        <select class="form-control" wire:model="filterEmployeeType"
                                wire:change="$dispatch('filter-updated')">
                            <option value="all">All Types</option>
                            @foreach($employeeTypes as $type)
                                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="border rounded-3 bg-light-subtle p-3 mb-4">
                    <div class="fw-bold text-uppercase small text-muted mb-3" style="letter-spacing:.04em;">
                        <i class="ti ti-sitemap me-1"></i>Filter by Organization
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Unit</label>
                            <select class="form-control" wire:model="unit_id" wire:change="$dispatch('filter-updated')">
                                <option value="">All Units</option>
                                @foreach ($this->units() as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Department</label>
                            <select class="form-control" wire:model="department_id" wire:change="$dispatch('filter-updated')" @disabled(!$unit_id)>
                                <option value="">{{ $unit_id ? 'All Departments' : 'Select a Unit first' }}</option>
                                @foreach ($this->departmentsForUnit() as $d)
                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Section</label>
                            <select class="form-control" wire:model="section_id" wire:change="$dispatch('filter-updated')" @disabled(!$department_id)>
                                <option value="">{{ $department_id ? 'All Sections' : '—' }}</option>
                                @foreach ($this->sectionsForDepartment() as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Subsection</label>
                            <select class="form-control" wire:model="subsection_id" wire:change="$dispatch('filter-updated')" @disabled(!$section_id)>
                                <option value="">{{ $section_id ? 'All Subsections' : '—' }}</option>
                                @foreach ($this->subsectionsForSection() as $sub)
                                    <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 border border-dashed rounded-2 px-3 py-2 mt-3" style="background:rgba(0,0,0,.015);">
                        <input type="checkbox" class="form-check-input mt-0 flex-shrink-0" id="attendanceInclOut" wire:model="includeOutsourced" wire:change="$dispatch('filter-updated')">
                        <label class="form-check-label small mb-0" for="attendanceInclOut">
                            <span class="fw-semibold">Include Outsourced staff</span>
                            <span class="text-muted d-block d-md-inline"> — excluded by default since they don't sit under Unit/Department/Section.</span>
                        </label>
                    </div>
                </div>

                <livewire:attendance-daily-table :status="$status ?? null" theme="bootstrap-4"/>
            </div>

        @endif

    </div>
</div>


