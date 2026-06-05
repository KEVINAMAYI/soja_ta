<?php

use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Attendance;
use App\Models\Employee;

new class extends Component {
    use WithPagination;

    public $reportDate;

    public $totalEmployees    = 0;
    public $presentCount      = 0;
    public $absentCount       = 0;
    public $attendanceRate    = 0;
    public $lateArrivals      = 0;
    public $autoClockOutCount = 0;

    public $topOvertimeEmployee = null;
    public $earliestClockIn     = null;
    public $absentEmployees     = [];
    public $entityLabel = 'Employee';

    protected $paginationTheme = 'bootstrap';

    public function mount(): void
    {
        $this->entityLabel = auth()->user()->employee?->organization?->is_student_record  ? "Student" : "Employee";
        $this->reportDate = now()->toDateString();
        $this->loadStats();
    }

    public function updatedReportDate(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function loadStats(): void
    {
        $date  = Carbon::parse($this->reportDate);
        $orgId = auth()->user()->employee->organization_id ?? null;

        $employees = Employee::where('active', 1)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->with('department')
            ->get();

        $employeeIds          = $employees->pluck('id');
        $this->totalEmployees = $employees->count();

        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $date)
            ->get();

        $presentIds = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->pluck('employee_id')->unique();

        $absentIds = $attendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->pluck('employee_id')->unique()
            ->reject(fn($id) => $presentIds->contains($id));

        $this->presentCount   = $presentIds->count();
        $this->absentCount    = $absentIds->count();
        $this->lateArrivals   = $attendances->where('is_late_checkin', 1)->count();
        $this->attendanceRate = $this->totalEmployees > 0
            ? round(($this->presentCount / $this->totalEmployees) * 100, 0)
            : 0;

        $topOt = $attendances->sortByDesc('overtime_hours')->first();
        if ($topOt && ($topOt->overtime_hours ?? 0) > 0) {
            $emp = $employees->firstWhere('id', $topOt->employee_id);
            $this->topOvertimeEmployee = [
                'name'       => $emp?->name ?? '—',
                'ot_hours'   => number_format($topOt->overtime_hours, 2),
                'department' => $emp?->department?->name ?? '—',
            ];
        } else {
            $this->topOvertimeEmployee = null;
        }

        $earliest = $attendances->whereNotNull('check_in_time')->sortBy('check_in_time')->first();
        if ($earliest) {
            $emp = $employees->firstWhere('id', $earliest->employee_id);
            $this->earliestClockIn = [
                'time'       => Carbon::parse($earliest->check_in_time)->format('g:i A'),
                'name'       => $emp?->name ?? '—',
                'department' => $emp?->department?->name ?? '—',
            ];
        } else {
            $this->earliestClockIn = null;
        }

        $this->autoClockOutCount = $attendances
            ->filter(fn($a) => ($a->auto_clocked_out ?? false) == true
                || ($a->status === 'clocked_in' && empty($a->check_out_time)))
            ->pluck('employee_id')->unique()->count();

        $this->absentEmployees = $employees
            ->whereIn('id', $absentIds->values()->all())
            ->map(fn($emp) => [
                'name'       => $emp->name,
                'department' => $emp->department?->name ?? '—',
            ])
            ->values()
            ->all();
    }

    public function with(): array
    {
        $date  = Carbon::parse($this->reportDate);
        $orgId = auth()->user()->employee->organization_id ?? null;

        $presentData = Attendance::with(['employee.department'])
            ->whereDate('date', $date)
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->when($orgId, fn($q) => $q->whereHas('employee',
                fn($q2) => $q2->where('organization_id', $orgId)
            ))
            ->orderBy('check_in_time')
            ->paginate(15);

        return ['presentData' => $presentData];
    }

    private function formatMinutes(?int $minutes): string
    {
        if (!$minutes || $minutes <= 0) return '0 min';
        if ($minutes >= 60) {
            $h = floor($minutes / 60);
            $m = $minutes % 60;
            return $m > 0 ? "{$h}h {$m}min" : "{$h}h";
        }
        return "{$minutes} min";
    }
}; ?>

