<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ElevateHrLeaveWebhookController extends Controller
{
    public function __invoke(Request $request, string $organization): JsonResponse
    {
        $validator = Validator::make($request->json()->all(), [
            'event_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'event_type' => ['required', Rule::in(['leave.approved', 'leave.recalled', 'leave.partial_recall'])],
            'occurred_at' => ['required', 'date'],
            'leave' => ['required', 'array'],
            'leave.leave_reference' => ['required', 'uuid'],
            'leave.employee_number' => ['required', 'string', 'max:255'],
            'leave.employee_name' => ['required', 'string', 'max:255'],
            'leave.leave_type_code' => ['required', 'string', 'max:255'],
            'leave.leave_type_name' => ['required', 'string', 'max:255'],
            'leave.is_paid' => ['required', 'boolean'],
            'leave.start_date' => ['required', 'date_format:Y-m-d'],
            'leave.end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:leave.start_date'],
            'leave.total_days' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'leave.status' => ['required', 'string', 'max:32'],
            'leave.approved_at' => ['nullable', 'date'],
            'leave.days' => ['present', 'array', 'max:3660'],
            'leave.days.*' => ['required', 'array:date,day_part,hours,is_holiday'],
            'leave.days.*.date' => ['required', 'date_format:Y-m-d', 'distinct', 'after_or_equal:leave.start_date', 'before_or_equal:leave.end_date'],
            'leave.days.*.day_part' => ['required', Rule::in(['FULL_DAY', 'HALF_DAY_MORNING', 'HALF_DAY_AFTERNOON'])],
            'leave.days.*.hours' => ['required', 'numeric', 'min:0', 'max:24', 'decimal:0,2'],
            'leave.days.*.is_holiday' => ['required', 'boolean'],
        ]);


        Log::info('Received Elevate HR leave webhook.', [
            'organization' => $organization,
            'ip' => $request->ip(),
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid leave event.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $snapshot = $data['leave'];
        $recalled = $data['event_type'] === 'leave.recalled';

        Log::info("TUKO AREA!!!");

        if (! $recalled && ($snapshot['status'] !== 'APPROVED'
            || (float) $snapshot['total_days'] <= 0 || $snapshot['days'] === [])) {
            return response()->json(['message' => 'Active leave must be approved and contain remaining leave days.'], 422);
        }

        DB::transaction(function () use ($request, $organization, $data, $snapshot, $recalled) {
            // Serialise this organisation's events, including first deliveries and type creation.
            Organization::whereKey($organization)->lockForUpdate()->firstOrFail();

            $employees = Employee::where('organization_id', $organization)
                ->where('ad_employee_id', $snapshot['employee_number'])
                ->get(['id', 'department_id', 'name', 'email']);
        Log::info("TUKO AREA!!!");

            if ($employees->count() !== 1) {
                $reason = $employees->isEmpty()
                    ? 'No employee found in this organisation for ad_employee_id ==> '.$snapshot['employee_number']
                    : 'Multiple employees found in this organisation for ad_employee_id ==> '.$snapshot['employee_number'];
                $request->attributes->set('elevate_failure_reason', $reason);
                $request->attributes->set('elevate_failure_metadata', [
                    'ad_employee_id' => $snapshot['employee_number'],
                    'employee_name' => $snapshot['employee_name'],
                    'matched_employees' => $employees->map(fn (Employee $employee) => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'email' => $employee->email,
                    ])->all(),
                ]);
        Log::info("TUKO AREA111!!!");

                throw ValidationException::withMessages(['leave.employee_number' => $reason]);
            }
        Log::info("TUKO AREA1234!!!");

            $employee = $employees->sole();

            $leave = Leave::where('organization_id', $organization)
                ->where('elevatehr_reference', $snapshot['leave_reference'])->first();

            if ($leave && $leave->employee_id !== $employee->id) {
                throw ValidationException::withMessages(['leave.leave_reference' => 'Leave reference belongs to another employee.']);
            }

            if ($leave && $data['sequence'] <= $leave->elevatehr_sequence) {
                return;
            }

            // if num of days is a decimal then return date should be same day otherwise add a day to end date for expected resumption
            $expectedResumption = null;
            if (!$recalled) {
                $numOfDays = $snapshot['total_days'];
                if (floor($numOfDays) != $numOfDays) {
                    $expectedResumption = Carbon::parse($snapshot['end_date'])->toDateString();
                } else {
                    $expectedResumption = Carbon::parse($snapshot['end_date'])->addDay()->toDateString();
                }
            }

            $leave ??= new Leave;
            $leave->fill([
                'organization_id' => $organization,
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'leave_type' => LeaveType::resolveName((int) $organization, $snapshot['leave_type_name']),
                'start_date' => $snapshot['start_date'],
                'end_date' => $snapshot['end_date'],

                // if number of days is 1.5 (point any number)
                'expected_resumption' => $expectedResumption,
                'status' => $recalled ? 'cancelled' : 'approved',
                'num_of_days' => $recalled ? 0 : $snapshot['total_days'],
                'leave_days' => $recalled ? [] : $snapshot['days'],
                'elevatehr_reference' => $snapshot['leave_reference'],
                'elevatehr_event_id' => $data['event_id'],
                'elevatehr_sequence' => $data['sequence'],
            ]);
            $leave->cancelled_at = $recalled ? Carbon::parse($data['occurred_at']) : null;
            $leave->save();
        }, 3);

        return response()->json(['message' => 'Leave event processed.']);
    }
}
