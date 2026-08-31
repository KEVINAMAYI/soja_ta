<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Builds the superadmin dashboard analytics payload (clients, workforce,
 * attendance and platform-utilization highlights) for a selected period.
 */
class DashboardAnalyticsService
{
    public const PERIOD_TODAY = 'today';
    public const PERIOD_THIS_WEEK = 'this_week';
    public const PERIOD_LAST_30_DAYS = 'last_30_days';
    public const PERIOD_LAST_90_DAYS = 'last_90_days';
    public const PERIOD_CUSTOM = 'custom';

    public const PERIODS = [
        self::PERIOD_TODAY,
        self::PERIOD_THIS_WEEK,
        self::PERIOD_LAST_30_DAYS,
        self::PERIOD_LAST_90_DAYS,
        self::PERIOD_CUSTOM,
    ];

    /**
     * @param string $period one of self::PERIODS
     */
    public function getAnalytics(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        [$start, $end, $prevStart, $prevEnd] = $this->resolveDateRange($period, $startDate, $endDate);

        return [
            'period' => [
                'type' => $period,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'clients' => $this->clientsSummary(),
            'workforce_highlights' => $this->workforceHighlights($start, $end, $prevStart, $prevEnd),
            'days_attendance' => $this->daysAttendance($start, $end),
            'platform_utilization' => $this->platformUtilization($start, $end),
        ];
    }

    /**
     * Resolves [start, end, previousStart, previousEnd] Carbon instances for
     * the given period. The "previous" range is an equal-length window
     * immediately preceding the selected one, used for growth comparisons.
     */
    public function resolveDateRange(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $now = Carbon::now();

        switch ($period) {
            case self::PERIOD_TODAY:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case self::PERIOD_THIS_WEEK:
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfDay();
                break;

            case self::PERIOD_LAST_30_DAYS:
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case self::PERIOD_LAST_90_DAYS:
                $start = $now->copy()->subDays(89)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case self::PERIOD_CUSTOM:
                if (!$startDate || !$endDate) {
                    throw new InvalidArgumentException('start_date and end_date are required for a custom period.');
                }
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->endOfDay();
                if ($start->gt($end)) {
                    throw new InvalidArgumentException('start_date must be before or equal to end_date.');
                }
                break;

            default:
                throw new InvalidArgumentException("Unsupported period [{$period}].");
        }

        $daysInRange = $start->diffInDays($end) + 1;
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($daysInRange - 1)->startOfDay();

        return [$start, $end, $prevStart, $prevEnd];
    }

    /**
     * Clients (organizations) group: total, active, inactive and total users.
     */
    private function clientsSummary(): array
    {
        $total = Organization::count();
        $active = Organization::where('active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'total_users' => User::count(),
        ];
    }

    /**
     * Workforce highlights: total employees, new employees added within the
     * period and percentage growth vs. the equivalent previous period.
     */
    private function workforceHighlights(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd): array
    {
        $totalEmployees = Employee::count();

        $newEmployees = Employee::whereBetween('created_at', [$start, $end])->count();
        $previousNewEmployees = Employee::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $percentageGrowth = $previousNewEmployees > 0
            ? round((($newEmployees - $previousNewEmployees) / $previousNewEmployees) * 100, 1)
            : ($newEmployees > 0 ? 100.0 : 0.0);

        return [
            'total_employees' => $totalEmployees,
            'new_employees' => $newEmployees,
            'percentage_growth' => $percentageGrowth,
        ];
    }

    /**
     * Attendance across all clients/employees for the selected period:
     * present, absent, on leave and overall attendance percentage.
     * Percentage is present-days over all recorded (expected) days.
     */
    private function daysAttendance(Carbon $start, Carbon $end): array
    {
        $rows = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->select('status', DB::raw('COUNT(DISTINCT employee_id, date) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = 0;
        $absent = 0;
        $onLeave = 0;

        foreach ($rows as $status => $count) {
            if (in_array($status, ['clocked_in', 'clocked_out'], true)) {
                $present += $count;
            } elseif (in_array($status, ['absent', 'unchecked_in'], true)) {
                $absent += $count;
            } elseif ($status === 'on_leave') {
                $onLeave += $count;
            }
        }

        $totalRecorded = $rows->sum();
        $percentageAttendance = $totalRecorded > 0 ? round(($present / $totalRecorded) * 100, 1) : 0.0;

        return [
            'present' => $present,
            'absent' => $absent,
            'on_leave' => $onLeave,
            'percentage_attendance' => $percentageAttendance,
        ];
    }

    /**
     * Platform utilization: attendance volume and reach across all clients
     * for the selected period.
     */
    private function platformUtilization(Carbon $start, Carbon $end): array
    {
        $query = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $totalAttendance = (clone $query)->whereIn('status', ['clocked_in', 'clocked_out'])->count();

        $activeClients = Organization::whereHas('employees.attendances', function ($q) use ($start, $end) {
            $q->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        })->count();

        $employeeAttendance = (clone $query)->distinct('employee_id')->count('employee_id');

        // Transactions = individual check-in/check-out events recorded.
        $attendanceTransactions = (clone $query)->whereNotNull('check_in_time')->count()
            + (clone $query)->whereNotNull('check_out_time')->count();

        return [
            'total_attendance' => $totalAttendance,
            'active_clients' => $activeClients,
            'employee_attendance' => $employeeAttendance,
            'attendance_transactions' => $attendanceTransactions,
        ];
    }
}
