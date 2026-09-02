<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\LeaveApprovalService;
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
                // leave_type_id is the canonical field now; leave_type (code string) is kept
                // as a fallback for older clients that haven't switched over yet.
                'leave_type_id' => 'nullable|exists:leave_types,id',
                'leave_type' => 'required_without:leave_type_id|nullable|string',
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
            $startDate = $request->start_date;
            $durationType = $request->duration_type;


            // Get employee details
            $employee = Employee::with('department', 'shift')->findOrFail($employeeId);
            $orgId = $employee->organization_id;
            $departmentId = $employee->department_id;

            // Resolve the leave type dynamically for this organization
            $leaveTypeQuery = LeaveType::where('organization_id', $orgId)->where('is_active', true);
            $leaveType = $request->filled('leave_type_id')
                ? $leaveTypeQuery->find($request->leave_type_id)
                : $leaveTypeQuery->where('code', $request->leave_type)->first();

            if (!$leaveType) {
                DB::rollBack();
                return response()->json([
                    'code' => 1003,
                    'message' => 'Invalid or unrecognized leave type for this organization.',
                ], 422);
            }

            $numberOfDays = 0;
            // Calculate end date
            if ($durationType === 'numberOfDays') {
                $start = Carbon::parse($startDate)->format('Y-m-d');
                $end = $leaveType->calculateEndDateWithStartDateAndNumberOfDays($start, intVal($request->number_of_days));
                $numberOfDays = intVal($request->number_of_days);
                $endDate = $end['end_date']->format('Y-m-d');
            } else {
                $endDate = $request->end_date;

                $numberOfDays = $leaveType->calculateNumberOfDaysFromLeaveStartAndEndDates(Carbon::parse($startDate)->format('Y-m-d'), Carbon::parse($endDate)->format('Y-m-d'))['effective_leave_days'];
            }

            // use this as default behavior for employees without shifts
            $returnDate =  Carbon::parse($endDate)->copy()->addDay();
            if ($employee->shift) {
                $returnDate = $leaveType->calculateReturnWithEndDate(Carbon::parse($endDate), $employee->shift);
            }
            $returnDate = Carbon::parse($returnDate)->format('Y-m-d');
            $endDate = Carbon::parse($endDate)->format('Y-m-d');
            $startDate = Carbon::parse($startDate)->format('Y-m-d');
            
            // Check for conflicts
            $conflicts = $this->getConflictingEmployees([$employeeId], $startDate, $endDate);

            if (!empty($conflicts)) {
                DB::rollBack();
                return response()->json([
                    'code' => 1003,
                    'message' => 'You have conflicting leave or off-shift status for the requested dates.',
                ], 409);
            }


            

            // Handle different leave types
            if ($leaveType->code === 'offshift') {
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

            } elseif ($leaveType->code === 'sick') {
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
                // Check leave balance before creating the request
                $requestedDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
                $balanceCheck = app(LeaveApprovalService::class)->checkBalance(
                    $employee,
                    $leaveType,
                    $requestedDays,
                    Carbon::parse($startDate)->year
                );

                if (!$balanceCheck['ok']) {
                    DB::rollBack();
                    return response()->json([
                        'code' => 1003,
                        'message' => 'Insufficient leave balance for ' . $leaveType->name
                            . '. Requested ' . $requestedDays . ' day(s), '
                            . max(0, $balanceCheck['remaining']) . ' day(s) remaining.',
                        'data' => [
                            'requested_days' => $requestedDays,
                            'remaining_days' => $balanceCheck['remaining'],
                        ],
                    ], 422);
                }

                // Create leave request
                $leave = Leave::create([
                    'organization_id' => $orgId,
                    'employee_id' => $employeeId,
                    'department_id' => $departmentId,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'num_of_days' => $numberOfDays,
                    'reason' => $request->reason,
                    'contact_during_leave' => $request->contact_during_leave,
                    'emergency_contact' => $request->emergency_contact,
                    'handover_to' => $request->handover_to,
                    'expected_resumption' => $returnDate,
                    'status' => 'pending',
                ]);

                DB::commit();

                // Kick off the dynamic multi-level approval chain (no-op if not configured)
                app(LeaveApprovalService::class)->createApprovalChain($leave);

                $leave = $leave->fresh();

                return response()->json([
                    'code' => 1000,
                    'message' => 'Leave request submitted successfully. Pending review.',
                    'data' => [
                        'leave_id' => $leave->id,
                        'employee_id' => $employeeId,
                        'leave_type' => $leaveType->name,
                        'start_date' => $startDate,
                        'end_date' => $leave->end_date->format('Y-m-d'),
                        'number_of_days' => $leave->num_of_days,
                        'status' => 'pending',
                        'expected_resumption' => $leave->expected_resumption->format('Y-m-d')
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
                ->with(['employee', 'department', 'approvalLogs.approverUser', 'approvalLogs.actionedBy']);

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
            $leaves->getCollection()->each(fn (Leave $leave) => $leave->append('approval_progress'));

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

            $leave = Leave::with(['employee', 'department', 'approvalLogs.approverUser', 'approvalLogs.actionedBy'])
                ->findOrFail($id);

            // Check authorization - employee can only view their own leaves
            if ($leave->employee_id !== $employeeId) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Unauthorized. You do not have permission to view this leave request.'
                ], 403);
            }

            $leave->append('approval_progress');

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
     * Get all available leave types for the authenticated user's organization
     * GET /api/leaves/types
     */
    public function leaveTypes(Request $request)
    {
        $orgId = $request->user()->employee->organization_id ?? null;

        if (!$orgId) {
            return response()->json([
                'code' => 1003,
                'message' => 'Employee record not found for authenticated user'
            ], 404);
        }

        $leaveTypes = LeaveType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'icon', 'annual_entitlement_days']);

        return response()->json([
            'code' => 1000,
            'message' => 'Leave types retrieved successfully',
            'data' => $leaveTypes
        ], 200);
    }

    /**
     * Get the authenticated employee's leave balance (entitled/used/pending/remaining
     * days) for every active leave type, so an applicant can see their balance
     * before applying.
     * GET /api/leaves/balances
     */
    public function balances(Request $request)
    {
        $employee = $request->user()->employee;

        if (!$employee) {
            return response()->json([
                'code' => 1003,
                'message' => 'Employee record not found for authenticated user'
            ], 404);
        }

        $year = (int) $request->input('year', now()->year);

        $balances = app(LeaveApprovalService::class)->balancesForEmployee($employee, $year);

        return response()->json([
            'code' => 1000,
            'message' => 'Leave balances retrieved successfully',
            'data' => $balances
        ], 200);
    }

    /**
     * Get the approval-chain progress for a specific leave request
     * GET /api/leaves/{id}/approval-progress
     */
    public function approvalProgress(Request $request, $id)
    {
        try {
            $user = $request->user();
            $employeeId = $user->employee->id ?? null;

            $leave = Leave::with(['approvalLogs.approverUser', 'approvalLogs.actionedBy'])
                ->findOrFail($id);

            if ($leave->employee_id !== $employeeId) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Unauthorized. You do not have permission to view this leave request.'
                ], 403);
            }

            return response()->json([
                'code' => 1000,
                'message' => 'Approval progress retrieved successfully',
                'data' => $leave->approval_progress
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Leave request not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1003,
                'message' => 'Failed to retrieve approval progress: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the current pending level of a leave request
     * POST /api/leaves/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        return $this->actionLeave($request, $id, 'approve');
    }

    /**
     * Reject the current pending level of a leave request
     * POST /api/leaves/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        return $this->actionLeave($request, $id, 'reject');
    }

    private function actionLeave(Request $request, $id, string $action)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 1003,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $employeeOrgId = $user->employee->organization_id ?? null;

        $leave = Leave::where('organization_id', $employeeOrgId)->find($id);

        if (!$leave) {
            return response()->json([
                'code' => 1003,
                'message' => 'Leave request not found'
            ], 404);
        }

        if (!$leave->isPending()) {
            return response()->json([
                'code' => 1003,
                'message' => 'This leave request has already been resolved.'
            ], 409);
        }

        // Authorization is driven entirely by the configured approval chain —
        // whoever is designated as the current level's approver (by role or
        // specific user) may act, regardless of any blanket permission.
        if (!app(LeaveApprovalService::class)->canAct($leave, $user)) {
            return response()->json([
                'code' => 1003,
                'message' => 'You are not authorized to action this approval level.'
            ], 403);
        }

        try {
            $leave = $action === 'approve'
                ? app(LeaveApprovalService::class)->approve($leave, $user, $request->notes)
                : app(LeaveApprovalService::class)->reject($leave, $user, $request->notes);

            return response()->json([
                'code' => 1000,
                'message' => $action === 'approve' ? 'Leave approved.' : 'Leave rejected.',
                'data' => $leave
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'code' => 1003,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}
