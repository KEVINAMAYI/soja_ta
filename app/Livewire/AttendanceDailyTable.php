<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\EmployeesExcelExport;
use App\Models\Employee;
use Carbon\Carbon;
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

        // Base Attendance query
        $query = Attendance::query()
            ->select('attendances.*')
            ->with(['employee', 'employee.shift'])
            ->whereDate('date', $today)
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId));


        // Add subselects for last check_in/out from *previous* records
        $query->addSelect([
            'last_check_in' => Attendance::select('check_in_time')
                ->whereColumn('employee_id', 'attendances.employee_id')
                ->whereNotNull('check_in_time')
                ->where('date', '<', $today)  // 👈 this line is critical
                ->orderByDesc('date')
                ->limit(1),

            'last_check_out' => Attendance::select('check_out_time')
                ->whereColumn('employee_id', 'attendances.employee_id')
                ->whereNotNull('check_out_time')
                ->where('date', '<', $today)  // 👈 this line too
                ->orderByDesc('date')
                ->limit(1),
        ]);

        if (!empty($status)) {

            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);

            } elseif ($status === 'off_shift') {
                $query->whereHas('employee.shift', function ($q) {
                    $q->where('status', 'inactive');
                });
            } else {
                $query->where('status', $status);
            }
        }


        // Apply search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%$search%")
                    ->orWhereHas('employee', fn($q) => $q->where('name', 'like', "%$search%")
                    );
            });
        }

        return $query;
    }


    public function columns(): array
    {
        $threshold = $this->min_ot_threshold;

        $isAbsentFilter = in_array($this->status, ['absent', 'unchecked_in', 'off_shift']);


        return [

            Column::make("Employee")
                ->label(fn($row) => view('livewire.admin.attendance.employee', ['attendance' => $row])),

            // Updated Shift Column with start and end times
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

            Column::make($isAbsentFilter ? "Last Clock-In" : "Clock In", "check_in_time")
                ->format(function ($value, $row) {
                    $label = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $value = $row->last_check_in;
                        if (is_null($this->status)) {
                            $label = "<br><small class='text-muted'>(Last Clock-in)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '-';

                    return "<div>
                    <span class='fw-semibold text-success'>{$formatted}</span>
                    {$label}
                    </div>";
                })
                ->html(),

            Column::make($isAbsentFilter ? "Last Clock-Out" : "Clock Out", "check_out_time")
                ->format(function ($value, $row) {
                    $label = '';

                    if (in_array($row->status, ['absent', 'unchecked_in'])) {
                        $value = $row->last_check_out;
                        if (is_null($this->status)) {
                            $label = "<br><small class='text-muted'>(Last Clock-Out)</small>";
                        }
                    }

                    $formatted = $value ? Carbon::parse($value)->format('M d, Y g:i A') : '';

                    // 🔴 Show red badge if user is still checked in (no checkout but has checkin)
                    if ($row->status === 'clocked_in' && $row->check_in_time && !$row->check_out_time) {
                        $badge = "<span style='background-color:green; color:#fff; padding:4px 12px; border-radius:4px; font-size:0.75rem; margin-left:6px;'>Still In</span>";
                    } else {
                        $badge = '';
                    }

                    return "<div>
            <span class='fw-semibold' style='color: #dc3545;'>{$formatted}</span>
            {$badge}
            {$label}
        </div>";
                })
                ->html(),


            Column::make("Overtime (hours)", "overtime_hours")
                ->sortable()
                ->format(function ($value) use ($threshold) {
                    return $value;
                })
                ->html()

        ];
    }


    public function bulkActions(): array
    {
        return [
            'exportExcel' => 'Export Excel',
            'exportPdf' => 'Export PDF'
        ];
    }


    public function exportExcel()
    {
        return Excel::download(new AttendanceDailyExcelExport($this->getSelected()), 'attendance.xlsx');
    }


    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('attendance-daily.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }


}
