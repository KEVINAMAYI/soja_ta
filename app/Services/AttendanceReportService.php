<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Facades\DB;

class AttendanceReportService
{
    public function getDaily(int $orgId, array $ids = [])
    {
        return Attendance::with(['employee.shift'])
            ->whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->when($ids, fn($q) => $q->whereIn('id', $ids))
            ->get();
    }

    public function getMonthly(int $orgId, array $ids = [], ?string $month = null)
    {
        $monthFilter = $month ?? now()->format('Y-m'); // use provided month or default to current

        $query = Attendance::query()
            ->join('employees', 'attendances.employee_id', '=', 'employees.id')
            ->where('employees.organization_id', $orgId)
            ->with('employee')
            ->select(
                'attendances.employee_id',
                DB::raw("DATE_FORMAT(attendances.date, '%Y-%m') as attendance_month"),
                DB::raw("SUM(CASE WHEN attendances.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN attendances.status = 'leave' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw("COUNT(*) as total_days"),
                DB::raw("SUM(attendances.worked_hours) as total_worked_hours"),
                DB::raw("SUM(attendances.overtime_hours) as total_ot_hours")
            )
            ->whereRaw("DATE_FORMAT(attendances.date, '%Y-%m') = ?", [$monthFilter])
            ->groupBy('attendances.employee_id', DB::raw("DATE_FORMAT(attendances.date, '%Y-%m')"));

        if (!empty($ids)) {
            $query->whereIn('employee_id', $ids);
        }

        return $query->get();
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
