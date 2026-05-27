<?php

use App\Models\Attendance;
use App\Models\Unit;
use App\Services\AttendanceSeeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $monthlyData = [];
    public $chartData = [];
    public $overtimeChartData = [];
    public $statusData;
    public $topOvertimeEmployees;
    public $entityLabel = 'Employee';
    public string $selectedDate = '';

    public function mount()
    {

        $orgId = Auth::user()->employee->organization_id ?? null;
        $this->isStudentRecord = Auth::user()->employee?->organization?->is_student_record ?? false;
        $this->entityLabel = $this->isStudentRecord ? 'Student' : 'Employee';

        // Refresh today's night-shift-aware statuses before charting — same
        // fix as the Dashboard's loadStaffStats().
        app(AttendanceSeeder::class)->seedIfDue($orgId, $this->isStudentRecord);

        // ?date= drives every chart below — defaults to today, capped at today.
        $requestedDate = request()->query('date');
        $referenceDate = $requestedDate ? Carbon::parse($requestedDate) : Carbon::today();
        if ($referenceDate->gt(Carbon::today())) {
            $referenceDate = Carbon::today();
        }
        $this->selectedDate = $referenceDate->toDateString();

        // --- 1. Monthly Attendance Data ---
        $start = $referenceDate->copy()->startOfMonth();
        $end = $referenceDate->copy()->endOfMonth();
        $days = $start->diffInDays($end);

        for ($i = 0; $i <= $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();

            // Base query for the day
            $baseQuery = Attendance::whereHas('employee', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            })->whereDate('date', $date);

            // Clone and count for each status
            $present = (clone $baseQuery)->whereIn('status', ['clocked_in', 'clocked_out'])->count();
            $absent = (clone $baseQuery)->whereIn('status', ['absent', 'unchecked_in'])->count();
            $leave = (clone $baseQuery)->where('status', 'on_leave')->count();
            $offShift = (clone $baseQuery)->where('status', 'off_shift')->count(); // <-- NEW STATUS

            $this->monthlyData[] = [
                'date' => $date,
                'present' => $present,
                'absent' => $absent,
                'leave' => $leave,
                'off_shift' => $offShift, // <-- ADDED
            ];
        }


        // --- 2. Unit Weekly Data (Chart Data) ---
        // Grouped by Unit, not raw Department rows. AD-sync data quality means the
        // departments table can carry dozens of near-duplicate rows for the same real
        // department (e.g. "Finance", "Finance - Financial Accounting", "FFinance -
        // Financial Accounting" — see docs/department-name-inconsistency.md), which is
        // exactly what made this chart unreadable once an org had "many departments."
        // Unit is the clean, deduplicated grouping level from the org hierarchy
        // (Company > Unit > Department > Section > Subsection).
        $startOfWeek = $referenceDate->copy()->startOfWeek(); // Monday
        $endOfWeek = $referenceDate->copy()->endOfWeek();     // Sunday

        $units = Unit::where('organization_id', $orgId)->get();

        $unitRows = [];

        foreach ($units as $unit) {
            $attendances = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
                ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
                    ->where('unit_id', $unit->id)
                )->get();

            $presentEmployeeIds = $attendances
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->pluck('employee_id')
                ->unique();

            $absentEmployeeIds = $attendances
                ->whereIn('status', ['absent', 'unchecked_in'])
                ->pluck('employee_id')
                ->unique()
                ->reject(fn($id) => $presentEmployeeIds->contains($id));

            $unitRows[] = [
                'name' => $unit->name,
                'present' => $presentEmployeeIds->count(),
                'absent' => $absentEmployeeIds->count(),
                'leave' => $attendances->where('status', 'on_leave')->pluck('employee_id')->unique()->count(),
                'off_shift' => $attendances->where('status', 'off_shift')->pluck('employee_id')->unique()->count(),
                'overtime' => (float) $attendances->sum('overtime_hours'),
            ];
        }

        // Employees not yet mapped to a Unit (pending their next AD sync/backfill) are
        // surfaced as their own "Unassigned" bar rather than silently dropped from the
        // chart — same principle as the "no subsection" handling on the Employees page.
        $unassigned = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId)->whereNull('unit_id'))
            ->get();

        if ($unassigned->isNotEmpty()) {
            $presentEmployeeIds = $unassigned->whereIn('status', ['clocked_in', 'clocked_out'])->pluck('employee_id')->unique();
            $absentEmployeeIds = $unassigned->whereIn('status', ['absent', 'unchecked_in'])->pluck('employee_id')->unique()
                ->reject(fn($id) => $presentEmployeeIds->contains($id));

            $unitRows[] = [
                'name' => 'Unassigned',
                'present' => $presentEmployeeIds->count(),
                'absent' => $absentEmployeeIds->count(),
                'leave' => $unassigned->where('status', 'on_leave')->pluck('employee_id')->unique()->count(),
                'off_shift' => $unassigned->where('status', 'off_shift')->pluck('employee_id')->unique()->count(),
                'overtime' => (float) $unassigned->sum('overtime_hours'),
            ];
        }

        // Busiest units first, so the chart leads with what actually matters.
        $attendanceRows = $unitRows;
        usort($attendanceRows, fn($a, $b) => ($b['present'] + $b['absent'] + $b['leave'] + $b['off_shift'])
            <=> ($a['present'] + $a['absent'] + $a['leave'] + $a['off_shift']));

        $this->chartData = [
            'categories' => array_column($attendanceRows, 'name'),
            'series' => [
                ['name' => 'Present', 'data' => array_column($attendanceRows, 'present')],
                ['name' => 'Absent', 'data' => array_column($attendanceRows, 'absent')],
                ['name' => 'Leave', 'data' => array_column($attendanceRows, 'leave')],
                ['name' => 'Off Shift', 'data' => array_column($attendanceRows, 'off_shift')],
            ]
        ];


        // --- 3. Daily Attendance Status (Pie Chart Data) ---
        $today = $referenceDate->copy();

        // Get the attendance for today
        $attendancesToday = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereDate('date', $today)
            ->get();

        // Aggregate by status and store in the new single array
        $this->statusData = [
            'present' => $attendancesToday->whereIn('status', ['clocked_in', 'clocked_out'])->count(),
            'absent' => $attendancesToday->whereIn('status', ['absent', 'unchecked_in'])->count(),
            'onLeave' => $attendancesToday->where('status', 'on_leave')->count(),
            'offShift' => $attendancesToday->where('status', 'off_shift')->count(), // <-- NEW STATUS
        ];


        // --- 4. Overtime Data — same per-unit rows computed above, just re-sorted by
        // overtime hours (busiest units for attendance and for overtime aren't always
        // the same units, so each chart gets its own descending order).
        $overtimeRows = $unitRows;
        usort($overtimeRows, fn($a, $b) => $b['overtime'] <=> $a['overtime']);

        $this->overtimeChartData = [
            'categories' => array_column($overtimeRows, 'name'),
            'series' => [
                ['name' => 'Overtime Hours', 'data' => array_column($overtimeRows, 'overtime')],
            ]
        ];

        $this->topOvertimeEmployees = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->selectRaw('employee_id, SUM(overtime_hours) as total_overtime')
            ->groupBy('employee_id')
            ->orderByDesc('total_overtime')
            ->take(5)
            ->with('employee')
            ->get();
    }

    /* ─────────────────────────────────────────────
       DATE SELECTOR — full-page redirect with ?date=
       so ApexCharts (rendered on DOMContentLoaded) get
       fresh data instead of stale JS from a partial update.
       Uses the named route (not request()->url()) because inside
       a Livewire action, request() is bound to the AJAX POST to
       livewire/update, not the page the user is viewing.
    ───────────────────────────────────────────── */
    #[On('analytics-date-changed')]
    public function viewForSelectedDate(): void
    {
        $this->redirect(route('analytics') . '?date=' . $this->selectedDate);
    }

    public function resetToToday(): void
    {
        $this->redirect(route('analytics'));
    }

}; ?>

