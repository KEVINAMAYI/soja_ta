<?php

namespace App\Mail;

use App\Models\Leave;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Leave $leave;
    public Employee $employee;
    public Employee $supervisor;

    public function __construct(Leave $leave, Employee $employee, Employee $supervisor)
    {
        $this->leave = $leave;
        $this->employee = $employee;
        $this->supervisor = $supervisor;
    }

    public function build()
    {
        $employeeName = trim("{$this->employee->name}");
        $leaveType = $this->leave->leave_type;
        $orgName = $this->employee->organization->name ?? config('app.name');

        \Log::info('LeaveNotificationMail build', [
            'employee' => $employeeName,
            'leave_type' => $leaveType,
            'supervisor' => $this->supervisor->email,
        ]);

        return $this->subject("Leave Request: {$employeeName} — {$leaveType}")
            ->view('emails.leaves.leave-notification')
            ->with([
                'leave' => $this->leave,
                'employee' => $this->employee,
                'supervisor' => $this->supervisor,
                'employeeName' => $employeeName,
                'leaveType' => $leaveType,
                'orgName' => $orgName,
                'startDate' => \Carbon\Carbon::parse($this->leave->start_date)->format('d M Y'),
                'endDate' => \Carbon\Carbon::parse($this->leave->end_date)->format('d M Y'),
                'resumption' => \Carbon\Carbon::parse($this->leave->expected_resumption)->format('d M Y'),
                'reason' => $this->leave->reason ?? 'No reason provided',
                'handoverName' => $this->resolveHandoverName(),
            ]);
    }

    private function resolveSupervisor(Employee $employee): ?Employee
    {
        return Employee::where('organization_id', $employee->organization_id)
            ->whereHas('user', function ($q) {
                $q->whereHas('roles', function ($r) {
                    $r->whereIn('name', ['supervisor', 'manager']);
                });
            })
            ->first();
    }


    private function resolveHandoverName(): ?string
    {
        if (!$this->leave->handover_to) {
            return null;
        }
        return $this->leave->handover_to;
    }
}