@push('styles')
    <style>
        /* ── Stats bar ── */
        .dsr-bar {
            display: grid;
            grid-template-columns: 1fr 1px 1fr 1px 1fr 1px 1fr;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .dsr-bar-cell {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.25rem 0.75rem 1rem;
            text-align: center;
        }
        .dsr-bar-divider { background: #e5e7eb; }
        .dsr-val {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -1px;
        }
        .dsr-val-sm { font-size: 1.2rem; font-weight: 700; }
        .dsr-lbl {
            font-size: 0.63rem;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            margin-top: 0.3rem;
        }
        .dsr-prog-wrap {
            width: 70%;
            height: 4px;
            background: #e5e7eb;
            border-radius: 99px;
            margin-top: 0.45rem;
            overflow: hidden;
        }
        .dsr-prog-fill {
            height: 100%;
            background: #22c55e;
            border-radius: 99px;
        }

        /* ── Section labels ── */
        .dsr-section-lbl {
            display: block;
            font-size: 0.68rem;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
        }
        .dsr-section-hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 0 0 1rem 0;
        }

        /* ── Highlight cards ── */
        .dsr-hl-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem 1.15rem;
            min-height: 88px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 2px 6px rgba(0,0,0,0.03);
            height: 100%;
        }
        .dsr-hl-icon  { font-size: 1rem; margin-bottom: 0.2rem; line-height: 1; }
        .dsr-hl-val   { font-size: 1.15rem; font-weight: 800; line-height: 1.2; }
        .dsr-hl-sub   { font-size: 0.77rem; color: #6b7280; margin-top: 0.2rem; }

        /* ── Absent chips ── */
        .dsr-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.4rem 0.7rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .dsr-chip-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #ef4444;
            flex-shrink: 0;
        }
        .dsr-chip-name { font-size: 0.82rem; font-weight: 600; color: #111827; white-space: nowrap; }
        .dsr-chip-dept { font-size: 0.71rem; color: #9ca3af; white-space: nowrap; }

        /* ── Table ── */
        .dsr-table { width: 100%; border-collapse: collapse; }
        .dsr-table thead th {
            font-size: 0.67rem; font-weight: 700; color: #9ca3af;
            letter-spacing: 0.06em; text-transform: uppercase;
            padding: 0.55rem 0.85rem;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap; background: #fff;
        }
        .dsr-table tbody td {
            font-size: 0.875rem; padding: 0.7rem 0.85rem;
            color: #111827; border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .dsr-table tbody tr:last-child td { border-bottom: none; }
        .dsr-table tbody tr:hover td { background: #fafafa; }
        .dsr-name { font-weight: 600; }
        .dsr-dept {
            display: inline-block; font-size: 0.71rem; font-weight: 500;
            color: #6b7280; background: #f3f4f6; border-radius: 99px;
            padding: 0.18rem 0.55rem; white-space: nowrap;
        }
        .dsr-time { font-weight: 600; font-variant-numeric: tabular-nums; }
        .dsr-pill { display: inline-block; font-size: 0.71rem; font-weight: 600; border-radius: 99px; padding: 0.2rem 0.6rem; white-space: nowrap; }
        .dsr-pill-g { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .dsr-pill-b { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .dsr-pill-o { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
        .dsr-ontime { font-size: 0.75rem; color: #9ca3af; }
        .dsr-ot { font-size: 0.8rem; font-weight: 700; color: #7c3aed; }

        /* ── Empty state ── */
        .dsr-empty { display: flex; flex-direction: column; align-items: center; gap: 0.3rem; padding: 2.5rem 0; }
        .dsr-empty-icon  { font-size: 2rem; }
        .dsr-empty-title { font-size: 0.9rem; font-weight: 600; color: #374151; }
        .dsr-empty-sub   { font-size: 0.8rem; color: #9ca3af; }

        /* ── Footer ── */
        .dsr-footer {
            margin-top: 1.5rem; padding-top: 0.75rem;
            border-top: 1px solid #f3f4f6;
            text-align: center; font-size: 0.75rem; color: #9ca3af;
        }

        /* ── Date input ── */
        .dsr-date-input {
            width: 160px !important;
            border-radius: 8px !important;
            border: 1px solid #e5e7eb !important;
            font-size: 0.85rem !important;
            padding: 0.35rem 0.65rem !important;
            box-shadow: none !important;
        }

        /* ── Responsive bar ── */
        @media (max-width: 640px) {
            .dsr-bar {
                grid-template-columns: 1fr 1fr;
            }
            .dsr-bar-divider { display: none; }
            .dsr-bar-cell { border-bottom: 1px solid #e5e7eb; }
            .dsr-val { font-size: 1.6rem; }
        }
    </style>
@endpush

<div>
    <div class="card">
        <div class="card-body p-4">

            {{-- Header --}}
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h5 class="mb-0 fw-bold">Daily Attendance Summary</h5>
                    <small class="text-muted">
                        {{ \Carbon\Carbon::parse($reportDate)->format('l, F j, Y') }}
                    </small>
                </div>
                <input type="date"
                       class="form-control dsr-date-input"
                       wire:model.live="reportDate">
            </div>

            {{-- Stats bar --}}
            <div class="dsr-bar">
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-success">{{ $presentCount }}</span>
                    <span class="dsr-lbl">Present</span>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-danger">{{ $absentCount }}</span>
                    <span class="dsr-lbl">Absent</span>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-dark">
                        {{ $attendanceRate }}<span class="dsr-val-sm">%</span>
                    </span>
                    <span class="dsr-lbl">Attendance Rate</span>
                    <div class="dsr-prog-wrap">
                        <div class="dsr-prog-fill" style="width:{{ $attendanceRate }}%"></div>
                    </div>
                </div>
                <div class="dsr-bar-divider"></div>
                <div class="dsr-bar-cell">
                    <span class="dsr-val text-warning">{{ $lateArrivals }}</span>
                    <span class="dsr-lbl">Late Arrivals</span>
                </div>
            </div>

            {{-- Highlights --}}
            <span class="dsr-section-lbl">Highlights</span>
            <hr class="dsr-section-hr">

            {{-- Inline flexbox: completely override-proof 2-per-row layout --}}
            <div style="display:flex; flex-wrap:wrap; gap:0.85rem; margin-bottom:1.5rem;">

                <div style="flex:1 1 calc(50% - 0.85rem); min-width:240px; box-sizing:border-box;">
                    <div class="dsr-hl-card">
                        <div class="dsr-hl-icon">⚠️</div>
                        <div class="dsr-hl-val text-danger">{{ $absentCount }} of {{ $totalEmployees }} absent</div>
                        <div class="dsr-hl-sub">Employees with no attendance record today</div>
                    </div>
                </div>

                <div style="flex:1 1 calc(50% - 0.85rem); min-width:240px; box-sizing:border-box;">
                    <div class="dsr-hl-card">
                        <div class="dsr-hl-icon">🕐</div>
                        @if($topOvertimeEmployee)
                            <div class="dsr-hl-val text-success">{{ $topOvertimeEmployee['name'] }}</div>
                            <div class="dsr-hl-sub">Top overtime: {{ $topOvertimeEmployee['ot_hours'] }} hrs — {{ $topOvertimeEmployee['department'] }}</div>
                        @else
                            <div class="dsr-hl-val text-muted">No overtime recorded</div>
                            <div class="dsr-hl-sub">No overtime data for this period</div>
                        @endif
                    </div>
                </div>

                <div style="flex:1 1 calc(50% - 0.85rem); min-width:240px; box-sizing:border-box;">
                    <div class="dsr-hl-card">
                        <div class="dsr-hl-icon">🌅</div>
                        @if($earliestClockIn)
                            <div class="dsr-hl-val text-success">{{ $earliestClockIn['time'] }}</div>
                            <div class="dsr-hl-sub">Earliest clock-in — {{ $earliestClockIn['name'] }} ({{ $earliestClockIn['department'] }})</div>
                        @else
                            <div class="dsr-hl-val text-muted">No clock-ins yet</div>
                            <div class="dsr-hl-sub">No clock-in data for this period</div>
                        @endif
                    </div>
                </div>

                <div style="flex:1 1 calc(50% - 0.85rem); min-width:240px; box-sizing:border-box;">
                    <div class="dsr-hl-card">
                        <div class="dsr-hl-icon">🔴</div>
                        <div class="dsr-hl-val text-danger">{{ $autoClockOutCount }} of {{ $totalEmployees }}</div>
                        <div class="dsr-hl-sub">Auto clocked out — no clock-out recorded at end of shift</div>
                    </div>
                </div>

            </div>

            {{-- Absent chips --}}
            @if(count($absentEmployees) > 0)
                <span class="dsr-section-lbl">Absent Today</span>
                <hr class="dsr-section-hr">
                <div class="d-flex flex-wrap gap-2 mb-4">
                    @foreach($absentEmployees as $emp)
                        <div class="dsr-chip">
                            <span class="dsr-chip-dot"></span>
                            <div>
                                <div class="dsr-chip-name">{{ $emp['name'] }}</div>
                                <div class="dsr-chip-dept">{{ $emp['department'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Present table --}}
            <span class="dsr-section-lbl">Present Today</span>
            <hr class="dsr-section-hr">

            <div class="table-responsive">
                <table class="dsr-table">
                    <thead>
                    <tr>
                        <th>{{ $entityLabel }}</th>
                        <th>Department</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th>Status</th>
                        <th>Late</th>
                        <th>OT Hrs</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($presentData as $att)
                        @php
                            $isLate  = $att->is_late_checkin ?? false;
                            $isOut   = $att->status === 'clocked_out';
                            $otHours = $att->overtime_hours ?? 0;
                        @endphp
                        <tr>
                            <td><span class="dsr-name">{{ $att->employee->name ?? '—' }}</span></td>
                            <td><span class="dsr-dept">{{ $att->employee->department->name ?? '—' }}</span></td>
                            <td>
                                <span class="dsr-time {{ $isLate ? 'text-warning' : 'text-success' }}">
                                    {{ $att->check_in_time ? \Carbon\Carbon::parse($att->check_in_time)->format('g:i A') : '—' }}
                                </span>
                            </td>
                            <td>
                                <span class="dsr-time {{ $isOut ? 'text-dark' : 'text-muted' }}">
                                    {{ $att->check_out_time ? \Carbon\Carbon::parse($att->check_out_time)->format('g:i A') : '—' }}
                                </span>
                            </td>
                            <td>
                                @if($isOut)
                                    <span class="dsr-pill dsr-pill-g">Clocked Out</span>
                                @else
                                    <span class="dsr-pill dsr-pill-b">Clocked In</span>
                                @endif
                            </td>
                            <td>
                                @if($isLate)
                                    <span class="dsr-pill dsr-pill-o">{{ $this->formatMinutes($att->minutes_late ?? 0) }}</span>
                                @else
                                    <span class="dsr-ontime">On time</span>
                                @endif
                            </td>
                            <td>
                                @if($otHours > 0)
                                    <span class="dsr-ot">+{{ number_format($otHours, 2) }}h</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="dsr-empty">
                                    <span class="dsr-empty-icon">📋</span>
                                    <div class="dsr-empty-title">No {{ strtolower($entityLabel) }}s clocked in yet</div>
                                    <div class="dsr-empty-sub">Check back later or select a different date</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($presentData->hasPages())
                <div class="d-flex justify-content-center mt-3">
                    {{ $presentData->links() }}
                </div>
            @endif

            <div class="dsr-footer">
                Generated on {{ now()->format('l, F j, Y \a\t g:i A') }}
            </div>

        </div>
    </div>
</div>
