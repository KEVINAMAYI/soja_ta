<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\AttendanceBreakLog;
use App\Models\ShiftBreak;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BreakPunchController extends Controller
{
    /**
     * Start a break
     *
     * POST /api/attendance/break/start
     * Body: {
     *   "attendance_id": 123,
     *   "shift_break_id": 456
     * }
     */
    public function startBreak(Request $request)
    {
        $request->validate([
            'attendance_id' => 'required|exists:attendances,id',
            'shift_break_id' => 'required|exists:shift_breaks,id',
        ]);

        DB::beginTransaction();
        try {
            $attendance = Attendance::findOrFail($request->attendance_id);
            $employee = auth()->user()->employee;

            // Authorization check
            if ($attendance->employee_id !== $employee->id) {
                if (!auth()->user()->can('manage-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Unauthorized to manage this attendance record.'
                    ], 403);
                }
            }

            // Check if already checked out
            if ($attendance->status === 'clocked_out') {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Cannot start break - already clocked out.'
                ], 400);
            }

            $shiftBreak = ShiftBreak::findOrFail($request->shift_break_id);

            // Check if break belongs to employee's shift
            if ($shiftBreak->shift_id !== $attendance->employee->shift_id) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'This break does not belong to your shift.'
                ], 400);
            }

            // Check if within time window
            if (!$shiftBreak->isWithinWindow()) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Break can only be taken during ' . $shiftBreak->getWindowTimeFormatted(),
                ], 400);
            }

            // Find or create break log
            $breakLog = AttendanceBreakLog::firstOrCreate(
                [
                    'attendance_id' => $attendance->id,
                    'shift_break_id' => $shiftBreak->id,
                ],
                [
                    'status' => 'pending',
                    'is_taken' => false,
                ]
            );

            // Check if already in progress
            if ($breakLog->status === 'in_progress') {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Break already in progress.',
                    'break_start_time' => $breakLog->break_start_time,
                ], 400);
            }

            // Check if already completed
            if (in_array($breakLog->status, ['completed', 'exceeded'])) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'This break has already been taken.',
                    'actual_duration' => $breakLog->actual_duration_minutes,
                ], 400);
            }

            // Start the break
            $breakLog->startBreak();

            DB::commit();

            return response()->json([
                'code' => 1000,
                'message' => 'Break started successfully',
                'data' => [
                    'break_log_id' => $breakLog->id,
                    'break_name' => $shiftBreak->name,
                    'break_start_time' => $breakLog->break_start_time->toDateTimeString(),
                    'expected_duration' => $shiftBreak->duration_minutes,
                    'max_duration' => $shiftBreak->max_duration_minutes ?? $shiftBreak->duration_minutes,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 1004,
                'message' => 'Failed to start break: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * End a break
     *
     * POST /api/attendance/break/end
     * Body: {
     *   "attendance_id": 123,
     *   "shift_break_id": 456
     * }
     */
    public function endBreak(Request $request)
    {
        $request->validate([
            'attendance_id' => 'required|exists:attendances,id',
            'shift_break_id' => 'required|exists:shift_breaks,id',
        ]);

        DB::beginTransaction();
        try {
            $attendance = Attendance::findOrFail($request->attendance_id);
            $employee = auth()->user()->employee;

            // Authorization check
            if ($attendance->employee_id !== $employee->id) {
                if (!auth()->user()->can('manage-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Unauthorized to manage this attendance record.'
                    ], 403);
                }
            }

            $breakLog = AttendanceBreakLog::where('attendance_id', $attendance->id)
                ->where('shift_break_id', $request->shift_break_id)
                ->first();

            if (!$breakLog) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Break log not found.'
                ], 404);
            }

            if ($breakLog->status !== 'in_progress') {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Break is not currently in progress.',
                ], 400);
            }

            // End the break
            $breakLog->endBreak();

            DB::commit();

            $warningMessage = null;
            if (!$breakLog->is_compliant) {
                $warningMessage = "Break exceeded allowed duration by {$breakLog->excess_minutes} minutes.";

                if ($breakLog->shiftBreak->penalty_type !== 'none') {
                    $warningMessage .= " Penalty applied: " . $breakLog->shiftBreak->getPenaltyDescription();
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => 'Break ended successfully',
                'data' => [
                    'break_log_id' => $breakLog->id,
                    'break_name' => $breakLog->shiftBreak->name,
                    'break_start_time' => $breakLog->break_start_time->toDateTimeString(),
                    'break_end_time' => $breakLog->break_end_time->toDateTimeString(),
                    'actual_duration_minutes' => $breakLog->actual_duration_minutes,
                    'is_compliant' => $breakLog->is_compliant,
                    'excess_minutes' => $breakLog->excess_minutes,
                    'status' => $breakLog->status,
                ],
                'warning' => $warningMessage,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'code' => 1004,
                'message' => 'Failed to end break: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available breaks for current shift
     *
     * GET /api/attendance/{attendance_id}/breaks/available
     */
    public function getAvailableBreaks($attendanceId)
    {
        try {
            $attendance = Attendance::findOrFail($attendanceId);
            $employee = auth()->user()->employee;

            // Authorization check
            if ($attendance->employee_id !== $employee->id) {
                if (!auth()->user()->can('view-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Unauthorized.'
                    ], 403);
                }
            }

            if (!$attendance->employee->shift) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'No shift assigned.'
                ], 404);
            }

            $currentTime = now();
            $availableBreaks = [];

            $shift = $attendance->employee->shift;
            $activeBreaks = $shift->activeBreaks;

            foreach ($activeBreaks as $shiftBreak) {
                // Get break log status
                $breakLog = AttendanceBreakLog::where('attendance_id', $attendance->id)
                    ->where('shift_break_id', $shiftBreak->id)
                    ->first();

                $status = $breakLog ? $breakLog->status : 'pending';
                $isWithinWindow = $shiftBreak->isWithinWindow($currentTime);
                $canStart = $status === 'pending' && $isWithinWindow;
                $canEnd = $status === 'in_progress';

                $availableBreaks[] = [
                    'id' => $shiftBreak->id,
                    'name' => $shiftBreak->name,
                    'type' => $shiftBreak->type,
                    'type_label' => $shiftBreak->getTypeLabel(),
                    'duration_minutes' => $shiftBreak->duration_minutes,
                    'max_duration_minutes' => $shiftBreak->max_duration_minutes ?? $shiftBreak->duration_minutes,
                    'window_time' => $shiftBreak->getWindowTimeFormatted(),
                    'is_within_window' => $isWithinWindow,
                    'is_mandatory' => $shiftBreak->is_mandatory,
                    'require_punch' => $shiftBreak->require_punch,
                    'status' => $status,
                    'can_start' => $canStart,
                    'can_end' => $canEnd,
                    'break_start_time' => $breakLog?->break_start_time,
                    'actual_duration_minutes' => $breakLog?->actual_duration_minutes,
                ];
            }

            return response()->json([
                'code' => 1000,
                'message' => 'Available breaks retrieved successfully',
                'data' => $availableBreaks,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 1004,
                'message' => 'Failed to retrieve breaks: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get break summary for an attendance record
     *
     * GET /api/attendance/{attendance_id}/breaks/summary
     */
    public function getBreakSummary($attendanceId)
    {
        try {
            $attendance = Attendance::with('employee.shift')->findOrFail($attendanceId);
            $employee = auth()->user()->employee;

            // Authorization check
            if ($attendance->employee_id !== $employee->id) {
                if (!auth()->user()->can('view-attendance')) {
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Unauthorized.'
                    ], 403);
                }
            }

            $breakLogs = AttendanceBreakLog::where('attendance_id', $attendance->id)
                ->with('shiftBreak')
                ->get();

            $summary = [
                'total_breaks' => $breakLogs->count(),
                'completed_breaks' => $breakLogs->where('status', 'completed')->count(),
                'pending_breaks' => $breakLogs->where('status', 'pending')->count(),
                'in_progress_breaks' => $breakLogs->where('status', 'in_progress')->count(),
                'total_break_minutes' => $breakLogs->sum('actual_duration_minutes'),
                'total_excess_minutes' => $breakLogs->sum('excess_minutes'),
                'non_compliant_breaks' => $breakLogs->where('is_compliant', false)->count(),
                'breaks' => $breakLogs->map(function ($log) {
                    return [
                        'name' => $log->shiftBreak->name,
                        'type' => $log->shiftBreak->type,
                        'status' => $log->status,
                        'start_time' => $log->break_start_time,
                        'end_time' => $log->break_end_time,
                        'duration_minutes' => $log->actual_duration_minutes,
                        'excess_minutes' => $log->excess_minutes,
                        'is_compliant' => $log->is_compliant,
                    ];
                })
            ];

            return response()->json([
                'code' => 1000,
                'message' => 'Break summary retrieved successfully',
                'data' => $summary,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 1004,
                'message' => 'Failed to retrieve summary: ' . $e->getMessage(),
            ], 500);
        }
    }
}
