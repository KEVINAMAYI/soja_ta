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
use App\Services\AttendanceSeeder;
use Illuminate\Support\Facades\DB;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;
    public $status;
    public $startDate;
    public $endDate;

    protected AttendanceSeeder $seeder;


    public function mount($status = null)
    {
        $this->status = $status;
        $this->startDate = now()->toDateString();
        $this->endDate = now()->toDateString();
    }


    #[On('date-range-updated')]
    public function filterByDateRange($startDate, $endDate, $status)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->status = $status;

        $orgId = auth()->user()->employee->organization_id ?? null;

        if (in_array($this->status, ['unchecked_in', 'absent', 'on_leave', 'off_shift', 'sick_off'])) {
            app(AttendanceSeeder::class)->seedMissingAttendanceRecords($orgId);
        }

        $this->dispatch('refreshDatatable');
    }


    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $startDate = $this->startDate ?: now()->toDateString();
        $endDate = $this->endDate ?: $startDate;

        $status = $this->status;
        $search = $this->search;

        // Handle inactive employees differently
        if ($status === 'inactive') {

            // Get inactive employees and their latest attendance records
            $query = Attendance::query()
                ->select('attendances.*')
                ->with(['employee', 'employee.shift'])
                ->whereBetween('date', [$startDate, $endDate])
                ->whereHas('employee', function($q) use ($orgId) {
                    $q->where('organization_id', $orgId)
                        ->where('active', 0);
                });

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('status', 'like', "%$search%")
                        ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%"));
                });
            }

            $query->orderByDesc('date')
                ->orderByRaw('check_in_time IS NULL')
                ->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));

            return $query;
        }

        // Normal filtering for active employees
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereBetween('date', [$startDate, $endDate])
            ->whereHas('employee', function($q) use ($orgId) {
                $q->where('organization_id', $orgId)
                    ->where('active', 1); // Only active employees for normal filters
            });

        if (!empty($status)) {
            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } else if ($status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%"));
            });
        }

        $query->orderByDesc('date')
            ->orderByRaw('check_in_time IS NULL')
            ->orderByDesc(DB::raw('COALESCE(check_in_time, updated_at)'));

        return $query;
    }


    /**
     * Get the last known attendance record for an employee before today.
     */
    protected function getLastAttendance($employeeId, $targetDate)
    {
        if (!$employeeId) {
            return null;
        }

        return Attendance::where('employee_id', $employeeId)
            ->where('date', '<', $targetDate)
            ->where(function ($q) {
                $q->whereNotNull('check_in_time')
                    ->orWhereNotNull('check_out_time');
            })
            ->orderByDesc('date')
            ->first();
    }


    public function formatMinutes($minutes)
    {
        if ($minutes < 0) {
            $minutes = 0;
        }

        if ($minutes < 60) {
            return $minutes . "m";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return $hours . "h";
        }

        return "{$hours}h {$mins}m";
    }


    public function columns(): array
    {
        $targetDate = $this->startDate ?: now()->toDateString();

        return [

            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            Column::make("Shift")
                ->label(function ($row) {
                    if (!$row->employee->shift) {
                        return '<span class="text-muted">-</span>';
                    }
                    $shift = $row->employee->shift;
                    $formattedStart = Carbon::parse($shift->start_time)->format('g:i A');
                    $formattedEnd = Carbon::parse($shift->end_time)->format('g:i A');

                    return "<strong>{$shift->name}</strong><br><small>{$formattedStart} - {$formattedEnd}</small>";
                })
                ->html(),

            // Clock In
            Column::make("Clock In", "check_in_time")
                ->format(function ($value, $row) use ($targetDate) {
                    $label = '';
                    $badge = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_in_time;
                        if ($value) {
                            $label = "<br><small class='text-muted'>(Last Clock-In)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';

                    if (empty($value)) {
                        $formatted = "<span class='fw-semibold text-primary'>Didn't Clocked In</span>";
                    } else {
                        $formatted = "<span class='fw-semibold text-success'>{$formatted}</span>";

                        if ($row->is_late_checkin && $row->employee->shift->track_late_checkin) {
                            if ($row->within_grace_period) {
                                $badge = "<br><span style='background-color:#ffc107; color:#000; padding:2px 8px; border-radius:12px; font-size:0.7rem; margin-left:6px; font-weight:500;'>⏰ {$this->formatMinutes($row->minutes_late)} Late (Grace)</span>";
                            } else {
                                $badge = "<br><span style='background-color:#dc3545; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.7rem; margin-left:6px; font-weight:500;'>🔴 {$this->formatMinutes($row->minutes_late)} Late</span>";
                            }
                        } elseif ($row->within_grace_period && $row->minutes_late > 0) {
                            $badge = "<span style='background-color:#17a2b8; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.7rem; margin-left:6px; font-weight:500;'>✓ On time</span>";
                        }
                    }

                    return "{$formatted}{$badge}{$label}";
                })
                ->html(),

            // Clock Out
            Column::make("Clock Out", "check_out_time")
                ->format(function ($value, $row) use ($targetDate) {
                    $label = '';
                    $badge = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id, $targetDate);
                        $value = $last?->check_out_time;
                        if ($value) {
                            $label = "<br><small class='text-muted'>(Last Clock-Out)</small>";
                        }
                    }

                    if ($row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        $formatted = "<span style='background-color:green; color:#fff; padding:4px 12px; border-radius:4px; font-size:0.75rem; margin-left:6px;'>Still In</span>";
                    } else {
                        $formatted = $row->check_out_time ? Carbon::parse($row->check_out_time)->format('M d, Y g:i A') : '';

                        if (empty($formatted)) {
                            if (empty($value)) {
                                $formatted = "<span class='fw-semibold text-primary'>Didn't Clocked Out</span>";
                            } else {
                                $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';
                                $formatted = "<span class='fw-semibold text-success'>{$formatted}</span>";
                            }
                        } else {
                            $formatted = "<span class='fw-semibold text-success'>{$formatted}</span>";

                            if ($row->is_early_checkout && $row->employee->shift->track_early_checkout) {
                                $badge = "<br><span style='background-color:#ff6b6b; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.7rem; margin-left:6px; font-weight:500;'>⚠️ {$this->formatMinutes($row->minutes_early)} Early</span>";
                            }

                        }
                    }

                    if ($row->status === 'clocked_out' && !$badge) {
                        if (strpos($formatted, 'fw-semibold') === false) {
                            $formatted = "<span class='fw-semibold text-success'>{$formatted}</span>";
                        }
                    }

                    return "{$formatted}{$badge}{$label}";
                })
                ->html(),

            // Worked Hours
            Column::make("Work Summary")
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
            'ids' => $this->getSelected(),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'status' => $this->status,
        ]);

        return redirect()->to($url);
    }
}
