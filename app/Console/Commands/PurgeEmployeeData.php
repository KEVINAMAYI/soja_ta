<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use App\Models\EmployeeAssignment;
use App\Models\Leave;
use App\Models\Overtime;

class PurgeEmployeeData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     * php artisan employee:purge {employee_id}
     */
    protected $signature = 'employee:purge {employee_id} {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     */
    protected $description = 'Safely delete an employee, related records, and linked user account.';

    public function handle()
    {
        $employeeId = (int)$this->argument('employee_id');
        $force = $this->option('force');

        $employee = Employee::with('user')->find($employeeId);

        if (!$employee) {
            $this->error("❌ Employee #{$employeeId} not found.");
            return Command::FAILURE;
        }

        $user = $employee->user;

        $this->warn("You are about to permanently delete:");
        $this->line("👤 Employee #{$employee->id}: {$employee->name}");
        if ($user) {
            $this->line("🔗 Linked User: {$user->email} (User ID: {$user->id})");
        }
        $this->newLine();

        // Count related records
        $counts = [
            'attendances' => Attendance::where('employee_id', $employeeId)->count(),
            'employee_assignments' => EmployeeAssignment::where('employee_id', $employeeId)->count(),
            'leaves' => Leave::where('employee_id', $employeeId)->count(),
            'overtimes' => Overtime::where('employee_id', $employeeId)->count(),
        ];

        $this->info('🧾 Records to delete:');
        foreach ($counts as $table => $count) {
            $this->line("- {$table}: {$count}");
        }

        $this->newLine();

        if (!$force && !$this->confirm('⚠️  Continue with deletion? This action cannot be undone.')) {
            $this->info('❎ Operation cancelled.');
            return Command::SUCCESS;
        }

        DB::beginTransaction();

        try {
            // Delete related records
            Attendance::where('employee_id', $employeeId)->delete();
            EmployeeAssignment::where('employee_id', $employeeId)->delete();
            Leave::where('employee_id', $employeeId)->delete();
            Overtime::where('employee_id', $employeeId)->delete();

            // Delete employee and linked user
            $employee->delete();

            if ($user) {
                $user->delete();
            }

            DB::commit();

            Log::info('Employee and related data purged successfully', [
                'employee_id' => $employeeId,
                'user_id' => $user?->id,
                'deleted_by' => auth()?->id() ?? 'system',
                'counts' => $counts,
                'timestamp' => now()->toDateTimeString(),
            ]);

            $this->newLine();
            $this->info("✅ Successfully deleted employee #{$employeeId} and all related data.");

            if ($user) {
                $this->info("🧹 Linked user account (ID {$user->id}) also deleted.");
            }

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Employee purge failed', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            $this->error('❌ Failed to delete employee data: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
