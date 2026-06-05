<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Jobs\SendLeaveNotificationJob;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LeaveController extends Controller
{
    /**
     * Apply for leave (single employee)
     * POST /api/leaves/apply
     */
    public function apply(Request $request)
    {
        try {
            DB::beginTransaction();

            // Validation rules
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'leave_type' => 'required|in:sick,offshift,annual,maternity,paternity,compassionate,study,unpaid,personal',
                'start_date' => 'required|date|after_or_equal:today',
                'duration_type' => 'required|in:dateRange,numberOfDays',
                'end_date' => 'required_if:duration_type,dateRange|nullable|date|after_or_equal:start_date',
                'number_of_days' => 'required_if:duration_type,numberOfDays|nullable|integer|min:1',
                'reason' => 'nullable|string|max:500',
                'contact_during_leave' => 'nullable|string|max:255',
                'emergency_contact' => 'nullable|string|max:255',
                'handover_to' => 'nullable|exists:employees,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $employeeId = $request->employee_id;
            $leaveType = $request->leave_type;
            $startDate = $request->start_date;
            $durationType = $request->duration_type;

            // Calculate end date
            if ($durationType === 'numberOfDays') {
                $start = Carbon::parse($startDate);
                $end = $start->copy()->addDays($request->number_of_days - 1);
                $endDate = $end->format('Y-m-d');
            } else {
                $endDate = $request->end_date;
            }

            // Check for conflicts
            $conflicts = $this->getConflictingEmployees([$employeeId], $startDate, $endDate);

            if (!empty($conflicts)) {
                DB::rollBack();
                return response()->json([
                    'code' => 1003,
                    'message' => 'You have conflicting leave or off-shift status for the requested dates.',
                ], 409);
            }

            // Get employee details
            $employee = Employee::with('department')->findOrFail($employeeId);
            $orgId = $employee->organization_id;
            $departmentId = $employee->department_id;

            // Get leave type name
            $leaveTypeDetails = $this->getLeaveTypeName($leaveType);

            // Handle different leave types
            if ($leaveType === 'offshift') {
                // Update employee shift status
                $employee->update([
                    'shift_status' => 'off_shift',
                    'start_off_shift_date' => $startDate,
                    'end_off_shift_date' => $endDate,
                ]);

                DB::commit();

                return response()->json([
                    'code' => 1000,
                    'message' => "Shift status updated to 'Off Shift'.",
                    'data' => [
                        'employee_id' => $employeeId,
                        'shift_status' => 'off_shift',
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]
                ], 200);

            } elseif ($leaveType === 'sick') {
                // Update employee shift status for sick leave
                $employee->update([
                    'shift_status' => 'sick_off',
                    'start_off_shift_date' => $startDate,
                    'end_off_shift_date' => $endDate,
                ]);

                DB::commit();

                return response()->json([
                    'code' => 1000,
                    'message' => "Shift status updated to 'Sick Off'.",
                    'data' => [
                        'employee_id' => $employeeId,
                        'shift_status' => 'sick_off',
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]
                ], 200);

            } else {
                // Create leave request
                $leave = Leave::create([
                    'organization_id' => $orgId,
                    'employee_id' => $employeeId,
                    'department_id' => $departmentId,
                    'leave_type' => $leaveTypeDetails['name'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $request->reason,
                    'contact_during_leave' => $request->contact_during_leave,
                    'emergency_contact' => $request->emergency_contact,
                    'handover_to' => $request->handover_to,
                    'expected_resumption' => Carbon::parse($endDate)->addDay()->format('Y-m-d'),
                    'status' => 'pending',
                ]);

                DB::commit();

                // Dispatch email notification job
                SendLeaveNotificationJob::dispatch($leave->id);

                return response()->json([
                    'code' => 1000,
                    'message' => 'Leave request submitted successfully. Pending review.',
                    'data' => [
                        'leave_id' => $leave->id,
                        'employee_id' => $employeeId,
                        'leave_type' => $leaveTypeDetails['name'],
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => 'pending',
                        'expected_resumption' => $leave->expected_resumption,
                    ]
                ], 201);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'code' => 1003,
                'message' => 'Failed to apply for leave: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get leaves for authenticated employee
     * GET /api/leaves/my-leaves
     */
    public function myLeaves(Request $request)
    {
        try {

            // Get employee from authenticated user
            $user = $request->user();

            if (!$user->employee) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Employee record not found for authenticated user'
                ], 404);
            }

            $employeeId = $user->employee->id;

            // Optional filters
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:pending,approved,rejected,cancelled',
                'leave_type' => 'nullable|string',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Build query
            $query = Leave::where('employee_id', $employeeId)
                ->with(['employee', 'department']);

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('leave_type')) {
                $query->where('leave_type', $request->leave_type);
            }

            if ($request->has('from_date')) {
                $query->where('end_date', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('start_date', '<=', $request->to_date);
            }

            // Order by most recent
            $query->orderBy('created_at', 'desc');

            // Paginate
            $perPage = $request->input('per_page', 15);
            $leaves = $query->paginate($perPage);

            // Get employee shift status for off-shift/sick leaves
            $employee = Employee::find($employeeId);
            $shiftStatus = null;

            if (in_array($employee->shift_status, ['off_shift', 'sick_off'])) {
                $shiftStatus = [
                    'status' => $employee->shift_status,
                    'start_date' => $employee->start_off_shift_date,
                    'end_date' => $employee->end_off_shift_date,
                ];
            }

            return response()->json([
                'code' => 1000,
                'message' => 'Leaves retrieved successfully',
                'data' => [
                    'leaves' => $leaves->items(),
                    'shift_status' => $shiftStatus,
                    'pagination' => [
                        'total' => $leaves->total(),
                        'per_page' => $leaves->perPage(),
                        'current_page' => $leaves->currentPage(),
                        'last_page' => $leaves->lastPage(),
                        'from' => $leaves->firstItem(),
                        'to' => $leaves->lastItem(),
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Failed to retrieve leaves: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific leave details
     * GET /api/leaves/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $employeeId = $user->employee->id ?? null;

            $leave = Leave::with(['employee', 'department'])
                ->findOrFail($id);

            // Check authorization - employee can only view their own leaves
            if ($leave->employee_id !== $employeeId) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Unauthorized. You do not have permission to view this leave request.'
                ], 403);
            }

            return response()->json([
                'code' => 1000,
                'message' => 'Leave details retrieved successfully',
                'data' => $leave
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Leave request not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Failed to retrieve leave details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel leave request
     * POST /api/leaves/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = $request->user();
            $employeeId = $user->employee->id ?? null;

            $leave = Leave::findOrFail($id);

            // Check authorization
            if ($leave->employee_id !== $employeeId) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Unauthorized. You do not have permission to cancel this leave request.'
                ], 403);
            }

            // Only pending or approved leaves can be cancelled
            if (!in_array($leave->status, ['pending', 'approved'])) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Only pending or approved leaves can be cancelled'
                ], 400);
            }

            // Update status
            $leave->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'code' => 1000,
                'message' => 'Leave request cancelled successfully',
                'data' => $leave
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Leave request not found'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'code' => 1003,
                'message' => 'Failed to cancel leave request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check for conflicting leaves/off-shifts
     */
    private function getConflictingEmployees(array $employeeIds, string $startDate, string $endDate): array
    {
        $conflictingLeaveIds = Leave::whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_date', '<=', $endDate)
                        ->where('end_date', '>=', $startDate);
                });
            })
            ->pluck('employee_id')
            ->toArray();

        $conflictingOffShiftIds = Employee::whereIn('id', $employeeIds)
            ->whereIn('shift_status', ['off_shift', 'sick_off'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->where('start_off_shift_date', '<=', $endDate)
                        ->where('end_off_shift_date', '>=', $startDate);
                });
            })
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($conflictingLeaveIds, $conflictingOffShiftIds));
    }

    /**
     * Get all available leave types
     * GET /api/leaves/types
     */
    public function leaveTypes()
    {
        $leaveTypes = $this->getAllLeaveTypes();

        return response()->json([
            'code' => 1000,
            'message' => 'Leave types retrieved successfully',
            'data' => array_values($leaveTypes)
        ], 200);
    }

    /**
     * Get all leave types (private helper)
     */
    private function getAllLeaveTypes(): array
    {
        return [
            'sick' => ['id' => 'sick', 'name' => 'Sick Off', 'icon' => '🤒'],
            'offshift' => ['id' => 'offshift', 'name' => 'Off Shift', 'icon' => '🌙'],
            'annual' => ['id' => 'annual', 'name' => 'Annual Leave', 'icon' => '🏖️'],
            'maternity' => ['id' => 'maternity', 'name' => 'Maternity Leave', 'icon' => '🤰'],
            'paternity' => ['id' => 'paternity', 'name' => 'Paternity Leave', 'icon' => '👨‍🍼'],
            'compassionate' => ['id' => 'compassionate', 'name' => 'Compassionate Leave', 'icon' => '🕯️'],
            'study' => ['id' => 'study', 'name' => 'Study Leave', 'icon' => '📚'],
            'unpaid' => ['id' => 'unpaid', 'name' => 'Unpaid Leave', 'icon' => '💸'],
            'personal' => ['id' => 'personal', 'name' => 'Personal Leave', 'icon' => '👤'],
        ];
    }

    /**
     * Get leave type details
     */
    private function getLeaveTypeName(string $leaveType): array
    {
        $leaveTypes = $this->getAllLeaveTypes();
        return $leaveTypes[$leaveType] ?? ['id' => $leaveType, 'name' => ucfirst($leaveType), 'icon' => '📄'];
    }
}
