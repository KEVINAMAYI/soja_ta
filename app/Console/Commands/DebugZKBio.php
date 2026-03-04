<?php

namespace App\Console\Commands;

use App\Services\ZKBioService;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DebugZKBio extends Command
{
    protected $signature   = 'zkbio:debug';
    protected $description = 'Debug ZKBio integration';

    public function handle(ZKBioService $zkbio): void
    {
        $deviceSn = config('zkbio.device_sn');
        $this->info("Device SN: {$deviceSn}");
        $this->info("Base URL: " . config('zkbio.base_url'));
        $this->info("Token: " . substr(config('zkbio.access_token'), 0, 10) . '...');
        $this->info("Default Org ID: " . config('zkbio.default_organization_id'));
        $this->info("Default Dept ID: " . config('zkbio.default_department_id'));
        $this->info("Default Shift ID: " . config('zkbio.default_shift_id'));

        $this->newLine();

        // Fetch raw transactions
        $todayEAT = Carbon::now('Africa/Nairobi');
        $this->info("Today EAT: " . $todayEAT->toDateTimeString());

        $transactions = $zkbio->getTransactions(
            $deviceSn,
            pageNo: 1,
            pageSize: 20,
            startDate: $todayEAT->copy()->startOfDay()->utc()->format('Y-m-d'),
            endDate: $todayEAT->copy()->endOfDay()->utc()->format('Y-m-d')
        );

        $this->info("Transactions fetched: " . count($transactions));
        $this->newLine();

        foreach ($transactions as $tx) {
            $this->line("  PIN: {$tx['pin']} | Name: {$tx['name']} | Event: {$tx['eventName']} | Time: {$tx['eventTime']}");

            // Convert time
            $eat = Carbon::parse($tx['eventTime'], 'UTC')->setTimezone('Africa/Nairobi');
            $this->line("  → EAT time: " . $eat->toDateTimeString() . " | Date: " . $eat->toDateString());

            // Check employee
            $emp = Employee::where('zkbio_pin', $tx['pin'])->first();
            if ($emp) {
                $this->info("  ✓ Employee found: {$emp->name} (ID: {$emp->id})");
            } else {
                $this->warn("  ✗ No employee with zkbio_pin={$tx['pin']}");
                $emp = Employee::where('name', trim($tx['name'] . ' ' . $tx['lastName']))->first();
                $emp
                    ? $this->warn("    → Name match found: {$emp->name}")
                    : $this->error("    → No name match either");
            }

            $this->newLine();
        }

        // Check if zkbio_pin column exists
        $this->newLine();
        $hasColumn = \Schema::hasColumn('employees', 'zkbio_pin');
        $this->info("employees.zkbio_pin column exists: " . ($hasColumn ? 'YES' : 'NO — run migration!'));
    }
}
