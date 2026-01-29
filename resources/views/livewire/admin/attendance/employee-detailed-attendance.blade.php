<?php

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AttendanceDailyExcelExport;

new class extends Component {
    public $employeeId;
    public $employee;
    public $attendanceRate = 0;
    public $totalDays = 0;
    public $presentDays = 0;
    public $daysAbsent = 0;
    public $lateArrivals = 0;
    public $averageLateMinutes = 0;
    public $attendanceHistory = [];
    public $dateFilter = '7'; // 7, 30, or 90 days

    public function mount($employeeId)
    {
        $this->employeeId = $employeeId;
        $this->loadEmployeeData();
        $this->loadAttendanceData();
    }

    public function loadEmployeeData()
    {
        $this->employee = Employee::with('department')
            ->findOrFail($this->employeeId);
    }

    public function loadAttendanceData()
    {
        $days = (int)$this->dateFilter;
        $startDate = Carbon::now()->subDays($days);
        $endDate = Carbon::now();

        // Get attendance records
        $attendances = Attendance::where('employee_id', $this->employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderByDesc('date')
            ->get();

        $this->totalDays = $days;
        $this->presentDays = $attendances->whereIn('status', ['clocked_in', 'clocked_out'])->count();
        $this->daysAbsent = $attendances->whereIn('status', ['absent', 'unchecked_in'])->count();

        // Calculate attendance rate
        if ($this->totalDays > 0) {
            $this->attendanceRate = round(($this->presentDays / $this->totalDays) * 100);
        }

        // Late arrivals
        $lateRecords = $attendances->where('is_late_checkin', true);
        $this->lateArrivals = $lateRecords->count();

        if ($this->lateArrivals > 0) {
            $totalLateMinutes = $lateRecords->sum('minutes_late');
            $this->averageLateMinutes = round($totalLateMinutes / $this->lateArrivals);
        }

        // Attendance history
        $this->attendanceHistory = $attendances->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'date' => Carbon::parse($attendance->date)->format('M d, Y'),
                'check_in' => $attendance->check_in_time ? Carbon::parse($attendance->check_in_time)->format('g:i A') : null,
                'check_out' => $attendance->check_out_time ? Carbon::parse($attendance->check_out_time)->format('g:i A') : null,
                'locations' => $attendance->employee->assignments
                    ->load('location')
                    ->pluck('location.name')
                    ->filter()
                    ->unique(),
                'total_hours' => $this->formatHours($attendance->worked_hours ?? 0),
                'status' => $attendance->status,
                'is_late' => $attendance->is_late_checkin ?? false,
            ];
        })->toArray();
    }

    public function formatHours($hours)
    {
        return $hours . 'h ';
    }

    public function updatedDateFilter()
    {
        $this->loadAttendanceData();
    }

    public function getStatusBadgeClass($status)
    {
        return match ($status) {
            'clocked_in', 'clocked_out', 'uncheked_in' => 'present',
            'absent', 'unchecked_in' => 'absent',
            'offshift', => 'off_shift',
            'sickoff', => 'sick_off',
            'on_leave', => 'on_leave',
            'not_scheduled', => 'not_scheduled',
            default => 'absent'
        };
    }

    public function getStatusText($status, $isLate)
    {
        if ($isLate) {
            return 'Late';
        }
        return match ($status) {
            'clocked_in', 'clocked_out', 'uncheked_in' => 'Present',
            'absent', 'unchecked_in' => 'Absent',
            'offshift', => 'Off Shift',
            'sickoff', => 'Sick Off',
            'on_leave', => 'On Leave',
            'not_scheduled', => 'Not Scheduled',
            default => 'Absent'
        };
    }


    private function getAttendanceIds()
    {
        $days = (int)$this->dateFilter;
        $startDate = Carbon::now()->subDays($days);
        $endDate = Carbon::now();

        return Attendance::where('employee_id', $this->employeeId)  // Find by employee
        ->whereBetween('date', [$startDate, $endDate])
            ->pluck('id')  // Get attendance record IDs
            ->toArray();
    }


    /**
     * Export to Excel
     */
    #[On('export-employee-excel')]
    public function exportExcel()
    {
        $days = (int)$this->dateFilter;
        $startDate = Carbon::now()->subDays($days);
        $endDate = Carbon::now();

        $attendanceIds = $this->getAttendanceIds();

        return Excel::download(
            new AttendanceDailyExcelExport(
                selectedIds: $attendanceIds, // Employee ID array
                startDate: $startDate->format('Y-m-d'),
                endDate: $endDate->format('Y-m-d'),
                status: ''
            ),
            "attendance_{$this->employee->name}_{$startDate->format('Y-m-d')}_to_{$endDate->format('Y-m-d')}.xlsx"
        );
    }


    #[On('export-employee-pdf')]
    public function exportPdf()
    {
        try {
            $days = (int)$this->dateFilter;
            $startDate = Carbon::now()->subDays($days);
            $endDate = Carbon::now();

            // Get attendance IDs
            $attendanceIds = $this->getAttendanceIds(); // ✅ Gets attendance IDs

            if (empty($attendanceIds)) {
                session()->flash('error', 'No attendance records found for this period.');
                return;
            }

            $url = route('attendance-daily.export.pdf', [
                'ids' => $attendanceIds, // ✅ Sends attendance IDs
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'status' => null,
            ]);

            return redirect()->to($url);

        } catch (\Exception $e) {
            \Log::error('PDF export failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'Failed to export PDF. Please try again.');
        }
    }

    /**
     * Legacy export report method (dispatches dropdown toggle)
     */
    public function exportReport()
    {
        // Dispatch event to toggle dropdown or show export options
        $this->dispatch('toggle-export-dropdown');
    }
}; ?>

