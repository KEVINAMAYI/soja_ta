<?php

namespace App\Jobs;

use App\Mail\LeaveNotificationMail;
use App\Models\Leave;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendLeaveNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $leaveId;

    public function __construct(int $leaveId)
    {
        $this->leaveId = $leaveId;
    }

    public function handle(): void
    {
        
        try {
            $leave = Leave::with(['employee.organization', 'department'])->find($this->leaveId);

            if (!$leave) {
                Log::warning('Leave not found in job', ['leave_id' => $this->leaveId]);
                return;
            }

            $employee = $leave->employee;
            $supervisor = $this->resolveSupervisor($employee);

            if (!$supervisor || !$supervisor->email) {
                Log::warning('No supervisor found for employee', [
                    'employee_id' => $employee->id,
                    'leave_id'    => $this->leaveId,
                ]);
                return;
            }

            Mail::to($supervisor->email)
                ->send(new LeaveNotificationMail($leave, $employee, $supervisor));


        } catch (\Throwable $e) {
            Log::error('SendLeaveNotificationJob failed', [
                'leave_id' => $this->leaveId,
                'error'    => $e->getMessage(),
                'line'     => $e->getLine(),
                'file'     => $e->getFile(),
            ]);

            throw $e;
        }
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
}
