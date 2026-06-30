<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\CheckInApprovalRequest;
use App\Services\CheckInApprovalService;
use Illuminate\Http\Request;

class CheckInApprovalController extends Controller
{
    /**
     * GET /api/checkin-requests
     *
     * List check-in approval requests for the logged-in user's organization.
     *
     * Query params:
     *   status       = pending | approved | rejected  (optional, default: all)
     *   date         = YYYY-MM-DD                     (optional, filter by date)
     *   start_date   = YYYY-MM-DD                     (optional, date range start)
     *   end_date     = YYYY-MM-DD                     (optional, date range end)
     *   employee_id  = int                            (optional)
     *   per_page     = int                            (optional, default: 20)
     */
    public function index(Request $request)
    {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
        }

        if (!auth()->user()->can('approve-checkin-requests')) {
            return response()->json(['code' => 1003, 'message' => 'You do not have permission to view check-in requests.'], 403);
        }

        $query = CheckInApprovalRequest::with(['employee', 'activeWindowLog'])
            ->where('organization_id', $employee->organization_id);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by specific date
        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        // Filter by date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // Filter by employee
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $perPage = min((int)($request->per_page ?? 20), 100);

        $requests = $query->orderByDesc('submitted_at')->paginate($perPage);

        return response()->json([
            'code' => 1000,
            'message' => 'Check-in requests retrieved successfully.',
            'data' => $requests->map(fn($r) => $this->formatRequest($r)),
            'meta' => [
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/checkin-requests/{id}
     *
     * Get a single check-in request with full window log history.
     */
    public function show(int $id)
    {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
        }

        $approvalRequest = CheckInApprovalRequest::with(['employee', 'windowLogs', 'resolvedBy'])
            ->where('organization_id', $employee->organization_id)
            ->find($id);

        if (!$approvalRequest) {
            return response()->json(['code' => 1003, 'message' => 'Request not found.'], 404);
        }

        // Employee can see their own request; approvers can see all
        $isSelf = $approvalRequest->employee_id === $employee->id;
        if (!$isSelf && !auth()->user()->can('approve-checkin-requests')) {
            return response()->json(['code' => 1003, 'message' => 'You do not have permission to view this request.'], 403);
        }

        return response()->json([
            'code' => 1000,
            'message' => 'Check-in request retrieved successfully.',
            'data' => $this->formatRequest($approvalRequest, detailed: true),
        ]);
    }

    /**
     * POST /api/checkin-requests/{id}/action
     *
     * Approve or reject a pending check-in request.
     *
     * Body:
     *   action = approved | rejected   (required)
     *   notes  = string               (optional)
     */
    public function action(Request $request, int $id)
    {
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected',
            'notes'  => 'nullable|string|max:500',
        ]);

        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
        }

        if (!auth()->user()->can('approve-checkin-requests')) {
            return response()->json(['code' => 1003, 'message' => 'You do not have permission to action check-in requests.'], 403);
        }

        $approvalRequest = CheckInApprovalRequest::with('employee')
            ->where('organization_id', $employee->organization_id)
            ->find($id);

        if (!$approvalRequest) {
            return response()->json(['code' => 1003, 'message' => 'Request not found.'], 404);
        }

        if (!$approvalRequest->isPending()) {
            return response()->json([
                'code' => 1003,
                'message' => "This request has already been {$approvalRequest->status}.",
                'current_status' => $approvalRequest->status,
            ], 409);
        }

        $resolved = app(CheckInApprovalService::class)->resolve(
            $approvalRequest,
            $validated['action'],
            auth()->id(),
            $validated['notes'] ?? null,
        );

        $message = $validated['action'] === 'approved'
            ? "Check-in approved. {$approvalRequest->employee->name} is now clocked in."
            : "Check-in rejected. {$approvalRequest->employee->name} remains absent for this day.";

        return response()->json([
            'code' => 1000,
            'message' => $message,
            'data' => $this->formatRequest($resolved->fresh(['employee', 'resolvedBy'])),
        ]);
    }

    /**
     * GET /api/checkin-requests/my-requests
     *
     * Employee sees their own pending/past requests.
     */
    public function myRequests(Request $request)
    {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json(['code' => 1003, 'message' => 'No employee profile found.'], 404);
        }

        $query = CheckInApprovalRequest::with(['activeWindowLog'])
            ->where('employee_id', $employee->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderByDesc('submitted_at')->paginate(20);

        return response()->json([
            'code' => 1000,
            'message' => 'Your check-in requests retrieved successfully.',
            'data' => $requests->map(fn($r) => $this->formatRequest($r)),
            'meta' => [
                'total' => $requests->total(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    private function formatRequest(CheckInApprovalRequest $r, bool $detailed = false): array
    {
        $data = [
            'id'              => $r->id,
            'status'          => $r->status,
            'date'            => $r->date?->format('Y-m-d'),
            'check_in_time'   => $r->check_in_time?->format('H:i:s'),
            'minutes_late'    => $r->minutes_late,
            'current_window'  => $r->current_window,
            'submitted_at'    => $r->submitted_at?->utc()->toIso8601String(),
            'resolved_at'     => $r->resolved_at?->utc()->toIso8601String(),
            'notes'           => $r->notes,
            'employee'        => $r->employee ? [
                'id'          => $r->employee->id,
                'name'        => $r->employee->name,
                'id_number'   => $r->employee->id_number,
                'department'  => $r->employee->department?->name,
            ] : null,
            'active_window'   => $r->activeWindowLog ? [
                'window_number'     => $r->activeWindowLog->window_number,
                'approver_role'     => $r->activeWindowLog->approver_role,
                'expires_at'        => $r->activeWindowLog->expires_at?->utc()->toIso8601String(),
                'minutes_remaining' => max(0, now()->diffInMinutes($r->activeWindowLog->expires_at, false)),
            ] : null,
        ];

        if ($detailed) {
            $data['window_logs'] = $r->windowLogs->map(fn($l) => [
                'window_number'  => $l->window_number,
                'approver_role'  => $l->approver_role,
                'status'         => $l->status,
                'opened_at'      => $l->opened_at?->utc()->toIso8601String(),
                'expires_at'     => $l->expires_at?->utc()->toIso8601String(),
                'closed_at'      => $l->closed_at?->utc()->toIso8601String(),
                'on_timeout'     => $l->on_timeout_action,
                'actioned_by'    => $l->actionedBy?->name,
            ])->toArray();
            $data['resolved_by']  = $r->resolvedBy?->name;
            $data['attendance_id'] = $r->attendance_id;
        }

        return $data;
    }
}
