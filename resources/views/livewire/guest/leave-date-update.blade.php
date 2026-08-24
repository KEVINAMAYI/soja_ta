<?php

use App\Models\Leave;
use App\Models\LeaveAlternativeDate;
use App\Services\GuestRoute;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.guest')]
class extends Component
{
    public string $token = '';
    public ?string $error = null;
    public ?array $data = null;
    public bool $justConfirmed = false;

    /**
     * Guest-facing confirmation screen for a proposed leave date change.
     * Reached via an encrypted redirect_token — no authentication required.
     * All state is resolved server-side from the token here on mount.
     */
    public function mount(): void
    {
        $this->token = (string) request()->query('redirect_token', '');

        [$this->error, $this->data] = $this->resolveGuestAction($this->token);
    }

    public function confirm(): void
    {
        [$error, $data] = $this->resolveGuestAction($this->token);

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

                Log::info('Guest leave date update actioned', [
                    'leave_alternative_date_id' => $alternative->id,
                    'action' => $data['action'],
                ]);

                $data['alternative'] = $alternative->fresh();
            }

            $this->justConfirmed = true;
        }

        $this->error = $error;
        $this->data = $data;
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

        // TO DO(SIR-DOMMY): Uncomment the following logic after testing to enforce that only pending alternative dates can be actioned.
        $alternative = LeaveAlternativeDate::where('leave_id', $leaveId)
            ->where('status', 'pending')
            ->latest()->first();

        if (!$alternative) {
            return ['Expired Link!.', null];
        }

        // add function to effect this change in leave approval process
        $service = app(\App\Services\LeaveApprovalService::class);
        $service->actionOnLeaveDatesChange($action, $leaveId, $alternative);

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
}; ?>

<div>
@php
    $isAccept = ($data['action'] ?? null) === 'accept';
    $alreadyActioned = !$error && $data['alternative']->status !== 'pending';
    $showSuccess = !$error && $justConfirmed;
@endphp

    <div class="text-center mb-4">
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
              style="width:64px;height:64px;background:{{ $error ? '#fdecea' : ($isAccept ? '#e6f6ee' : '#fdecea') }};">
            @if($error)
                <iconify-icon icon="solar:danger-triangle-bold-duotone" style="font-size:32px;color:#e35c4f;"></iconify-icon>
            @elseif($isAccept)
                <iconify-icon icon="solar:calendar-check-bold-duotone" style="font-size:32px;color:#1f9d66;"></iconify-icon>
            @else
                <iconify-icon icon="solar:calendar-mark-bold-duotone" style="font-size:32px;color:#e35c4f;"></iconify-icon>
            @endif
        </span>

        <h4 class="fw-bold mb-1">
            @if($error)
                Link Unavailable
            @elseif($isAccept)
                Confirm New Leave Dates
            @else
                Decline New Leave Dates
            @endif
        </h4>
        <p class="text-muted mb-0">
            @if($error)
                We couldn't process this request.
            @else
                Please review the details below before confirming your response.
            @endif
        </p>
    </div>

    @if($error)
        <div class="alert alert-danger text-center" role="alert">
            {{ $error }}
        </div>
        <div class="text-center">
            <a href="{{ route('login') }}" class="btn btn-outline-secondary">Go to Login</a>
        </div>
    @else
        <div class="card border-0 shadow-sm mb-4" style="background:#f8fafc;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small">Employee</span>
                    <span class="fw-semibold">{{ $data['employeeName'] }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small">Leave Type</span>
                    <span class="fw-semibold">{{ $data['leaveTypeName'] }}</span>
                </div>

                <hr>

                <div class="row text-center g-2">
                    <div class="col-5">
                        <div class="small text-muted mb-1">Original Dates</div>
                        <div class="fw-semibold text-decoration-line-through text-muted">{{ $data['originalStartDate'] }}</div>
                        <div class="fw-semibold text-decoration-line-through text-muted">{{ $data['originalEndDate'] }}</div>
                    </div>
                    <div class="col-2 d-flex align-items-center justify-content-center">
                        <iconify-icon icon="solar:round-arrow-right-bold" style="font-size:22px;color:#0a2540;"></iconify-icon>
                    </div>
                    <div class="col-5">
                        <div class="small text-muted mb-1">Proposed Dates</div>
                        <div class="fw-bold" style="color:#0a2540;">{{ $data['newStartDate'] }}</div>
                        <div class="fw-bold" style="color:#0a2540;">{{ $data['newEndDate'] }}</div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <span class="badge rounded-pill" style="background:#eef4fa;color:#0a2540;">
                        {{ $data['newNumberOfDays'] }} day{{ $data['newNumberOfDays'] == 1 ? '' : 's' }} total
                    </span>
                </div>
            </div>
        </div>

        <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="btn w-100 py-2 fw-semibold"
                style="background:{{ $isAccept ? '#1f9d66' : '#e35c4f' }};color:#fff;">
            <span wire:loading.remove wire:target="confirm">
                <iconify-icon icon="{{ $isAccept ? 'solar:check-circle-bold' : 'solar:close-circle-bold' }}" style="font-size:20px;vertical-align:-4px;margin-right:6px;"></iconify-icon>
                {{ $isAccept ? 'Change Accepted' : 'Change Rejected' }}
            </span>
            <span wire:loading wire:target="confirm">Processing...</span>
        </button>
    @endif
</div>
