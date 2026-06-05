<?php

use Livewire\Volt\Component;
use App\Models\Employee;
use App\Models\Attendance;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {

    public $chartData = [];
    public $chartLabels = [];

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;
        $today = Carbon::today();

        // Total employees in the organization (for reference if needed)
        $totalEmployees = Employee::where('organization_id', $orgId)->count();

        // Attendance statuses for today
        $attendances = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId)
        )
            ->whereDate('date', $today)
            ->get();

        $present = $attendances->where('status', 'Present')->count();
        $absent = $attendances->where('status', 'Absent')->count();
        $onLeave = $attendances->where('status', 'Leave')->count();

        // Chart data
        $this->chartData = [$present, $absent, $onLeave];
        $this->chartLabels = ['Present', 'Absent', 'On Leave'];
    }

}; ?>

<div class="col-lg-4">
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-4">Daily Attendance</h5>
            <div class="bg-primary bg-opacity-10 rounded-1 overflow-hidden mb-4">
                <div id="chart-pie-simple"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        document.addEventListener("livewire:load", function () {

            const chartData = @json($chartData);
            const chartLabels = @json($chartLabels);

            var chart_pie_simple = new ApexCharts(
                document.querySelector("#chart-pie-simple"),
                {
                    series: chartData,
                    chart: { type: "pie", width: 380, fontFamily: "inherit" },
                    colors: ["#28a745", "#dc3545", "#ffc107"], // Green = Present, Red = Absent, Yellow = On Leave
                    labels: chartLabels,
                    legend: { labels: { colors: "#a1aab2" } },
                }
            );

            chart_pie_simple.render();
        });
    </script>
@endpush