@push('styles')
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1a1a1a;
        }

        .container-custom {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Header */
        .header-section {
            margin-bottom: 32px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 16px;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #334155;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }

        .employee-info h1 {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .department-badge {
            display: inline-block;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        /* Export Button Dropdown */
        .export-dropdown {
            position: relative;
        }

        .export-btn {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            min-width: 160px;
            z-index: 1000;
            display: none;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-menu li {
            list-style: none;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            color: #334155;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
            cursor: pointer;
        }

        .dropdown-item:hover {
            background: #f8fafc;
        }

        .dropdown-item:first-child {
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }

        .dropdown-item:last-child {
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        /* Stats Grid - 2 columns like your image */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
            line-height: 1;
        }

        .stat-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .stat-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon-wrapper.success {
            background: #dcfce7;
        }

        .stat-icon-wrapper.danger {
            background: #fee2e2;
        }

        .stat-icon-wrapper.warning {
            background: #fef3c7;
        }

        .stat-icon-wrapper svg {
            width: 22px;
            height: 22px;
        }

        .stat-icon-wrapper.success svg {
            color: #16a34a;
        }

        .stat-icon-wrapper.danger svg {
            color: #dc2626;
        }

        .stat-icon-wrapper.warning svg {
            color: #d97706;
        }

        /* Attendance History */
        .history-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .history-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }

        .date-filter {
            display: flex;
            gap: 8px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 4px;
        }

        .filter-btn {
            background: transparent;
            border: none;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn.active {
            background: white;
            color: #3b82f6;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        /* Table */
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table thead {
            border-bottom: 2px solid #f1f5f9;
        }

        .attendance-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }

        .attendance-table tbody tr:hover {
            background: #f8fafc;
        }

        .attendance-table td {
            padding: 16px;
            font-size: 14px;
            color: #334155;
        }

        .time-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .time-icon {
            width: 16px;
            height: 16px;
            color: #94a3b8;
        }

        .location-cell {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .location-icon {
            width: 16px;
            height: 16px;
            color: #94a3b8;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.present {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.absent, .status-badge.off_shift, .status-badge.sick_off, .status-badge.on_leave, .status-badge.not_scheduled {
            background: #fee2e2;
            color: #991b1b;
        }

        .date-cell {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .calendar-icon {
            width: 16px;
            height: 16px;
            color: #94a3b8;
        }

        .no-data {
            text-align: center;
            padding: 48px 24px;
            color: #94a3b8;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .attendance-table {
                font-size: 13px;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 12px 8px;
            }

            .history-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Toggle export dropdown
        document.addEventListener('livewire:init', () => {
            Livewire.on('toggle-export-dropdown', () => {
                const dropdown = document.getElementById('exportDropdown');
                if (dropdown) {
                    dropdown.classList.toggle('show');
                }
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            const dropdown = document.getElementById('exportDropdown');
            const exportBtn = document.getElementById('exportButton');

            if (dropdown && exportBtn && !exportBtn.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
    </script>
@endpush

<div class="container-custom">
    <!-- Header -->
    <div class="header-section">
        <a href="{{ route('attendance.index') }}" class="back-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Back to Attendance
        </a>

        <div class="header-content">
            <div class="employee-info">
                <h1>{{ $employee->name }}</h1>
                <span class="department-badge">{{ $employee->department->name ?? 'No Department' }}</span>
            </div>

            <!-- Export Dropdown -->
            <div class="export-dropdown">
                <button wire:click="exportReport" class="export-btn" id="exportButton">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export Report
                </button>

                <ul class="dropdown-menu" id="exportDropdown">
                    <li>
                        <a href="#"
                           wire:click.prevent="$dispatch('export-employee-excel')"
                           class="dropdown-item">
                            <iconify-icon icon="mdi:file-excel" width="16" height="16"
                                          style="margin-right:8px;"></iconify-icon>
                            Excel
                        </a>
                    </li>
                    <li>
                        <a href="#"
                           wire:click.prevent="$dispatch('export-employee-pdf')"
                           class="dropdown-item">
                            <iconify-icon icon="mdi:file-pdf-box" width="16" height="16"
                                          style="margin-right:8px;"></iconify-icon>
                            PDF
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Stats Grid - 2 columns slim cards -->
    <div class="stats-grid">
        <!-- Attendance Rate -->
        <div class="stat-card">
            <div class="stat-content">
                <div class="stat-label">Attendance Rate</div>
                <div class="stat-value">{{ $attendanceRate }}%</div>
                <div class="stat-subtitle">{{ $presentDays }} of {{ $totalDays }} days</div>
            </div>
            <div class="stat-icon-wrapper success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
        </div>

        <!-- Days Absent -->
        <div class="stat-card">
            <div class="stat-content">
                <div class="stat-label">Days Absent</div>
                <div class="stat-value">{{ $daysAbsent }}</div>
                <div class="stat-subtitle">Last {{ $totalDays }} days</div>
            </div>
            <div class="stat-icon-wrapper danger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
        </div>

        <!-- Late Arrivals -->
        <div class="stat-card">
            <div class="stat-content">
                <div class="stat-label">Late Arrivals</div>
                <div class="stat-value">{{ $lateArrivals }}</div>
                <div class="stat-subtitle">Average
                    hours: {{ $averageLateMinutes > 0 ? floor($averageLateMinutes / 60) . 'h ' . ($averageLateMinutes % 60) . 'm' : '0m' }}</div>
            </div>
            <div class="stat-icon-wrapper warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
        </div>
    </div>

    <!-- Attendance History -->
    <div class="history-section">
        <div class="history-header">
            <h2 class="history-title">Attendance History</h2>

            <div class="date-filter">
                <button
                    wire:click="$set('dateFilter', '7')"
                    class="filter-btn {{ $dateFilter === '7' ? 'active' : '' }}">
                    7 Days
                </button>
                <button
                    wire:click="$set('dateFilter', '30')"
                    class="filter-btn {{ $dateFilter === '30' ? 'active' : '' }}">
                    30 Days
                </button>
                <button
                    wire:click="$set('dateFilter', '90')"
                    class="filter-btn {{ $dateFilter === '90' ? 'active' : '' }}">
                    90 Days
                </button>
            </div>
        </div>

        @if(count($attendanceHistory) > 0)
            <table class="attendance-table">
                <thead>
                <tr>
                    <th>DATE</th>
                    <th>CLOCK IN</th>
                    <th>CLOCK OUT</th>
                    <th>LOCATION</th>
                    <th>TOTAL HOURS</th>
                    <th>STATUS</th>
                </tr>
                </thead>
                <tbody>
                @foreach($attendanceHistory as $record)
                    <tr>
                        <td>
                            <div class="date-cell">
                                <svg class="calendar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                {{ $record['date'] }}
                            </div>
                        </td>
                        <td>
                            @if($record['check_in'])
                                <div class="time-cell">
                                    <svg class="time-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    {{ $record['check_in'] }}
                                </div>
                            @else
                                <svg class="time-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <span style="color: #94a3b8;">-</span>
                            @endif
                        </td>
                        <td>
                            @if($record['check_out'])
                                <div class="time-cell">
                                    <svg class="time-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    {{ $record['check_out'] }}
                                </div>
                            @else
                                <svg class="time-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <span style="color: #94a3b8;">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="location-cell d-flex flex-wrap">
                                @foreach ($record['locations'] as $loc)
                                    <span class="badge bg-primary-subtle text-primary border px-2 py-1 me-1 mb-1">
                                          <i class="ti ti-map-pin me-1"></i>{{ $loc }}
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td>{{ $record['total_hours'] }}</td>
                        <td>
                                <span
                                    class="status-badge {{ $this->getStatusBadgeClass($record['status']) }} {{ $record['is_late'] ? 'late' : '' }}">
                                    {{ $this->getStatusText($record['status'], $record['is_late']) }}
                                </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <div class="no-data">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                     style="margin: 0 auto 16px; opacity: 0.3;">
                    <path d="M9 11l3 3L22 4"/>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                <p>No attendance records found for this period</p>
            </div>
        @endif
    </div>
</div>
