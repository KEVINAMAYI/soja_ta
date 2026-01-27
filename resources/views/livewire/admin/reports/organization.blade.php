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

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;

        // --- 1. Monthly Attendance Data ---
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();
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


        // --- 2. Department Weekly Data (Chart Data) ---
        $startOfWeek = Carbon::now()->startOfWeek(); // Monday
        $endOfWeek = Carbon::now()->endOfWeek();     // Sunday

        $departments = Department::where('organization_id', $orgId)->get();

        $categories = [];
        $presentData = [];
        $absentData = [];
        $leaveData = [];
        $offShiftData = []; // <-- NEW SERIES

        foreach ($departments as $dept) {
            $categories[] = $dept->name;

            $attendances = Attendance::whereBetween('date', [$startOfWeek, $endOfWeek])
                ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
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
                ['name' => 'Off Shift', 'data' => $offShiftData], // <-- ADDED SERIES
            ]
        ];


        // --- 3. Daily Attendance Status (Pie Chart Data) ---
        $today = Carbon::today();

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


        <livewire:admin.summaries.employee-statuses/>
    </div>

    <div class="col-lg-12 mt-4">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Monthly Attendance Timeline</h4>
                <div id="monthly-employee-attendance" class="ms-n3"></div>
            </div>
        </div>
    </div>

    <div class="row d-flex align-items-stretch mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-4">Weekly Departmental Attendance</h5>
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
                    <h5 class="card-title mb-4">Weekly Departmental Overtime Hours</h5>
                    <div id="department-weekly-overtime"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm recent-activity-card">
                <div class="card-header">
                    <h5 class="mb-0">Top 5 Overtime Employees (This Week)</h5>
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
                        text: "Employee Count"
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
                            return val + " employees";
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




