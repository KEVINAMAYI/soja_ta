<?php

use App\Models\WorkLocation;
use Livewire\Volt\Component;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

new class extends Component {

    public $totalEmployees = 0;
    public $presentToday = 0;
    public $lateArrivals = 0;
    public $overtimeHours = 0;
    public $departmentStats = [];
    public $recentActivities = [];
    public $currentEmployeeStatus = [];
    public $employeeLocations = [];
    public $googleMapsApiKey;
    public $workLocations = [];

    public function mount()
    {
        $today = Carbon::today();
        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

        // Determine organization of logged-in user
        $employeeRecord = Employee::where('user_id', auth()->id())->first();
        $orgId = $employeeRecord->organization_id;

        $employees = Employee::where('organization_id', $orgId)->get();
        $this->totalEmployees = $employees->count();
        $employeeIds = $employees->pluck('id');

        // Attendances today
        $attendancesToday = Attendance::whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->get();

        $this->presentToday = $attendancesToday->whereNotNull('check_in_time')->count();

        // Late arrivals
        $this->lateArrivals = $attendancesToday->where('status', 'late')->count();

        // Overtime hours this week
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $this->overtimeHours = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->sum('overtime_hours');

        // Department stats
        $this->departmentStats = $employees->groupBy('department_id')->map(function ($group) {
            $clockedIn = Attendance::whereIn('employee_id', $group->pluck('id'))
                ->whereDate('date', Carbon::today())
                ->whereNotNull('check_in_time')
                ->count();
            $total = $group->count();
            return [
                'name' => $group[0]->department->name ?? 'Unknown',
                'clocked_in' => $clockedIn,
                'total' => $total,
            ];
        });

        // Recent activities (today only, last 5)
        $this->recentActivities = $attendancesToday
            ->sortByDesc('created_at')
            ->take(5);

        // Current employee status
        $this->currentEmployeeStatus = Attendance::with('employee.department')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->orderBy('check_in_time', 'desc')
            ->take(5)
            ->get()
            ->map(fn($att) => [
                'name' => $att->employee->name,
                'department' => $att->employee->department->name ?? 'N/A',
                'status' => $att->status,
                'clock_in' => $att->check_in_time
                    ? Carbon::parse($att->check_in_time)->format('h:i A')
                    : 'N/A',
                'hours_today' => $att->worked_hours ?? 0,
                'view_link' => route('attendance.index')
            ]);

        // Employee locations
        $this->employeeLocations = Attendance::with('employee.department', 'employee.currentAssignment.location')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->whereNotNull('check_in_time')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('check_in_time', 'desc')
            ->get()
            ->groupBy('employee_id')
            ->map(function ($group) use ($employeeRecord) {
                $last = $group->first();
                $workLocation = $last->employee->currentAssignment?->location;

                return [
                    'name' => $last->employee->name,
                    'department' => $last->employee->department->name ?? 'N/A',
                    'clock_in' => Carbon::parse($last->check_in_time)->format('h:i A'),
                    'lat' => $last->latitude,
                    'lng' => $last->longitude,
                    'work_location_id' => $workLocation?->id
                        ?? $employeeRecord->organization->location?->id
                            ?? null,
                ];
            })
            ->values()
            ->toArray();

        // Work locations with id
        $this->workLocations = WorkLocation::where('organization_id', $orgId)
            ->where('active', true)
            ->get()
            ->map(function ($loc) {
                return [
                    'id' => $loc->id, // 🔹 fix added
                    'name' => $loc->name,
                    'lat' => $loc->latitude,
                    'lng' => $loc->longitude,
                    'radius_m' => $loc->radius_m,
                    'address' => $loc->address,
                ];
            })
            ->toArray();
    }


}; ?>

