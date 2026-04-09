<?php

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {

    public string  $search           = '';
    public ?string $activePreview    = null;
    public string  $dailyDate        = '';
    public string  $weeklyStartDate  = '';
    public string  $monthlyDate      = '';
    public string  $customStartDate  = '';
    public string  $customEndDate    = '';
    public string  $chronicThreshold = '75';

    // Student status report filters
    public string $statusGradeFilter  = '';
    public string $statusFilter       = '';   // '' | 'present' | 'left' | 'not_reported'

    public array $previewData = [];

    public function mount(): void
    {
        $this->dailyDate       = now()->toDateString();
        $this->weeklyStartDate = now()->startOfWeek()->toDateString();
        $this->monthlyDate     = now()->format('Y-m');
        $this->customStartDate = now()->startOfMonth()->toDateString();
        $this->customEndDate   = now()->toDateString();
    }

    /* ─────────────────────────────────────────────
       HELPERS
    ───────────────────────────────────────────── */
    private function orgId(): ?int
    {
        return auth()->user()->employee->organization_id ?? null;
    }

    private function studentQuery()
    {
        return Employee::where('organization_id', $this->orgId())
            ->where('active', 1)
            ->where('is_student', 1);
    }

    private function summarise(\Illuminate\Support\Collection $atts, \Illuminate\Support\Collection $ids): array
    {
        $inSchoolIds   = $atts->where('status', 'clocked_in')->pluck('employee_id')->unique();
        $leftSchoolIds = $atts->where('status', 'clocked_out')
            ->pluck('employee_id')
            ->unique()
            ->reject(fn($id) => $inSchoolIds->contains($id));

        $shownUpIds = $inSchoolIds->merge($leftSchoolIds)->unique();
        $total      = $ids->count();
        $shownUp    = $shownUpIds->count();

        return [
            'present'  => $inSchoolIds->count(),
            'departed' => $leftSchoolIds->count(),
            'notIn'    => max(0, $total - $shownUp),
            'total'    => $total,
            'rate'     => $total > 0 ? round(($shownUp / $total) * 100) : 0,
        ];
    }

    private function summariseRange(
        \Illuminate\Support\Collection $atts,
        \Illuminate\Support\Collection $students,
        int $totalDays
    ): array {
        $enrolled    = $students->count();
        $byEmployee  = $atts->groupBy('employee_id');
        $presentDays = 0;
        $leftDays    = 0;

        foreach ($students as $student) {
            $byDate = $byEmployee->get($student->id, collect())->groupBy('date');
            foreach ($byDate as $dayAtts) {
                if ($dayAtts->where('status', 'clocked_out')->isNotEmpty()) {
                    $leftDays++;
                } elseif ($dayAtts->where('status', 'clocked_in')->isNotEmpty()) {
                    $presentDays++;
                }
            }
        }

        $shownUp     = $presentDays + $leftDays;
        $maxPossible = max(1, $enrolled * $totalDays);

        return [
            'present'  => $presentDays,
            'departed' => $leftDays,
            'notIn'    => max(0, $maxPossible - $shownUp),
            'total'    => $enrolled,
            'rate'     => round(($shownUp / $maxPossible) * 100),
        ];
    }

    private function countSchoolDays(Carbon $start, Carbon $end): int
    {
        $days = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (!$d->isWeekend()) $days++;
        }
        return max(1, $days);
    }

    /* ─────────────────────────────────────────────
       PREVIEW TOGGLE
    ───────────────────────────────────────────── */
    public function preview(string $type): void
    {
        $this->activePreview = ($this->activePreview === $type) ? null : $type;
        $this->previewData   = $this->activePreview
            ? match ($type) {
                'daily'          => $this->buildDailyData(),
                'weekly'         => $this->buildWeeklyData(),
                'monthly'        => $this->buildMonthlyData(),
                'custom'         => $this->buildCustomData(),
                'chronic'        => $this->buildChronicData(),
                'student_status' => $this->buildStudentStatusData(),
                default          => [],
            }
            : [];
    }

    // Re-run preview when filters change (called from blade via wire:change)
    public function refreshStudentStatus(): void
    {
        if ($this->activePreview === 'student_status') {
            $this->previewData = $this->buildStudentStatusData();
        }
    }

    /* ── DAILY ──────────────────────────────────── */
    private function buildDailyData(): array
    {
        $date     = $this->dailyDate;
        $students = $this->studentQuery()->whereNotNull('grade')->get()->groupBy('grade');
        $rows     = [];

        foreach ($students as $grade => $gradeStudents) {
            $ids    = $gradeStudents->pluck('id');
            $atts   = Attendance::whereIn('employee_id', $ids)->whereDate('date', $date)->get();
            $rows[] = array_merge(['grade' => $grade], $this->summarise($atts, $ids));
        }

        $totals = [
            'present'  => array_sum(array_column($rows, 'present')),
            'departed' => array_sum(array_column($rows, 'departed')),
            'notIn'    => array_sum(array_column($rows, 'notIn')),
            'total'    => array_sum(array_column($rows, 'total')),
        ];
        $totals['rate'] = $totals['total'] > 0
            ? round((($totals['present'] + $totals['departed']) / $totals['total']) * 100)
            : 0;

        return ['date' => $date, 'rows' => $rows, 'totals' => $totals];
    }

    /* ── WEEKLY ─────────────────────────────────── */
    private function buildWeeklyData(): array
    {
        $start    = Carbon::parse($this->weeklyStartDate)->startOfWeek();
        $end      = $start->copy()->endOfWeek();
        $students = $this->studentQuery()->get();
        $ids      = $students->pluck('id');

        $atts = Attendance::whereIn('employee_id', $ids)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $rows = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dayStr  = $d->toDateString();
            $dayAtts = $atts->where('date', $dayStr);
            $s       = $this->summarise($dayAtts, $ids);
            $rows[]  = array_merge($s, [
                'day'  => $d->format('D'),
                'date' => $d->format('d M'),
            ]);
        }

        return [
            'weekLabel'     => $start->format('d M') . ' – ' . $end->format('d M Y'),
            'rows'          => $rows,
            'totalStudents' => $students->count(),
        ];
    }

    /* ── MONTHLY ────────────────────────────────── */
    private function buildMonthlyData(): array
    {
        $month     = Carbon::parse($this->monthlyDate . '-01');
        $start     = $month->copy()->startOfMonth();
        $end       = $month->copy()->endOfMonth();
        $totalDays = $this->countSchoolDays($start, $end);
        $byGrade   = $this->studentQuery()->whereNotNull('grade')->get()->groupBy('grade');
        $rows      = [];

        foreach ($byGrade as $grade => $gradeStudents) {
            $ids    = $gradeStudents->pluck('id');
            $atts   = Attendance::whereIn('employee_id', $ids)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get();
            $rows[] = array_merge(['grade' => $grade], $this->summariseRange($atts, $gradeStudents, $totalDays));
        }

        $totals      = [
            'present'  => array_sum(array_column($rows, 'present')),
            'departed' => array_sum(array_column($rows, 'departed')),
            'notIn'    => array_sum(array_column($rows, 'notIn')),
            'total'    => array_sum(array_column($rows, 'total')),
        ];
        $maxPossible    = max(1, $totals['total'] * $totalDays);
        $totals['rate'] = round((($totals['present'] + $totals['departed']) / $maxPossible) * 100);

        return ['monthLabel' => $month->format('F Y'), 'totalDays' => $totalDays, 'rows' => $rows, 'totals' => $totals];
    }

    /* ── CUSTOM DATE RANGE ──────────────────────── */
    private function buildCustomData(): array
    {
        $start     = Carbon::parse($this->customStartDate);
        $end       = Carbon::parse($this->customEndDate);
        $totalDays = $this->countSchoolDays($start, $end);
        $byGrade   = $this->studentQuery()->whereNotNull('grade')->get()->groupBy('grade');
        $rows      = [];

        foreach ($byGrade as $grade => $gradeStudents) {
            $ids    = $gradeStudents->pluck('id');
            $atts   = Attendance::whereIn('employee_id', $ids)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get();
            $rows[] = array_merge(['grade' => $grade], $this->summariseRange($atts, $gradeStudents, $totalDays));
        }

        $totals      = [
            'present'  => array_sum(array_column($rows, 'present')),
            'departed' => array_sum(array_column($rows, 'departed')),
            'notIn'    => array_sum(array_column($rows, 'notIn')),
            'total'    => array_sum(array_column($rows, 'total')),
        ];
        $maxPossible    = max(1, $totals['total'] * $totalDays);
        $totals['rate'] = round((($totals['present'] + $totals['departed']) / $maxPossible) * 100);

        return ['rangeLabel' => $start->format('d M Y') . ' – ' . $end->format('d M Y'), 'totalDays' => $totalDays, 'rows' => $rows, 'totals' => $totals];
    }

    /* ── LOW PRESENCE ───────────────────────────── */
    private function buildChronicData(): array
    {
        $threshold = (int) $this->chronicThreshold;
        $termStart = now()->startOfMonth()->toDateString();
        $termEnd   = now()->toDateString();
        $totalDays = $this->countSchoolDays(Carbon::parse($termStart), Carbon::parse($termEnd));

        $students = $this->studentQuery()->get();
        $ids      = $students->pluck('id');
        $atts     = Attendance::whereIn('employee_id', $ids)
            ->whereBetween('date', [$termStart, $termEnd])
            ->get()
            ->groupBy('employee_id');

        $flagged = [];
        foreach ($students as $student) {
            $byDate  = $atts->get($student->id, collect())->groupBy('date');
            $present = $left = 0;

            foreach ($byDate as $dayAtts) {
                if ($dayAtts->where('status', 'clocked_out')->isNotEmpty()) {
                    $left++;
                } elseif ($dayAtts->where('status', 'clocked_in')->isNotEmpty()) {
                    $present++;
                }
            }

            $shownUp = $present + $left;
            $rate    = round(($shownUp / $totalDays) * 100);

            if ($rate < $threshold) {
                $flagged[] = [
                    'name'     => $student->name,
                    'grade'    => $student->grade ?? '—',
                    'present'  => $present,
                    'departed' => $left,
                    'notIn'    => max(0, $totalDays - $shownUp),
                    'rate'     => $rate,
                ];
            }
        }

        usort($flagged, fn($a, $b) => $a['rate'] <=> $b['rate']);

        return [
            'threshold' => $threshold,
            'termStart' => $termStart,
            'termEnd'   => $termEnd,
            'totalDays' => $totalDays,
            'total'     => count($flagged),
            'rows'      => $flagged,
        ];
    }

    /* ── STUDENT STATUS LIST ─────────────────────
     |
     |  Shows every pembroke with their CURRENT status
     |  based on their LAST attendance record regardless
     |  of date. A pembroke who checked in 2 weeks ago
     |  and never checked out is still "Present".
     |
     |  present      = last record status is clocked_in
     |  left         = last record status is clocked_out
     |  not_reported = no attendance record at all
     ─────────────────────────────────────────── */
    private function buildStudentStatusData(): array
    {
        $query = $this->studentQuery()->with(['lastAttendance']);

        if ($this->statusGradeFilter !== '') {
            $query->where('grade', $this->statusGradeFilter);
        }

        $students = $query->orderBy('grade')->orderBy('name')->get();

        $rows = [];
        foreach ($students as $student) {
            $last   = $student->lastAttendance;
            $status = 'not_reported';
            $time   = null;
            $date   = null;

            if ($last) {
                if ($last->status === 'clocked_in') {
                    $status = 'present';
                    $time   = $last->check_in_time
                        ? Carbon::parse($last->check_in_time)->format('g:i A')
                        : null;
                    $date = Carbon::parse($last->date)->format('d M Y');
                } elseif ($last->status === 'clocked_out') {
                    $status = 'left';
                    $time   = $last->check_out_time
                        ? Carbon::parse($last->check_out_time)->format('g:i A')
                        : null;
                    $date = Carbon::parse($last->date)->format('d M Y');
                }
            }

            $row = [
                'name'   => $student->name,
                'grade'  => $student->grade ?? '—',
                'status' => $status,
                'time'   => $time,
                'date'   => $date,
            ];

            // Apply status filter
            if ($this->statusFilter === '' || $this->statusFilter === $status) {
                $rows[] = $row;
            }
        }

        // Available grades for filter dropdown
        $grades = $this->studentQuery()
            ->whereNotNull('grade')
            ->distinct()
            ->orderBy('grade')
            ->pluck('grade')
            ->toArray();

        // Summary counts across ALL students (unfiltered by status)
        $all         = collect($rows);
        $presentCount  = collect($rows)->where('status', 'present')->count();
        $leftCount     = collect($rows)->where('status', 'left')->count();
        $notRepCount   = collect($rows)->where('status', 'not_reported')->count();

        // Recalculate totals from full unfiltered list for the summary cards
        $allStudents = $this->studentQuery()
            ->when($this->statusGradeFilter !== '', fn($q) => $q->where('grade', $this->statusGradeFilter))
            ->with('lastAttendance')
            ->get();

        $totalPresent     = 0;
        $totalLeft        = 0;
        $totalNotReported = 0;

        foreach ($allStudents as $s) {
            $l = $s->lastAttendance;
            if (!$l || !in_array($l->status, ['clocked_in', 'clocked_out'])) {
                $totalNotReported++;
            } elseif ($l->status === 'clocked_in') {
                $totalPresent++;
            } else {
                $totalLeft++;
            }
        }

        return [
            'rows'             => $rows,
            'grades'           => $grades,
            'totalPresent'     => $totalPresent,
            'totalLeft'        => $totalLeft,
            'totalNotReported' => $totalNotReported,
            'totalEnrolled'    => $allStudents->count(),
        ];
    }

    /* ─────────────────────────────────────────────
       EXPORT ROWS
    ───────────────────────────────────────────── */
    private function buildExportRows(string $type, array $data): array
    {
        return match ($type) {
            'daily' => array_merge(
                [['Grade', 'In School', 'Left School', 'Not Reported', 'Enrolled', 'Rate %']],
                array_map(fn($r) => [$r['grade'], $r['present'], $r['departed'], $r['notIn'], $r['total'], $r['rate']], $data['rows']),
                [['TOTAL', $data['totals']['present'], $data['totals']['departed'], $data['totals']['notIn'], $data['totals']['total'], $data['totals']['rate']]]
            ),
            'weekly' => array_merge(
                [['Day', 'Date', 'In School', 'Left School', 'Not Reported', 'Total Enrolled', 'Rate %']],
                array_map(fn($r) => [$r['day'], $r['date'], $r['present'], $r['departed'], $r['notIn'], $r['total'], $r['rate']], $data['rows'])
            ),
            'monthly', 'custom' => array_merge(
                [['Grade', 'Days In School', 'Days Left School', 'Days Not Reported', 'Enrolled', 'School Days', 'Rate %']],
                array_map(fn($r) => [$r['grade'], $r['present'], $r['departed'], $r['notIn'], $r['total'], $data['totalDays'], $r['rate']], $data['rows']),
                [['TOTAL', $data['totals']['present'], $data['totals']['departed'], $data['totals']['notIn'], $data['totals']['total'], $data['totalDays'], $data['totals']['rate']]]
            ),
            'chronic' => array_merge(
                [['Student', 'Grade', 'Days In School', 'Days Left School', 'Days Not Reported', 'Rate %']],
                array_map(fn($r) => [$r['name'], $r['grade'], $r['present'], $r['departed'], $r['notIn'], $r['rate']], $data['rows'])
            ),
            'student_status' => array_merge(
                [['Student', 'Grade', 'Status', 'Time', 'Date of Last Record']],
                array_map(function ($r) {
                    $statusLabel = match($r['status']) {
                        'present'      => 'Present (In School)',
                        'left'         => 'Left School',
                        'not_reported' => 'Not Reported',
                        default        => $r['status'],
                    };
                    return [
                        $r['name'],
                        $r['grade'],
                        $statusLabel,
                        $r['time'] ?? '—',
                        $r['date'] ?? '—',
                    ];
                }, $data['rows'])
            ),
            default => [[]],
        };
    }

    /* ─────────────────────────────────────────────
       DOWNLOADS
    ───────────────────────────────────────────── */
    public function downloadCsv(string $type): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data     = $this->resolveData($type);
        $rows     = $this->buildExportRows($type, $data);
        $filename = $type . '_report_' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function downloadExcel(string $type): mixed
    {
        $data     = $this->resolveData($type);
        $rows     = $this->buildExportRows($type, $data);
        $filename = $type . '_report_' . now()->format('Ymd') . '.xlsx';

        $export = new class($rows) implements
            \Maatwebsite\Excel\Concerns\FromArray,
            \Maatwebsite\Excel\Concerns\ShouldAutoSize,
            \Maatwebsite\Excel\Concerns\WithStyles,
            \Maatwebsite\Excel\Concerns\WithTitle
        {
            public function __construct(private readonly array $rows) {}
            public function array(): array { return $this->rows; }
            public function title(): string { return 'Report'; }
            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastCol = $sheet->getHighestColumn();
                $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F172A']],
                    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);
                $lastRow = $sheet->getHighestRow();
                if ($sheet->getCell('A' . $lastRow)->getValue() === 'TOTAL') {
                    $sheet->getStyle('A' . $lastRow . ':' . $lastCol . $lastRow)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']],
                    ]);
                }
                $sheet->getStyle('A1:' . $lastCol . $lastRow)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                $sheet->getDefaultRowDimension()->setRowHeight(20);
            }
        };

        return Excel::download($export, $filename);
    }

    public function downloadPdf(string $type): mixed
    {
        $data     = $this->resolveData($type);
        $pdf      = \Barryvdh\DomPDF\Facade\Pdf::loadView("exports.pembroke.{$type}", compact('data'));
        $filename = $type . '_report_' . now()->format('Ymd') . '.pdf';

        return response()->streamDownload(
            fn() => print($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    private function resolveData(string $type): array
    {
        return match ($type) {
            'daily'          => $this->buildDailyData(),
            'weekly'         => $this->buildWeeklyData(),
            'monthly'        => $this->buildMonthlyData(),
            'custom'         => $this->buildCustomData(),
            'chronic'        => $this->buildChronicData(),
            'student_status' => $this->buildStudentStatusData(),
            default          => [],
        };
    }

    public function reports(): array
    {
        $all = [
            [
                'key'         => 'student_status',
                'title'       => 'Current Student Status',
                'description' => 'Live view of every student — who is Present, who has Left School, and who has Not Reported. Based on last attendance record regardless of date.',
                'icon'        => 'person-check',
                'iconBg'      => '#dcfce7',
                'iconColor'   => '#16a34a',
                'tag'         => 'Live',
                'tagStyle'    => 'background:#dcfce7; color:#16a34a;',
            ],
            [
                'key'         => 'daily',
                'title'       => 'Daily Check-in Summary',
                'description' => 'See who is in school, who has left, and who hasn\'t arrived — for any selected day.',
                'icon'        => 'calendar',
                'iconBg'      => '#e0f2fe',
                'iconColor'   => '#0284c7',
                'tag'         => 'Check-in',
                'tagStyle'    => 'background:#ede9fe; color:#6d28d9;',
            ],
            [
                'key'         => 'weekly',
                'title'       => 'Weekly Overview',
                'description' => 'Day-by-day check-in trends across a selected school week.',
                'icon'        => 'graph-up',
                'iconBg'      => '#e0f2fe',
                'iconColor'   => '#0284c7',
                'tag'         => 'Check-in',
                'tagStyle'    => 'background:#ede9fe; color:#6d28d9;',
            ],
            [
                'key'         => 'monthly',
                'title'       => 'Monthly Summary',
                'description' => 'Aggregated check-in data by grade for a full calendar month.',
                'icon'        => 'calendar',
                'iconBg'      => '#f3e8ff',
                'iconColor'   => '#9333ea',
                'tag'         => 'Check-in',
                'tagStyle'    => 'background:#ede9fe; color:#6d28d9;',
            ],
            [
                'key'         => 'custom',
                'title'       => 'Custom Date Range',
                'description' => 'Check-in summary by grade for any date range you choose.',
                'icon'        => 'sliders',
                'iconBg'      => '#fff7ed',
                'iconColor'   => '#ea580c',
                'tag'         => 'Check-in',
                'tagStyle'    => 'background:#ede9fe; color:#6d28d9;',
            ]
        ];

        if ($this->search) {
            $q   = strtolower($this->search);
            $all = array_values(array_filter($all, fn($r) =>
                str_contains(strtolower($r['title']), $q) ||
                str_contains(strtolower($r['description']), $q)
            ));
        }

        return $all;
    }
};
?>

@push('styles')
    <style>
        .rp-page { font-family: 'DM Sans', 'Nunito', sans-serif; background: #f0f4f8; min-height: 100vh; padding: 1.5rem; }
        .rp-heading { font-size: 1.75rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.2rem; }
        .rp-sub { font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; }

        .rp-search-wrap { position: relative; max-width: 340px; margin-bottom: 2rem; }
        .rp-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .rp-search { width: 100%; padding: 9px 14px 9px 38px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 0.875rem; background: #fff; outline: none; color: #0f172a; transition: border 0.2s; }
        .rp-search:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.08); }

        .rp-section-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 0.75rem; }

        .rp-card { background: #fff; border: 1.5px solid #e8edf2; border-radius: 14px; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; transition: box-shadow 0.2s, border-color 0.2s; margin-bottom: 0.6rem; flex-wrap: wrap; }
        .rp-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.06); }
        .rp-card.active { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        .rp-icon-wrap { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .rp-card-body { flex: 1; min-width: 180px; }
        .rp-card-title { font-size: 0.95rem; font-weight: 700; color: #0f172a; margin: 0 0 2px; }
        .rp-card-desc { font-size: 0.8rem; color: #64748b; margin: 0; }
        .rp-tag { font-size: 0.7rem; font-weight: 600; padding: 3px 9px; border-radius: 99px; white-space: nowrap; flex-shrink: 0; }
        .rp-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; flex-wrap: wrap; }

        .btn-preview { font-size: 0.8rem; font-weight: 600; padding: 6px 14px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #fff; color: #334155; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
        .btn-preview:hover, .btn-preview.active { background: #f1f5f9; border-color: #94a3b8; }
        .btn-pdf { font-size: 0.8rem; font-weight: 700; padding: 6px 14px; border-radius: 8px; border: none; background: #0f172a; color: #fff; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; white-space: nowrap; }
        .btn-pdf:hover { background: #1e293b; }
        .btn-excel { font-size: 0.8rem; font-weight: 700; padding: 6px 14px; border-radius: 8px; border: none; background: #16a34a; color: #fff; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; white-space: nowrap; }
        .btn-excel:hover { background: #15803d; }
        .btn-csv { font-size: 0.8rem; font-weight: 700; padding: 6px 14px; border-radius: 8px; border: 1.5px solid #94a3b8; background: transparent; color: #475569; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; white-space: nowrap; }
        .btn-csv:hover { background: #f1f5f9; }

        .rp-preview { background: #fff; border: 1.5px solid #e8edf2; border-radius: 14px; padding: 1.5rem; margin-bottom: 0.6rem; animation: rpSlide 0.18s ease; }
        @keyframes rpSlide { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
        .rp-preview-title { font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0 0 2px; }
        .rp-preview-meta  { font-size: 0.8rem; color: #94a3b8; margin: 0 0 1.25rem; }

        .rp-filter-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 1.25rem; align-items: flex-end; }
        .rp-filter-group { display: flex; flex-direction: column; }
        .rp-filter-row label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 6px; }
        .rp-filter-row input, .rp-filter-row select { font-size: 0.9rem; border: 1.5px solid #e2e8f0; border-radius: 9px; padding: 10px 14px; background: #fff; outline: none; color: #0f172a; min-width: 160px; transition: border 0.2s, box-shadow 0.2s; }
        .rp-filter-row input:focus, .rp-filter-row select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.08); }

        .rp-legend { display: flex; gap: 14px; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
        .rp-legend-item { display: flex; align-items: center; font-size: 0.78rem; color: #64748b; gap: 5px; }
        .stat-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

        .rp-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .rp-table th { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; padding: 0 12px 10px; text-align: left; border-bottom: 1.5px solid #f1f5f9; white-space: nowrap; }
        .rp-table td { padding: 10px 12px; border-bottom: 1px solid #f8fafc; color: #334155; }
        .rp-table tr:last-child td { border-bottom: none; }
        .rp-table tr.total-row td { font-weight: 700; color: #0f172a; background: #f8fafc; border-top: 1.5px solid #f1f5f9; }

        .badge-grade { font-size: 0.72rem; font-weight: 600; background: #f1f5f9; color: #475569; padding: 3px 9px; border-radius: 99px; white-space: nowrap; }
        .rate-badge { font-size: 0.78rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
        .rate-badge.good { background: #dcfce7; color: #16a34a; }
        .rate-badge.warn { background: #fef9c3; color: #a16207; }
        .rate-badge.bad  { background: #fee2e2; color: #dc2626; }

        .week-chart { display: flex; align-items: flex-end; gap: 6px; height: 64px; margin-bottom: 1.25rem; }
        .week-chart-col { display: flex; flex-direction: column; align-items: center; flex: 1; }
        .week-chart-bar { width: 100%; border-radius: 5px 5px 0 0; min-height: 4px; }
        .week-chart-label { font-size: 0.68rem; color: #94a3b8; margin-top: 4px; }

        .inline-bar-wrap { display: inline-block; width: 60px; height: 5px; background: #f1f5f9; border-radius: 99px; vertical-align: middle; margin-right: 6px; overflow: hidden; }
        .inline-bar-fill { height: 100%; border-radius: 99px; }

        .rp-preview-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; flex-wrap: wrap; gap: 10px; }
        .rp-preview-count { font-size: 0.8rem; color: #94a3b8; }
        .btn-dl-pdf { font-size: 0.83rem; font-weight: 700; padding: 8px 18px; border-radius: 9px; border: none; background: #0f172a; color: #fff; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .btn-dl-pdf:hover { background: #1e293b; }
        .btn-dl-excel { font-size: 0.83rem; font-weight: 700; padding: 8px 18px; border-radius: 9px; border: none; background: #16a34a; color: #fff; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .btn-dl-excel:hover { background: #15803d; }
        .btn-dl-csv { font-size: 0.83rem; font-weight: 700; padding: 8px 18px; border-radius: 9px; border: 1.5px solid #94a3b8; background: transparent; color: #475569; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .btn-dl-csv:hover { background: #f1f5f9; }

        /* Status mini cards */
        .status-summary { display: flex; gap: 12px; margin-bottom: 1.25rem; flex-wrap: wrap; }
        .status-summary-card { flex: 1; min-width: 120px; border-radius: 12px; padding: 14px 16px; }
        .status-summary-card .count { font-size: 1.6rem; font-weight: 800; line-height: 1; }
        .status-summary-card .label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }

        /* Status badge in table */
        .status-badge { font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 8px; display: inline-block; white-space: nowrap; }
    </style>
@endpush

@php
    $iconSvgs = [
        'person-check'       => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M12.5 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm1.679-4.493-1.335 2.226a.75.75 0 0 1-1.174.144l-.774-.773a.5.5 0 0 1 .708-.708l.547.548 1.17-1.951a.5.5 0 1 1 .858.514zM11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM8 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>',
        'graph-up'           => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M0 0h1v15h15v1H0V0zm10 3.5a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-1 0V4.9l-3.613 4.417a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61L13.445 4H10.5a.5.5 0 0 1-.5-.5z"/></svg>',
        'calendar'           => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z"/></svg>',
        'sliders'            => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M11.5 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM9.05 3a2.5 2.5 0 0 1 4.9 0H16v1h-2.05a2.5 2.5 0 0 1-4.9 0H0V3h9.05zM4.5 7a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM2.05 8a2.5 2.5 0 0 1 4.9 0H16v1H6.95a2.5 2.5 0 0 1-4.9 0H0V8h2.05zm9.45 4a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm-2.45 1a2.5 2.5 0 0 1 4.9 0H16v1h-2.05a2.5 2.5 0 0 1-4.9 0H0v-1h9.05z"/></svg>',
        'exclamation-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/></svg>',
    ];
@endphp

<div class="rp-page">

    <h1 class="rp-heading">Reports</h1>
    <p class="rp-sub">Click Preview to view live data, then export as PDF, Excel, or CSV.</p>

    <div class="rp-search-wrap">
        <svg class="rp-search-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242 1.156a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/>
        </svg>
        <input type="text" class="rp-search" placeholder="Search reports…" wire:model.live="search" />
    </div>

    <div class="rp-section-label">Student Reports</div>

    @foreach($this->reports() as $report)

        {{-- ── Report card ── --}}
        <div class="rp-card {{ $activePreview === $report['key'] ? 'active' : '' }}">
            <div class="rp-icon-wrap" style="background:{{ $report['iconBg'] }}; color:{{ $report['iconColor'] }};">
                {!! $iconSvgs[$report['icon']] ?? '' !!}
            </div>
            <div class="rp-card-body">
                <p class="rp-card-title">{{ $report['title'] }}</p>
                <p class="rp-card-desc">{{ $report['description'] }}</p>
            </div>
            <span class="rp-tag" style="{{ $report['tagStyle'] }}">{{ $report['tag'] }}</span>
            <div class="rp-actions">
                <button class="btn-preview {{ $activePreview === $report['key'] ? 'active' : '' }}"
                        wire:click="preview('{{ $report['key'] }}')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>
                    Preview
                </button>
                <button class="btn-pdf"   wire:click="downloadPdf('{{ $report['key'] }}')">↓ PDF</button>
                <button class="btn-excel" wire:click="downloadExcel('{{ $report['key'] }}')">↓ Excel</button>
                <button class="btn-csv"   wire:click="downloadCsv('{{ $report['key'] }}')">↓ CSV</button>
            </div>
        </div>

        {{-- ── Preview panels ── --}}
        @if($activePreview === $report['key'] && !empty($previewData))

            {{-- ══ STUDENT STATUS LIST ══ --}}
            @if($report['key'] === 'student_status')
                <div class="rp-preview">
                    <p class="rp-preview-title">Current Student Status</p>
                    <p class="rp-preview-meta">Live view based on each student's last attendance record — not date-filtered.</p>

                    {{-- Filters --}}
                    <div class="rp-filter-row">
                        <div class="rp-filter-group">
                            <label>Grade</label>
                            <select wire:model.live="statusGradeFilter" wire:change="refreshStudentStatus()">
                                <option value="">All Grades</option>
                                @foreach($previewData['grades'] as $g)
                                    <option value="{{ $g }}">{{ $g }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="rp-filter-group">
                            <label>Status</label>
                            <select wire:model.live="statusFilter" wire:change="refreshStudentStatus()">
                                <option value="">All</option>
                                <option value="present">On Campus (In School)</option>
                                <option value="left">Off Campus</option>
                                <option value="not_reported">Never Checked In</option>
                            </select>
                        </div>
                    </div>

                    {{-- Summary mini cards --}}
                    <div class="status-summary">
                        <div class="status-summary-card" style="background:#dcfce7;">
                            <div class="count" style="color:#16a34a;">{{ $previewData['totalPresent'] }}</div>
                            <div class="label" style="color:#16a34a;">Present</div>
                        </div>
                        <div class="status-summary-card" style="background:#e0f2fe;">
                            <div class="count" style="color:#0284c7;">{{ $previewData['totalLeft'] }}</div>
                            <div class="label" style="color:#0284c7;">Left School</div>
                        </div>
                        <div class="status-summary-card" style="background:#fee2e2;">
                            <div class="count" style="color:#dc2626;">{{ $previewData['totalNotReported'] }}</div>
                            <div class="label" style="color:#dc2626;">Not Reported</div>
                        </div>
                        <div class="status-summary-card" style="background:#f1f5f9;">
                            <div class="count" style="color:#475569;">{{ $previewData['totalEnrolled'] }}</div>
                            <div class="label" style="color:#475569;">Total Enrolled</div>
                        </div>
                    </div>

                    <table class="rp-table">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th>Date of Last Record</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($previewData['rows'] as $row)
                            <tr>
                                <td style="font-weight:600; color:#0f172a;">{{ $row['name'] }}</td>
                                <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
                                <td>
                                    @if($row['status'] === 'present')
                                        <span class="status-badge" style="background:#dcfce7; color:#16a34a;">✓ Present</span>
                                    @elseif($row['status'] === 'left')
                                        <span class="status-badge" style="background:#e0f2fe; color:#0284c7;">↩ Left School</span>
                                    @else
                                        <span class="status-badge" style="background:#fee2e2; color:#dc2626;">✕ Not Reported</span>
                                    @endif
                                </td>
                                <td style="color:#64748b;">{{ $row['time'] ?? '—' }}</td>
                                <td style="color:#64748b;">{{ $row['date'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align:center; color:#94a3b8; padding:1.75rem;">
                                    No students match the selected filters.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>

                    <div class="rp-preview-footer">
                        <span class="rp-preview-count">{{ count($previewData['rows']) }} student{{ count($previewData['rows']) !== 1 ? 's' : '' }}</span>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-dl-excel" wire:click="downloadExcel('student_status')">↓ Excel</button>
                            <button class="btn-dl-csv"   wire:click="downloadCsv('student_status')">↓ CSV</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ══ DAILY ══ --}}
            @if($report['key'] === 'daily')
                <div class="rp-preview">
                    <p class="rp-preview-title">Daily Check-in Summary</p>
                    <p class="rp-preview-meta">{{ \Carbon\Carbon::parse($previewData['date'])->format('l, d F Y') }}</p>
                    <div class="rp-filter-row">
                        <div class="rp-filter-group">
                            <label>Date</label>
                            <input type="date" wire:model.live="dailyDate" wire:change="preview('daily')" />
                        </div>
                    </div>
                    <div class="rp-legend">
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#22c55e;"></span> In School</span>
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#f59e0b;"></span> Left School</span>
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#ef4444;"></span> Not Reported</span>
                        <span class="rp-legend-item" style="margin-left:auto; font-style:italic; font-size:0.75rem;">Rate = (In + Left) ÷ Enrolled</span>
                    </div>
                    <table class="rp-table">
                        <thead><tr><th>Grade</th><th>In School</th><th>Left School</th><th>Not Reported</th><th>Enrolled</th><th>Rate</th></tr></thead>
                        <tbody>
                        @foreach($previewData['rows'] as $row)
                            <tr>
                                <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
                                <td style="color:#16a34a; font-weight:600;">{{ $row['present'] }}</td>
                                <td style="color:#d97706; font-weight:600;">{{ $row['departed'] }}</td>
                                <td style="color:#dc2626; font-weight:600;">{{ $row['notIn'] }}</td>
                                <td style="color:#64748b;">{{ $row['total'] }}</td>
                                <td><span class="rate-badge {{ $row['rate'] >= 80 ? 'good' : ($row['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $row['rate'] }}%</span></td>
                            </tr>
                        @endforeach
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td style="color:#16a34a;">{{ $previewData['totals']['present'] }}</td>
                            <td style="color:#d97706;">{{ $previewData['totals']['departed'] }}</td>
                            <td style="color:#dc2626;">{{ $previewData['totals']['notIn'] }}</td>
                            <td>{{ $previewData['totals']['total'] }}</td>
                            <td><span class="rate-badge {{ $previewData['totals']['rate'] >= 80 ? 'good' : ($previewData['totals']['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $previewData['totals']['rate'] }}%</span></td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="rp-preview-footer">
                        <span class="rp-preview-count">{{ count($previewData['rows']) }} grades</span>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-dl-pdf"   wire:click="downloadPdf('daily')">↓ PDF</button>
                            <button class="btn-dl-excel" wire:click="downloadExcel('daily')">↓ Excel</button>
                            <button class="btn-dl-csv"   wire:click="downloadCsv('daily')">↓ CSV</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ══ WEEKLY ══ --}}
            @if($report['key'] === 'weekly')
                <div class="rp-preview">
                    <p class="rp-preview-title">Weekly Overview</p>
                    <p class="rp-preview-meta">{{ $previewData['weekLabel'] }} · {{ $previewData['totalStudents'] }} students enrolled</p>
                    <div class="rp-filter-row">
                        <div class="rp-filter-group">
                            <label>Week Starting</label>
                            <input type="date" wire:model.live="weeklyStartDate" wire:change="preview('weekly')" />
                        </div>
                    </div>
                    <div class="week-chart">
                        @foreach($previewData['rows'] as $row)
                            <div class="week-chart-col">
                                <div class="week-chart-bar" style="height:{{ max(4, $row['rate'] * 0.56) }}px; background:{{ $row['rate'] >= 80 ? '#22c55e' : ($row['rate'] >= 60 ? '#f59e0b' : '#ef4444') }};"></div>
                                <span class="week-chart-label">{{ $row['day'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <table class="rp-table">
                        <thead><tr><th>Day</th><th>Date</th><th>In School</th><th>Left School</th><th>Not Reported</th><th>Enrolled</th><th>Rate</th></tr></thead>
                        <tbody>
                        @foreach($previewData['rows'] as $row)
                            <tr>
                                <td style="font-weight:700; color:#0f172a;">{{ $row['day'] }}</td>
                                <td style="color:#64748b;">{{ $row['date'] }}</td>
                                <td style="color:#16a34a; font-weight:600;">{{ $row['present'] }}</td>
                                <td style="color:#d97706; font-weight:600;">{{ $row['departed'] }}</td>
                                <td style="color:#dc2626; font-weight:600;">{{ $row['notIn'] }}</td>
                                <td style="color:#64748b;">{{ $row['total'] }}</td>
                                <td>
                                    <span class="inline-bar-wrap"><span class="inline-bar-fill" style="width:{{ $row['rate'] }}%; background:{{ $row['rate'] >= 80 ? '#22c55e' : ($row['rate'] >= 60 ? '#f59e0b' : '#ef4444') }};"></span></span>
                                    <span class="rate-badge {{ $row['rate'] >= 80 ? 'good' : ($row['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $row['rate'] }}%</span>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="rp-preview-footer">
                        <span class="rp-preview-count">7 days</span>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-dl-pdf"   wire:click="downloadPdf('weekly')">↓ PDF</button>
                            <button class="btn-dl-excel" wire:click="downloadExcel('weekly')">↓ Excel</button>
                            <button class="btn-dl-csv"   wire:click="downloadCsv('weekly')">↓ CSV</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ══ MONTHLY ══ --}}
            @if($report['key'] === 'monthly')
                <div class="rp-preview">
                    <p class="rp-preview-title">Monthly Summary</p>
                    <p class="rp-preview-meta">{{ $previewData['monthLabel'] }} · {{ $previewData['totalDays'] }} school days</p>
                    <div class="rp-filter-row">
                        <div class="rp-filter-group"><label>Month</label><input type="month" wire:model.live="monthlyDate" wire:change="preview('monthly')" /></div>
                    </div>
                    <div class="rp-legend">
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#22c55e;"></span> Days In School</span>
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#f59e0b;"></span> Days Left School</span>
                        <span class="rp-legend-item"><span class="stat-dot" style="background:#ef4444;"></span> Days Not Reported</span>
                        <span class="rp-legend-item" style="margin-left:auto; font-style:italic; font-size:0.75rem;">Rate = student-days ÷ (enrolled × school days)</span>
                    </div>
                    <table class="rp-table">
                        <thead><tr><th>Grade</th><th>Days In</th><th>Days Left</th><th>Days Not Reported</th><th>Enrolled</th><th>School Days</th><th>Rate</th></tr></thead>
                        <tbody>
                        @foreach($previewData['rows'] as $row)
                            <tr>
                                <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
                                <td style="color:#16a34a; font-weight:600;">{{ $row['present'] }}</td>
                                <td style="color:#d97706; font-weight:600;">{{ $row['departed'] }}</td>
                                <td style="color:#dc2626; font-weight:600;">{{ $row['notIn'] }}</td>
                                <td style="color:#64748b;">{{ $row['total'] }}</td>
                                <td style="color:#64748b;">{{ $previewData['totalDays'] }}</td>
                                <td>
                                    <span class="inline-bar-wrap"><span class="inline-bar-fill" style="width:{{ $row['rate'] }}%; background:{{ $row['rate'] >= 80 ? '#22c55e' : ($row['rate'] >= 60 ? '#f59e0b' : '#ef4444') }};"></span></span>
                                    <span class="rate-badge {{ $row['rate'] >= 80 ? 'good' : ($row['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $row['rate'] }}%</span>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td style="color:#16a34a;">{{ $previewData['totals']['present'] }}</td>
                            <td style="color:#d97706;">{{ $previewData['totals']['departed'] }}</td>
                            <td style="color:#dc2626;">{{ $previewData['totals']['notIn'] }}</td>
                            <td>{{ $previewData['totals']['total'] }}</td>
                            <td>{{ $previewData['totalDays'] }}</td>
                            <td><span class="rate-badge {{ $previewData['totals']['rate'] >= 80 ? 'good' : ($previewData['totals']['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $previewData['totals']['rate'] }}%</span></td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="rp-preview-footer">
                        <span class="rp-preview-count">{{ count($previewData['rows']) }} grades</span>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-dl-pdf"   wire:click="downloadPdf('monthly')">↓ PDF</button>
                            <button class="btn-dl-excel" wire:click="downloadExcel('monthly')">↓ Excel</button>
                            <button class="btn-dl-csv"   wire:click="downloadCsv('monthly')">↓ CSV</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ══ CUSTOM ══ --}}
            @if($report['key'] === 'custom')
                <div class="rp-preview">
                    <p class="rp-preview-title">Custom Date Range</p>
                    <p class="rp-preview-meta">{{ $previewData['rangeLabel'] }} · {{ $previewData['totalDays'] }} school days</p>
                    <div class="rp-filter-row">
                        <div class="rp-filter-group"><label>From</label><input type="date" wire:model.live="customStartDate" wire:change="preview('custom')" /></div>
                        <div class="rp-filter-group"><label>To</label><input type="date" wire:model.live="customEndDate" wire:change="preview('custom')" /></div>
                    </div>
                    <table class="rp-table">
                        <thead><tr><th>Grade</th><th>Days In</th><th>Days Left</th><th>Days Not Reported</th><th>Enrolled</th><th>School Days</th><th>Rate</th></tr></thead>
                        <tbody>
                        @foreach($previewData['rows'] as $row)
                            <tr>
                                <td><span class="badge-grade">{{ $row['grade'] }}</span></td>
                                <td style="color:#16a34a; font-weight:600;">{{ $row['present'] }}</td>
                                <td style="color:#d97706; font-weight:600;">{{ $row['departed'] }}</td>
                                <td style="color:#dc2626; font-weight:600;">{{ $row['notIn'] }}</td>
                                <td style="color:#64748b;">{{ $row['total'] }}</td>
                                <td style="color:#64748b;">{{ $previewData['totalDays'] }}</td>
                                <td>
                                    <span class="inline-bar-wrap"><span class="inline-bar-fill" style="width:{{ $row['rate'] }}%; background:{{ $row['rate'] >= 80 ? '#22c55e' : ($row['rate'] >= 60 ? '#f59e0b' : '#ef4444') }};"></span></span>
                                    <span class="rate-badge {{ $row['rate'] >= 80 ? 'good' : ($row['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $row['rate'] }}%</span>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td style="color:#16a34a;">{{ $previewData['totals']['present'] }}</td>
                            <td style="color:#d97706;">{{ $previewData['totals']['departed'] }}</td>
                            <td style="color:#dc2626;">{{ $previewData['totals']['notIn'] }}</td>
                            <td>{{ $previewData['totals']['total'] }}</td>
                            <td>{{ $previewData['totalDays'] }}</td>
                            <td><span class="rate-badge {{ $previewData['totals']['rate'] >= 80 ? 'good' : ($previewData['totals']['rate'] >= 60 ? 'warn' : 'bad') }}">{{ $previewData['totals']['rate'] }}%</span></td>
                        </tr>
                        </tbody>
                    </table>
                    <div class="rp-preview-footer">
                        <span class="rp-preview-count">{{ count($previewData['rows']) }} grades</span>
                        <div style="display:flex; gap:10px;">
                            <button class="btn-dl-pdf"   wire:click="downloadPdf('custom')">↓ PDF</button>
                            <button class="btn-dl-excel" wire:click="downloadExcel('custom')">↓ Excel</button>
                            <button class="btn-dl-csv"   wire:click="downloadCsv('custom')">↓ CSV</button>
                        </div>
                    </div>
                </div>
            @endif

        @endif

    @endforeach

</div>
