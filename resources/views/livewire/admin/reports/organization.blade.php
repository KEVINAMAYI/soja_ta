<?php

use App\Models\Attendance;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public $monthlyData = [];
    public $chartData = [];
    public $overtimeChartData = [];
    public $statusData;
    public $topOvertimeEmployees;
    public $entityLabel = 'Employee';

    // Date filter for the daily attendance highlight cards
    public string $selectedDate = '';
    public bool $isToday = true;
    public string $selectedDateLabel = '';
    public string $monthLabel = '';
    public string $weekLabel = '';
    private ?int $orgId = null;


    public function mount()
    {

        $orgId = Auth::user()->employee->organization_id ?? null;
        $this->orgId = $orgId;
        $this->isStudentRecord = Auth::user()->employee?->organization?->is_student_record ?? false;
        $this->entityLabel = $this->isStudentRecord ? 'Student' : 'Employee';
        $this->selectedDate = Carbon::today()->toDateString();

        // --- 1. Monthly Attendance Data ---
        $this->loadMonthlyData();

        // --- 2. Department Weekly Data (Chart Data) ---
        $this->loadWeeklyDepartmentData();

        // --- 3. Daily Attendance Status (Pie Chart Data) ---
        $this->loadDailyStatus();


        // --- 4. Overtime Data (No change needed) ---
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $departments = Department::where('organization_id', $orgId)->get();

        $overtimeCategories = [];
        $overtimeSeriesData = [];

        foreach ($departments as $dept) {
            $overtimeCategories[] = $dept->name;

            $attendances = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
                ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
                    ->where('department_id', $dept->id)
                )->get();

            $totalOvertime = $attendances->sum('overtime_hours');

            $overtimeSeriesData[] = $totalOvertime;
        }

        $this->overtimeChartData = [
            'categories' => $overtimeCategories,
            'series' => [
                ['name' => 'Overtime Hours', 'data' => $overtimeSeriesData],
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
       Monthly attendance timeline — for the month
       containing the selected date
    ───────────────────────────────────────────── */
    private function loadMonthlyData(): void
    {
        $selected = Carbon::parse($this->selectedDate);
        $start = $selected->copy()->startOfMonth();
        $end = $selected->copy()->endOfMonth();
        $days = $start->diffInDays($end);
        $this->monthLabel = $selected->format('F Y');

        $monthlyData = [];

        for ($i = 0; $i <= $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();

            // Base query for the day
            $baseQuery = Attendance::whereHas('employee', function ($q) {
                $q->where('organization_id', $this->orgId);
            })->whereDate('date', $date);

            // Clone and count for each status
            $present = (clone $baseQuery)->whereIn('status', ['clocked_in', 'clocked_out'])->count();
            $absent = (clone $baseQuery)->whereIn('status', ['absent', 'unchecked_in'])->count();
            $leave = (clone $baseQuery)->where('status', 'on_leave')->count();
            $offShift = (clone $baseQuery)->where('status', 'off_shift')->count();

            $monthlyData[] = [
                'date' => $date,
                'present' => $present,
                'absent' => $absent,
                'leave' => $leave,
                'off_shift' => $offShift,
            ];
        }

        $this->monthlyData = $monthlyData;
    }

    /* ─────────────────────────────────────────────
       Department attendance breakdown — for the week
       containing the selected date
    ───────────────────────────────────────────── */
    private function loadWeeklyDepartmentData(): void
    {
        $selected = Carbon::parse($this->selectedDate);
        $startOfWeek = $selected->copy()->startOfWeek(); // Monday
        $endOfWeek = $selected->copy()->endOfWeek();     // Sunday
        $this->weekLabel = $startOfWeek->format('d M') . ' - ' . $endOfWeek->format('d M');

        $departments = Department::where('organization_id', $this->orgId)->get();

        $categories = [];
        $presentData = [];
        $absentData = [];
        $leaveData = [];
        $offShiftData = [];

        foreach ($departments as $dept) {
            $categories[] = $dept->name;

            $attendances = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
                ->whereHas('employee', fn($q) => $q->where('organization_id', $this->orgId)
                    ->where('department_id', $dept->id)
                )->get();

            // Get employees who actually showed up (clocked in or out)
            $presentEmployeeIds = $attendances
                ->whereIn('status', ['clocked_in', 'clocked_out'])
                ->pluck('employee_id')
                ->unique();

            // Get employees marked absent BUT exclude those who showed up
            $absentEmployeeIds = $attendances
                ->whereIn('status', ['absent', 'unchecked_in'])
                ->pluck('employee_id')
                ->unique()
                ->reject(fn($id) => $presentEmployeeIds->contains($id)); // Exclude present employees

            $presentData[] = $presentEmployeeIds->count();
            $absentData[] = $absentEmployeeIds->count();

            $leaveData[] = $attendances->where('status', 'on_leave')
                ->pluck('employee_id')
                ->unique()
                ->count();

            $offShiftData[] = $attendances->where('status', 'off_shift')
                ->pluck('employee_id')
                ->unique()
                ->count();
        }

        $this->chartData = [
            'categories' => $categories,
            'series' => [
                ['name' => 'Present', 'data' => $presentData],
                ['name' => 'Absent', 'data' => $absentData],
                ['name' => 'Leave', 'data' => $leaveData],
                ['name' => 'Off Shift', 'data' => $offShiftData],
            ]
        ];
    }

    /* ─────────────────────────────────────────────
       Re-run the daily pie chart stats when the
       date filter changes, then push fresh data to
       the chart via a browser event (no full reload)
    ───────────────────────────────────────────── */
    private function loadDailyStatus(): void
    {
        $date = Carbon::parse($this->selectedDate)->startOfDay();
        $this->isToday = $date->isToday();
        $this->selectedDateLabel = $date->format('d M');

        $attendancesForDate = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $this->orgId))
            ->whereDate('date', $date)
            ->get();

        $this->statusData = [
            'present' => $attendancesForDate->whereIn('status', ['clocked_in', 'clocked_out'])->count(),
            'absent' => $attendancesForDate->whereIn('status', ['absent', 'unchecked_in'])->count(),
            'onLeave' => $attendancesForDate->where('status', 'on_leave')->count(),
            'offShift' => $attendancesForDate->where('status', 'off_shift')->count(),
        ];
    }

    public function updatedSelectedDate(): void
    {
        $this->loadDailyStatus();
        $this->loadMonthlyData();
        $this->loadWeeklyDepartmentData();

        $this->dispatch('daily-status-updated', statusData: $this->statusData, title: $this->isToday
            ? 'Daily Attendance Status'
            : 'Attendance Status - ' . $this->selectedDateLabel);

        $this->dispatch('monthly-data-updated', monthlyData: $this->monthlyData, monthLabel: $this->monthLabel);

        $this->dispatch('weekly-department-updated', chartData: $this->chartData, weekLabel: $this->weekLabel);
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

        {{-- ══════════════════════════════════════════
             DATE FILTER  (drives the highlight cards)
        ══════════════════════════════════════════ --}}
        <div class="card shadow-sm mb-3" x-data x-init="$nextTick(() => initOrgReportDatepicker())">
            <div class="card-body d-flex align-items-center gap-3 flex-wrap py-2">
                <iconify-icon icon="mdi:calendar-month" style="font-size:20px; color:#64748b;"></iconify-icon>
                <input type="text" id="orgReportSelectedDate" class="form-control" style="max-width:180px;"
                       wire:model.live="selectedDate" value="{{ $selectedDate }}" autocomplete="off"/>
                <span class="text-muted small">
                    Showing data for {{ \Carbon\Carbon::parse($selectedDate)->format('d M Y') }}
                </span>
            </div>
        </div>

        <livewire:admin.summaries.employee-statuses :selected-date="$selectedDate" :key="'org-emp-statuses-'.$selectedDate"/>
    </div>

    <div class="col-lg-12 mt-4">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title" id="monthly-attendance-title">Monthly {{ $entityLabel }} Attendance Timeline - {{ $monthLabel }}</h4>
                <div id="monthly-employee-attendance" class="ms-n3"></div>
            </div>
        </div>
    </div>

    <div class="row d-flex align-items-stretch mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-4" id="weekly-department-title">Weekly Attendance by Unit ({{ $weekLabel }})</h5>
                    <div id="department-weekly-data"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h4 class="card-title" id="daily-attendance-title">{{ $isToday ? 'Daily Attendance Status' : 'Attendance Status - ' . $selectedDateLabel }}</h4>
                    <div id="daily-attendance"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row d-flex align-items-stretch">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-4">Weekly Departmental Overtime Hours</h5>
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
        function initOrgReportDatepicker() {
            const $input = $('#orgReportSelectedDate');

            if (typeof $.fn.datepicker === 'undefined' || !$input.length) {
                return;
            }

            const setLivewireDateValue = (input, value) => {
                const componentRoot = input.closest('[wire\\:id]');
                const componentId = componentRoot?.getAttribute('wire:id');

                if (!componentId || !window.Livewire || typeof window.Livewire.find !== 'function') {
                    return;
                }

                const component = window.Livewire.find(componentId);
                if (component && typeof component.set === 'function') {
                    component.set('selectedDate', value);
                }
            };

            if ($input.data('datepicker')) {
                $input.datepicker('destroy');
            }

            const currentValue = $input.val();

            $input.datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true,
                endDate: new Date(),
            }).on('changeDate', function (e) {
                const selected = e.format('yyyy-mm-dd');
                $input.val(selected).trigger('input').trigger('change');
                setLivewireDateValue($input[0], selected);
            });

            if (currentValue) {
                $input.datepicker('update', currentValue);
            }
        }

        document.addEventListener('livewire:navigated', initOrgReportDatepicker);
        initOrgReportDatepicker();

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

            // Update the pie chart in place when the date filter changes
            document.addEventListener('livewire:init', () => {
                Livewire.on('daily-status-updated', (event) => {
                    const payload = Array.isArray(event) ? event[0] : event;
                    chart_pie_simple.updateSeries(Object.values(payload.statusData));

                    const titleEl = document.getElementById('daily-attendance-title');
                    if (titleEl) titleEl.textContent = payload.title;
                });
            });

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

            // Update the monthly timeline chart in place when the date filter changes
            document.addEventListener('livewire:init', () => {
                Livewire.on('monthly-data-updated', (event) => {
                    const payload = Array.isArray(event) ? event[0] : event;
                    const d = payload.monthlyData;

                    chart.updateSeries([
                        {name: "Present", data: d.map(x => ({x: new Date(x.date).toISOString(), y: x.present}))},
                        {name: "Absent", data: d.map(x => ({x: new Date(x.date).toISOString(), y: x.absent}))},
                        {name: "Leave", data: d.map(x => ({x: new Date(x.date).toISOString(), y: x.leave}))},
                        {name: "Off Shift", data: d.map(x => ({x: new Date(x.date).toISOString(), y: x.off_shift}))},
                    ]);

                    const titleEl = document.getElementById('monthly-attendance-title');
                    if (titleEl) titleEl.textContent = 'Monthly {{ $entityLabel }} Attendance Timeline - ' + payload.monthLabel;
                });
            });


            // Department Weekly Data (Stacked Bar Chart)
            const chartData = @json($chartData);

            const options_stacked = {
                series: chartData.series, // Now contains 4 series
                chart: {
                    fontFamily: "inherit",
                    type: "bar",
                    height: 400,
                    stacked: true,
                    toolbar: {show: false},
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
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

            // Update the weekly departmental chart in place when the date filter changes
            document.addEventListener('livewire:init', () => {
                Livewire.on('weekly-department-updated', (event) => {
                    const payload = Array.isArray(event) ? event[0] : event;

                    dchart.updateOptions({xaxis: {categories: payload.chartData.categories}});
                    dchart.updateSeries(payload.chartData.series);

                    const titleEl = document.getElementById('weekly-department-title');
                    if (titleEl) titleEl.textContent = 'Weekly Attendance by Unit (' + payload.weekLabel + ')';
                });
            });

            // Overtime Chart and Top Employees data remain the same
            const overtimeChartData = @json($overtimeChartData);

            const overtimeOptions = {
                series: overtimeChartData.series,
                chart: {
                    fontFamily: "inherit",
                    type: "bar",
                    height: 400,
                    toolbar: {show: false},
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
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




