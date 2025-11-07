<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\EmployeesExcelExport;
use App\Models\Employee;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Attendance;
use App\Services\AttendanceSeeder;

class AttendanceDailyTable extends DataTableComponent
{
    protected $model = Attendance::class;
    public $min_ot_threshold = 0;
    public $status;

    protected AttendanceSeeder $seeder;


    public function mount(AttendanceSeeder $seeder, $status = null)
    {
        $this->status = $status;
        $this->seeder = $seeder;
        $orgId = auth()->user()->employee->organization_id ?? null;

        if ($status == 'unchecked_in' || $status == 'absent') {
            $this->seeder->seedMissingAttendanceRecords($orgId);
        }

        $this->min_ot_threshold = auth()->user()->employee->organization()->first()->getSetting('min_ot_threshold', 0);

    }


    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;
        $today = now()->toDateString();
        $status = $this->status;
        $search = $this->search;

        // Base Attendance query (no more last check in/out subselects)
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereDate('date', $today)
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId));

        if (!empty($status)) {
            if ($status === 'absent') {
                // Checking for 'absent' or 'unchecked_in' statuses
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } else {
                // Handle other statuses
                $query->where('status', $status);
            }
        }


        // Apply search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%"));
            });
        }

        return $query;
    }


    /**
     * Get the last known attendance record for an employee before today.
     */
    protected function getLastAttendance($employeeId)
    {
        if (!$employeeId) {
            return null;
        }

        $today = now()->toDateString();

        return Attendance::where('employee_id', $employeeId)
            ->where('date', '<', $today)
            ->where(function ($q) {
                $q->whereNotNull('check_in_time')
                    ->orWhereNotNull('check_out_time');
            })
            ->orderByDesc('date')
            ->first();
    }


    public function columns(): array
    {
        $threshold = $this->min_ot_threshold;

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
                ->format(function ($value, $row) {
                    $label = '';

                    // Show last known check-in for absentees
                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id);
                        $value = $last?->check_in_time;
                        if ($value) {
                            $label = "<br><small class='text-muted'>(Last Clock-In)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';
                    return "<span class='fw-semibold text-success'>{$formatted}</span>{$label}";
                })
                ->html(),


            // Clock Out
            Column::make("Clock Out", "check_out_time")
                ->format(function ($value, $row) {
                    $label = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $last = $this->getLastAttendance($row->employee_id);
                        $value = $last?->check_out_time;
                        if ($value) {
                            $label = "<br><small class='text-muted'>(Last Clock-Out)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';

                    $badge = '';
                    if ($row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        $badge = "<span style='background-color:green; color:#fff; padding:4px 12px; border-radius:4px; font-size:0.75rem; margin-left:6px;'>Still In</span>";
                    }

                    return "<div>
            <span class='fw-semibold' style='color:#dc3545;'>{$formatted}</span>
            {$badge}{$label}
        </div>";
                })
                ->html(),


            Column::make("Overtime (hours)", "overtime_hours")
                ->sortable()
                ->format(fn($value) => $value ?? '-')
                ->html(),
        ];
    }


    #[On('export-daily-excel')]
    public function exportExcel()
    {
        return Excel::download(new AttendanceDailyExcelExport($this->getSelected()), 'attendance.xlsx');
    }


    #[On('export-daily-pdf')]
    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('attendance-daily.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }


}
