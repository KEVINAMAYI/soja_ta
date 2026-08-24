<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\LeaveAlternativeDate;
use App\Services\GuestRoute;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GuestLoginController extends Controller
{
    /**
     * Show the guest-facing confirmation screen for a proposed leave date
     * change, resolved entirely from the encrypted redirect_token — no
     * authentication is required to view or action this page.
     */
    public function leaveUpdateGuestLogin(Request $request): View|RedirectResponse
    {
        $token = $request->query('redirect_token');

        if (empty($token)) {
            Log::error('Missing redirect token in guest login request.');
            return redirect()->route('login')
                ->withErrors(['redirect_token' => 'Invalid details provided.']);
        }

        [$error, $data] = $this->resolveGuestAction($token);

        return view('livewire.guest.leave-date-update', [
            'token' => $token,
            'error' => $error,
            'data' => $data,
        ]);
    }

    /**
     * Handle the guest's confirmation of the proposed leave date change.
     */
    public function leaveUpdateGuestConfirm(Request $request): View
    {
        $token = (string) $request->input('redirect_token');

        [$error, $data] = $this->resolveGuestAction($token);

        if ($error === null && $data !== null) {
            $alternative = LeaveAlternativeDate::find($data['alternative']->id);

            if ($alternative && $alternative->status === 'pending') {
                if ($data['action'] === 'accept') {
                    $leave = $alternative->leave;
                    $leave->update([
                        'start_date' => $alternative->new_start_date,
                        'end_date' => $alternative->new_end_date,
                        'num_of_days' => $alternative->new_num_of_days,
                    ]);
                    $alternative->update(['status' => 'approved']);
                } else {
                    $alternative->update(['status' => 'rejected']);
                }

                $data['alternative'] = $alternative->fresh();
            }
        }

        return view('livewire.guest.leave-date-update', [
            'token' => $token,
            'error' => $error,
            'data' => $data,
            'justConfirmed' => $error === null,
        ]);
    }

    /**
     * Decrypt the token and resolve the leave/alternative-date/action it
     * refers to. Returns [errorMessage, data] where data is null on failure.
     */
    private function resolveGuestAction(string $token): array
    {
        if (empty($token)) {
            return ['This link is invalid.', null];
        }

        $queryString = GuestRoute::decryptRedirectToken($token);

        if ($queryString === null) {
            return ['This link is invalid or has expired.', null];
        }

        parse_str($queryString, $params);

        $leaveId = $params['leave_id'] ?? null;
        $action = $params['action'] ?? null;
        $receiverEmail = $params['receiver_email'] ?? null;

        if (empty($leaveId) || empty($receiverEmail) || !in_array($action, ['accept', 'reject'], true)) {
            return ['This link is missing required details.', null];
        }

        $leave = Leave::with(['employee', 'leaveType'])->find($leaveId);

        if (!$leave || !$leave->employee || strcasecmp((string) $leave->employee->email, (string) $receiverEmail) !== 0) {
            return ['We could not verify your details for this request.', null];
        }

        $alternative = LeaveAlternativeDate::where('leave_id', $leaveId)->latest()->first();

        if (!$alternative) {
            return ['No pending date change was found for this leave request.', null];
        }

        return [null, [
            'leave' => $leave,
            'alternative' => $alternative,
            'action' => $action,
            'employeeName' => $leave->employee->name,
            'leaveTypeName' => $leave->leaveType->name ?? $leave->leave_type,
            'originalStartDate' => $leave->start_date->format('d M Y'),
            'originalEndDate' => $leave->end_date->format('d M Y'),
            'newStartDate' => Carbon::parse($alternative->new_start_date)->format('d M Y'),
            'newEndDate' => Carbon::parse($alternative->new_end_date)->format('d M Y'),
            'newNumberOfDays' => $alternative->new_num_of_days,
        ]];
    }
}