@push('styles')
    <style>

        .department-overview-title {
            font-size: 18px;
            font-weight: bold;
            color: #000;
        }

        .department-overview-card,
        .recent-activity-card {
            border-radius: 12px;
        }

        .department-overview-title, .map-title, .quick-actions-title {
            font-weight: bold;
            font-size: 18px;
            color: #000; /* black */
        }

        .recent-activity-title, .map-title, .quick-actions-title {
            font-weight: bold;
            font-size: 14px;
            color: #000; /* black */
        }

        .card-action {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            color: #000;
            background: #fff;
            border: 2px dotted #333; /* dotted border on small cards */
            border-radius: 8px;
            padding: 15px;
            transition: all 0.2s ease-in-out;
            font-weight: 500;
            height: 100%;
            text-align: center;
        }

        .card-action:hover {
            background: #f5f5f5;
            transform: scale(1.05);
        }

        .recent-activity-card {
            border-radius: 12px;
        }

        .activity-item {
            background: linear-gradient(135deg, #f9fbff, #eef4ff);
            border-radius: 12px;
            padding: 12px;
            transition: 0.2s;
            box-shadow: 0 2px 6px rgba(74, 108, 247, 0.08);
        }

        .activity-item:hover {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            box-shadow: 0 4px 10px rgba(74, 108, 247, 0.15);
        }

        .icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dbe4ff, #edf2ff);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4a6cf7;
            font-size: 20px;
            box-shadow: 0 2px 5px rgba(74, 108, 247, 0.2);
        }

        .status {
            font-weight: 500;
            text-transform: lowercase;
        }

        .stat-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
        }

        .stat-text {
            text-align: left;
        }

        .stat-icon {
            font-size: 36px;
            padding: 12px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-blue {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .icon-green {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .icon-red {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .icon-orange {
            background: rgba(251, 146, 60, 0.1);
            color: #fb923c;
        }

    </style>
@endpush


<div class="row g-3">

    <!-- Total Employees -->
    <div class="col-lg-3 col-6">
        <div class="card shadow-sm h-100">
            <div class="stat-card">
                <div class="stat-text">
                    <h6 class="text-muted mb-1">Total Employees</h6>
                    <h3 class="fw-bold text-dark">{{ $totalEmployees }}</h3>
                    <small style="visibility:hidden;" class="text-muted">Everyone in</small>
                </div>
                <div class="stat-icon icon-green">
                    <span class="iconify" data-icon="mdi:account-check"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Present Today -->
    <div class="col-lg-3 col-6">
        <div class="card shadow-sm h-100">
            <div class="stat-card">
                <div class="stat-text">
                    <h6 class="text-muted mb-1">Present Today</h6>
                    <h3 class="fw-bold text-success">{{ $presentToday }}</h3>
                    <small class="text-muted">{{ number_format(($presentToday / $totalEmployees) * 100, 2) }}%
                        attendance</small>
                </div>
                <div class="stat-icon icon-green">
                    <span class="iconify" data-icon="mdi:account-check"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Late Arrivals -->
    <div class="col-lg-3 col-6">
        <div class="card shadow-sm h-100">
            <div class="stat-card">
                <div class="stat-text">
                    <h6 class="text-muted mb-1">Late Arrivals</h6>
                    <h3 class="fw-bold text-danger">{{ $lateArrivals }}</h3>
                    <small class="text-muted">Today</small>
                </div>
                <div class="stat-icon icon-red">
                    <span class="iconify" data-icon="mdi:alert-circle-outline"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Overtime Hours -->
    <div class="col-lg-3 col-6">
        <div class="card shadow-sm h-100">
            <div class="stat-card">
                <div class="stat-text">
                    <h6 class="text-muted mb-1">Overtime Hours</h6>
                    <h3 class="fw-bold text-warning">{{ $overtimeHours }}</h3>
                    <small class="text-muted">This week</small>
                </div>
                <div class="stat-icon icon-orange">
                    <span class="iconify" data-icon="mdi:chart-line"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Map + Quick Actions -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header map-title fw-semibold">Live Map</div>
            <div class="card-body p-0">
                <div id="map" wire:ignore style="height: 300px; width:100%;"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header quick-actions-title">
                Quick Actions
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Manual Check-In -->
                    <div class="col-6">
                        <a href="#" class="card-action">
                            <span class="iconify mb-2" data-icon="mdi:account-check-outline"
                                  style="font-size: 28px;"></span>
                            <span>Manual Check-in</span>
                        </a>
                    </div>

                    <!-- Schedule Override -->
                    <div class="col-6">
                        <a href="#" class="card-action">
                            <span class="iconify mb-2" data-icon="mdi:calendar-clock-outline"
                                  style="font-size: 28px;"></span>
                            <span>Schedule Override</span>
                        </a>
                    </div>

                    <!-- Export Reports -->
                    <div class="col-6">
                        <a href="#" class="card-action">
                            <span class="iconify mb-2" data-icon="mdi:file-download-outline"
                                  style="font-size: 28px;"></span>
                            <span>Export Reports</span>
                        </a>
                    </div>

                    <!-- System Settings -->
                    <div class="col-6">
                        <a href="#" class="card-action">
                            <span class="iconify mb-2" data-icon="mdi:cog-outline" style="font-size: 28px;"></span>
                            <span>System Settings</span>
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Department Overview -->
    <div class="col-lg-8 d-flex">
        <div class="card shadow-sm flex-fill">
            <div class="card-header department-overview-title fw-semibold">
                Department Overview
            </div>
            <div class="card-body">
                @foreach ($departmentStats as $dept)
                    @php
                        $perc = $dept['total'] ? round(($dept['clocked_in'] / $dept['total']) * 100) : 0;
                    @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small fw-semibold">
                            <span>{{ $dept['name'] }}</span>
                            <span>{{ $dept['clocked_in'] }}/{{ $dept['total'] }} ({{ $perc }}%)</span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar bg-primary" style="width: {{ $perc }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-lg-4 d-flex">
        <div class="card shadow-sm recent-activity-card flex-fill">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold recent-activity-title">Recent Activity</span>
                <div>
                    <select class="form-select form-select-sm d-inline-block w-auto me-2">
                        <option>All Activities</option>
                        <option>Clock In</option>
                        <option>Clock Out</option>
                    </select>
                    <button class="btn btn-primary btn-sm">
                        <span class="iconify" data-icon="mdi:filter-variant"></span> Filter
                    </button>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">

                    <!-- Recent Activities -->
                    @foreach ($recentActivities as $activity)
                        <li class="activity-item d-flex align-items-center mb-3">
                            <div class="icon-wrap">
                                <span class="iconify" data-icon="mdi:clock-outline"></span>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="fw-semibold">{{ $activity->employee->name }}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold">
                                    @php
                                        $time = $activity->check_out_time ?? $activity->check_in_time;
                                    @endphp
                                    {{ $time ? Carbon::parse($time)->format('h:i A') : 'N/A' }}
                                </div>

                                <div class="status small
                                @switch($activity->status)
                                   @case('clocked_in') text-success @break
                                   @case('clocked_out') text-danger @break
                                   @case('unchecked_in') text-secondary @break
                                   @case('absent') text-warning @break
                                   @default text-muted
                                @endswitch
                                 ">
                                    {{ ucfirst(str_replace('_', ' ', $activity->status)) }}
                                </div>
                            </div>
                        </li>
                    @endforeach

                </ul>
            </div>
        </div>
    </div>


    <!-- Current Employee Status -->
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Recent Entries</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Clock In</th>
                        <th>Status</th>
                        <th>Hours Worked</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($currentEmployeeStatus as $emp)
                        <tr>
                            <td>{{ $emp['name'] }}</td>
                            <td>{{ $emp['department'] }}</td>
                            <td>{{ $emp['clock_in'] }}</td>
                            <td>
                                @php
                                    $badgeClass = match($emp['status']) {
                                        'present' => 'bg-success',
                                        'clocked_out' => 'bg-danger',
                                        'absent' => 'bg-warning text-dark',
                                        'unchecked_in' => 'bg-secondary text-dark', // new status
                                        default => 'bg-secondary'
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">
                                 {{ ucfirst(str_replace('_', ' ', $emp['status'])) }}
                                </span>
                            </td>
                            <td>{{ $emp['hours_today'] }}</td>
                            <td>
                                <a href="{{ $emp['view_link'] }}" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>


@push('scripts')
    <script>
        const workLocations = @json($workLocations);
        const employeeLocations = @json($employeeLocations);

        function initMap() {
            const map = new google.maps.Map(document.getElementById("map"), {
                zoom: 12,
                center: {lat: -1.2921, lng: 36.8219}, // Nairobi fallback
            });

            const bounds = new google.maps.LatLngBounds();
            let activeInfoWindow = null;

            // --- Draw red geofence ---
            function drawGeofence(position, radius) {
                new google.maps.Circle({
                    strokeColor: "#FF0000",
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: "#FF0000",
                    fillOpacity: 0.15,
                    map,
                    center: position,
                    radius: radius,
                });
            }

            // --- Add marker with info window ---
            function addMarker(position, infoContent) {
                const marker = new google.maps.Marker({
                    position,
                    map,
                });

                if (infoContent) {
                    const infoWindow = new google.maps.InfoWindow({content: infoContent});
                    marker.addListener("click", () => {
                        if (activeInfoWindow) activeInfoWindow.close();
                        infoWindow.open(map, marker);
                        activeInfoWindow = infoWindow;
                    });
                }
                return marker;
            }

            // --- Group employees by work_location_id ---
            const groupedEmployees = {};
            employeeLocations.forEach(emp => {
                if (emp.work_location_id) {
                    if (!groupedEmployees[emp.work_location_id]) {
                        groupedEmployees[emp.work_location_id] = [];
                    }
                    groupedEmployees[emp.work_location_id].push(emp);
                }
            });

            // --- Process each work location ---
            workLocations.forEach(loc => {
                if (loc.lat && loc.lng) {
                    const position = {lat: parseFloat(loc.lat), lng: parseFloat(loc.lng)};
                    bounds.extend(position);
                    drawGeofence(position, loc.radius_m);

                    const emps = groupedEmployees[loc.id] || [];

                    let infoContent = `
                   <div style="background:#fff; padding:12px; border-radius:6px;
                               box-shadow:0 2px 6px rgba(0,0,0,0.15); min-width:220px;">
                       <div style="font-weight:700; font-size:16px; color:#333;">
                           ${loc.name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                       </div>
                       <div style="font-size:13px; color:#555; margin-bottom:8px;">
                           ${loc.address || 'No address provided'}
                       </div>`;

                    if (emps.length > 0) {
                        infoContent += `<div style="font-size:14px; color:#222; margin-bottom:6px;">
                        <b>${emps.length} Employee${emps.length > 1 ? 's' : ''}</b> checked in:
                    </div>`;
                        emps.forEach(e => {
                            infoContent += `<div style="font-size:13px; color:#444; margin-bottom:3px;">
                            <b>${e.name}</b> (${e.department}) - Clock In: ${e.clock_in}
                        </div>`;
                        });
                    }

                    infoContent += `</div>`;

                    addMarker(position, infoContent);
                }
            });

            // --- Fit map bounds ---
            if (!bounds.isEmpty()) {
                map.fitBounds(bounds);
            } else {
                map.setCenter({lat: -1.2921, lng: 36.8219});
                map.setZoom(12);
            }
        }
    </script>

    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&callback=initMap">
    </script>
@endpush




