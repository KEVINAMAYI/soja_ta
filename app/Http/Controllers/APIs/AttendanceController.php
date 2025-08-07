<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AttendanceController extends Controller
{

    public function QRCheckin(Request $request)
    {

        $request->validate([
            'qr_code' => 'required|string'
        ]);

        DB::beginTransaction();

        try {

            $employee = Employee::where('qr_code', $request->qr_code)->firstOrFail();

            $existing = Attendance::where('employee_id', $employee->id)
                ->whereNull('check_out_time')
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Employee has already checked in and not yet checked out.'
                ], 409);
            }

            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'date' => today(),
                'check_in_time' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Checked in successfully',
                'data' => new AttendanceResource($attendance)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to check in.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function QRCheckout(Request $request)
    {
        $request->validate([
            'qr_code' => 'required|string'
        ]);

        DB::beginTransaction();

        try {

            $standardWorkingHours = 8;

            $employee = Employee::where('qr_code', $request->qr_code)->firstOrFail();

            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereNull('check_out_time')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'message' => 'Employee has not checked in yet or has already checked out.'
                ], 409);
            }

            //for testing
            $checkOutTime = now()->addHours(9); // Simulate checkout after 9 hours

//            $checkOutTime = now();
            $checkInTime = Carbon::parse($attendance->check_in_time);

            $overtimeStart = $checkInTime->copy()->addHours($standardWorkingHours);
            $workedHours = $checkInTime->diffInMinutes($checkOutTime) / 60;
            $overtimeHours = max(0, $overtimeStart->diffInMinutes($checkOutTime) / 60);

            $attendance->update([
                'check_out_time' => $checkOutTime,
                'worked_hours' => round($workedHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
            ]);


            if ($overtimeHours > 0) {
                Overtime::create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'date' => $checkOutTime->toDateString(),
                    'start_time' => $overtimeStart->toTimeString(),
                    'end_time' => $checkOutTime->toTimeString(),
                    'hours' => round($overtimeHours, 2),
                    'reason' => 'Auto-generated on checkout',
                    'approved_by' => null,
                ]);
            }


            DB::commit();

            return response()->json([
                'message' => 'Checked out successfully',
                'data' => new AttendanceResource($attendance)
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to check out.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


}
