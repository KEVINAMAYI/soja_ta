<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceReportService
{
    public function getDaily(
        int     $orgId,
        array   $ids = [],
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $status = null,
    )
    {
        // 🔹 Set default dates to today if not provided
        $startDate = $startDate ?? now()->toDateString();
        $endDate = $endDate ?? $startDate;

        // 💡 FIX: Normalize the dates to YYYY-MM-DD format using Carbon
        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();

        $query = Attendance::with(['employee.shift'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId));

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        // 🔹 Filter by date range
        $query->whereBetween('date', [$startDate, $endDate]);

        // 🔹 Filter by status
        if ($status) {
            if ($status === 'absent') {
                $query->whereIn('status', ['absent', 'unchecked_in']);
            } else if ($status === 'present') {
                $query->whereIn('status', ['clocked_in', 'clocked_out']);
            } else {
                $query->where('status', $status);
            }
        }

        return $query->get();
    }


    public function baseQuery($orgId, $ids, $start_date, $end_date, $department_id)
    {
        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId);

        // Filter by department
        if ($department_id && $department_id !== 'all') {
            $query->where('employees.department_id', $department_id);
        }

        // Date filtering
        if ($start_date) {
            $query->where('attendances.date', '>=', $start_date);
        }

        if ($end_date) {
            $query->where('attendances.date', '<=', $end_date);
        }

        if (!empty($ids)) {
            $query->whereIn('attendances.employee_id', $ids);
        }

        return $query;
    }


    public function getMonthly($orgId, $ids, $start_date, $end_date, $department_id)
    {
        return $this->baseQuery($orgId, $ids, $start_date, $end_date, $department_id)
            ->with([
                'employee',
                'employee.department',
                'employee.shift',
            ])
            ->select(
                'attendances.employee_id',

                // Present
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('clocked_in','clocked_out')
                        OR attendances.check_in_time IS NOT NULL
                        THEN 1 ELSE 0
                    END
                ) as present_days
            "),

                // Absent
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('absent','unchecked_in')
                        THEN 1 ELSE 0
                    END
                ) as absent_days
            "),

                // Leave
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status = 'on_leave' THEN 1 ELSE 0
                    END
                ) as leave_days
            "),

                // Sick leave
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status IN ('sick_leave','sick_off')
                        THEN 1 ELSE 0
                    END
                ) as sick_days
            "),

                // Off shift days
                DB::raw("
                SUM(
                    CASE
                        WHEN attendances.status = 'off_shift'
                        THEN 1 ELSE 0
                    END
                ) as off_shift_days
            "),

                // Totals
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->groupBy('attendances.employee_id')->get();
    }


    public function getByDepartment(int $orgId, array $ids = [], array $filters = [])
    {
        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.organization_id', $orgId)
            ->select(
                'employees.department_id',
                'departments.name as department_name',
                DB::raw("DATE_FORMAT(attendances.date, '%Y-%m') as attendance_month"),
                DB::raw("SUM(CASE WHEN attendances.status = 'Present' THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'Absent' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'Leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            );

        // Optional: filter by departments
        if (!empty($ids)) {
            $query->whereIn('employees.department_id', $ids);
        }

        // Flexible date filter
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('attendances.date', [$filters['start_date'], $filters['end_date']]);
        } elseif (!empty($filters['date'])) {
            $query->whereDate('attendances.date', $filters['date']);
        } elseif (!empty($filters['month'])) {
            $query->whereRaw("DATE_FORMAT(attendances.date, '%Y-%m') = ?", [$filters['month']]);
        } elseif (!empty($filters['week_start']) && !empty($filters['week_end'])) {
            $query->whereBetween('attendances.date', [$filters['week_start'], $filters['week_end']]);
        }

        $query->groupBy('employees.department_id', 'departments.name', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));

        return $query->get();
    }

}