<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Organization Reports"
            :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Reports',
            'icon' => '<iconify-icon icon=\'mdi:file-chart-outline\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Organization',
            'icon' => '<iconify-icon icon=\'mdi:domain\' class=\'fs-5\'></iconify-icon>',
        ]
       ]"
        />


        @php($isSelectedToday = $selectedDate === now()->toDateString())
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2"
             style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.5rem .75rem; box-shadow:0 1px 2px rgba(0,0,0,.04);">
            <iconify-icon icon="mdi:calendar-month-outline" style="font-size:20px; color:#64748b;"></iconify-icon>
            <input type="date" class="form-control form-control-sm" style="max-width:170px; background:#fff; color:#1f2937;"
                   wire:model="selectedDate" wire:change="$dispatch('analytics-date-changed')" max="{{ now()->toDateString() }}">
            @unless($isSelectedToday)
                <button type="button" class="btn btn-sm btn-primary" wire:click="resetToToday">
                    <iconify-icon icon="mdi:backup-restore"></iconify-icon>
                    Today
                </button>
            @endunless
            <span class="text-muted small">
                Showing data for {{ \Carbon\Carbon::parse($selectedDate)->format('d M Y') }}
            </span>
        </div>

        <livewire:admin.summaries.employee-statuses/>
    </div>

    <div class="col-lg-12 mt-4">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Monthly {{ $entityLabel }} Attendance Timeline</h4>
                <div id="monthly-employee-attendance" class="ms-n3"></div>
            </div>
        </div>
    </div>

    <div class="row d-flex align-items-stretch mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-4">Weekly Attendance by Unit</h5>
                    <div id="department-weekly-data"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h4 class="card-title">Daily Attendance Status</h4>
                    <div id="daily-attendance"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row d-flex align-items-stretch">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-4">Weekly Overtime Hours by Unit</h5>
                    <div id="department-weekly-overtime"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm recent-activity-card">
                <div class="card-header">
                    <h5 class="mb-0">Top 5 Overtime {{ $entityLabel }}s (This Week)</h5>
                </div>
                <div class="card-body">
                    @if ($topOvertimeEmployees->isEmpty())
                        <div class="text-center py-4">
                    <span class="iconify text-muted mb-2" data-icon="mdi:emoticon-sad-outline"
                          style="font-size: 32px;"></span>
                            <div class="fw-semibold text-muted">No Overtime Data Available</div>
                        </div>
                    @else
                        <ul class="list-unstyled">
                            @foreach ($topOvertimeEmployees as $emp)
                                <li class="activity-item d-flex align-items-center mb-4">

                                    <div class="d-flex align-items-center flex-grow-1">
                                        <iconify-icon icon="mdi:account-circle" class="me-3 text-primary"
                                                      width="32"></iconify-icon>

                                        <div class="d-flex flex-column">
                                            <span class="fw-semibold text-dark">{{ $emp->employee->name }}</span>

                                            <small
                                                class='text-muted d-block'>{{ $emp->employee->department->name ?? 'N/A' }}</small>
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <div class="fw-bold fs-5 text-warning">
                                            {{ number_format($emp->total_overtime, 1) }}
                                        </div>
                                        <div class="small text-muted">hrs</div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {


            // Daily Pie Chart
            const dailydata = @json($statusData);

            const seriesData = Object.values(dailydata);
            const labels = Object.keys(dailydata).map(key => {
                if (key === 'onLeave') return 'On Leave';
                if (key === 'offShift') return 'Off Shift'; // <-- NEW LABEL
                return key.charAt(0).toUpperCase() + key.slice(1);
            });

            const options_simple = {
                series: seriesData,
                chart: {
                    fontFamily: "inherit",
                    type: "pie",
                    height: 300,
                },
                // Present (blue), Absent (red), Leave (yellow), Off Shift (gray) <-- NEW COLOR ADDED
                colors: ["#0d6efd", "#dc3545", "#ffc107", "#6c757d"],
                labels: labels,
                legend: {
                    position: "bottom",
                    horizontalAlign: "center",
                    fontSize: "12px",
                    labels: {
                        colors: "#a1aab2"
                    },
                },
                responsive: [
                    {
                        breakpoint: 480,
                        options: {
                            chart: {height: 250},
                            legend: {position: "bottom"}
                        },
                    },
                ],
            };


            const chart_pie_simple = new ApexCharts(
                document.querySelector("#daily-attendance"),
                options_simple
            );
            chart_pie_simple.render();

            const data = @json($monthlyData);

            const presentSeries = data.map(d => ({
                x: new Date(d.date).toISOString(),
                y: d.present
            }));

            const absentSeries = data.map(d => ({
                x: new Date(d.date).toISOString(),
                y: d.absent
            }));

            const leaveSeries = data.map(d => ({
                x: new Date(d.date).toISOString(),
                y: d.leave
            }));

            const offShiftSeries = data.map(d => ({ // <-- NEW SERIES FOR MONTHLY CHART
                x: new Date(d.date).toISOString(),
                y: d.off_shift
            }));

            const options_zoomable = {
                series: [
                    {
                        name: "Present",
                        data: presentSeries
                    },
                    {
                        name: "Absent",
                        data: absentSeries
                    },
                    {
                        name: "Leave",
                        data: leaveSeries
                    },
                    { // <-- ADDED SERIES
                        name: "Off Shift",
                        data: offShiftSeries
                    }
                ],
                chart: {
                    fontFamily: "inherit",
                    type: "area",
                    stacked: false,
                    height: 350,
                    zoom: {
                        type: "x",
                        enabled: true,
                        autoScaleYaxis: true,
                    },
                    toolbar: {
                        autoSelected: "zoom",
                        show: true,
                    },
                },
                dataLabels: {
                    enabled: false,
                },
                grid: {
                    borderColor: "transparent",
                    padding: {
                        top: 0,
                        right: 0,
                        bottom: 0,
                        left: 0,
                    },
                },
                // Present (blue), Absent (red), Leave (yellow), Off Shift (gray) <-- NEW COLOR ADDED
                colors: ["#0d6efd", "#dc3545", "#ffc107", "#6c757d"],
                markers: {
                    size: 3,
                },
                fill: {
                    type: "gradient",
                    gradient: {
                        shadeIntensity: 1,
                        inverseColors: false,
                        opacityFrom: 0.4,
                        opacityTo: 0,
                        stops: [0, 90, 100],
                    },
                },
                yaxis: {
                    labels: {
                        style: {
                            colors: "#a1aab2",
                        },
                    },
                    title: {
                        text: "{{ $entityLabel }} Count"
                    }
                },
                xaxis: {
                    type: "datetime",
                    labels: {
                        style: {
                            colors: "#a1aab2",
                        },
                    },
                },
                tooltip: {
                    shared: false,
                    y: {
                        formatter: function (val) {
                            return val + " {{ Str::lower($entityLabel) }}s";
                        },
                    },
                    theme: "dark",
                },
            };

            const chart = new ApexCharts(
                document.querySelector("#monthly-employee-attendance"),
                options_zoomable
            );
            chart.render();


            // Unit Weekly Data (Stacked Bar Chart)
            const chartData = @json($chartData);

            // Fixed heights are exactly what made this chart unreadable once it had many
            // bars — each category needs a minimum amount of vertical room regardless of
            // how many there are, so height scales with the category count instead.
            const unitChartHeight = Math.min(900, Math.max(320, chartData.categories.length * 46));

            const options_stacked = {
                series: chartData.series, // Now contains 4 series
                chart: {
                    fontFamily: "inherit",
                    type: "bar",
                    height: unitChartHeight,
                    stacked: true,
                    toolbar: {show: false},
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        barHeight: "65%",
                    },
                },
                grid: {
                    borderColor: "transparent",
                },
                // Present (blue), Absent (red), Leave (yellow), Off Shift (gray) <-- NEW COLOR ADDED
                colors: ["#0d6efd", "#dc3545", "#ffc107", "#6c757d"],
                xaxis: {
                    categories: chartData.categories,
                    labels: {
                        style: {colors: "#a1aab2"},
                    },
                },
                yaxis: {
                    labels: {
                        style: {colors: "#a1aab2"},
                    },
                },
                tooltip: {
                    y: {
                        formatter: function (val) {
                            return val + " employees";
                        },
                    },
                    theme: "dark",
                },
                fill: {
                    opacity: 1,
                },
                legend: {
                    position: "top",
                    horizontalAlign: "left",
                    offsetX: 40,
                    labels: {
                        colors: ["#a1aab2"],
                    },
                },
            };

            const dchart = new ApexCharts(document.querySelector("#department-weekly-data"), options_stacked);
            dchart.render();

            // Overtime Chart and Top Employees data remain the same
            const overtimeChartData = @json($overtimeChartData);
            const overtimeChartHeight = Math.min(900, Math.max(320, overtimeChartData.categories.length * 46));

            const overtimeOptions = {
                series: overtimeChartData.series,
                chart: {
                    fontFamily: "inherit",
                    type: "bar",
                    height: overtimeChartHeight,
                    toolbar: {show: false},
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        barHeight: "65%",
                    },
                },
                grid: {
                    borderColor: "transparent",
                },
                colors: ["#f59e0b"], // orange for overtime
                xaxis: {
                    categories: overtimeChartData.categories,
                    labels: {style: {colors: "#a1aab2"}},
                    title: {text: "Overtime Hours"}
                },
                yaxis: {
                    labels: {style: {colors: "#a1aab2"}},
                },
                tooltip: {
                    y: {
                        formatter: function (val) {
                            return val + " hrs";
                        },
                    },
                    theme: "dark",
                },
                fill: {opacity: 1},
                legend: {
                    position: "top",
                    horizontalAlign: "left",
                    offsetX: 40,
                    labels: {colors: ["#a1aab2"]},
                },
            };

            const overtimeChart = new ApexCharts(
                document.querySelector("#department-weekly-overtime"),
                overtimeOptions
            );
            overtimeChart.render();

        });
    </script>
@endpush




