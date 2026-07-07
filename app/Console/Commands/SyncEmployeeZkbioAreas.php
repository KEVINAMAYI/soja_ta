<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncEmployeeZkbioAreas extends Command
{
    protected $signature = 'zkbio:sync-employee-areas {--org=3} {--dry-run} {--pin=}';
    protected $description = 'Remove employees from all ZKBio areas, reassign per department -> area_department';

    public function handle(): int
    {
        $baseUrl = rtrim(env('ZKBIO_URL'), '/');
        $accessToken = env('ZKBIO_ACCESS_TOKEN');

        if (!$baseUrl || !$accessToken) {
            $this->error('Set ZKBIO_URL and ZKBIO_ACCESS_TOKEN in .env');
            return 1;
        }

        // department_id => [area codes]
        $deptAreas = DB::table('area_department')->get()
            ->groupBy('department_id')
            ->map(fn ($rows) => $rows->pluck('area_id')->unique()->values()->all());

        // Every area code in play — remove everyone from all of these first
        $allAreaCodes = DB::table('area_department')->distinct()->pluck('area_id')->all();

        $query = Employee::where('organization_id', (int) $this->option('org'))
            ->where('active', 1)
            ->whereNotNull('zkbio_pin')
            ->whereNotNull('department_id');

        if ($pin = $this->option('pin')) {
            $query->where('zkbio_pin', $pin);
        }

        $employees = $query->get();
        $this->info("Processing {$employees->count()} employees...");

        $ok = 0; $skipped = 0; $failed = 0;

        foreach ($employees as $emp) {
            $targetCodes = $deptAreas[$emp->department_id] ?? [];

            if (empty($targetCodes)) {
                $this->warn("SKIP pin {$emp->zkbio_pin} ({$emp->name}): dept {$emp->department_id} not mapped");
                $skipped++;
                continue;
            }

            $removeCodes = array_values(array_diff($allAreaCodes, $targetCodes));

            if ($this->option('dry-run')) {
                $this->line("DRY  pin {$emp->zkbio_pin} ({$emp->name}): remove [" . implode(',', $removeCodes) . "] assign [" . implode(',', $targetCodes) . "]");
                $ok++;
                continue;
            }

            $success = true;

            // 1. Remove from every area that's not a target
            foreach ($removeCodes as $code) {
                $body = Http::timeout(10)
                    ->post("{$baseUrl}/api/attAreaPerson/delete?access_token={$accessToken}", [
                        'code' => (string) $code,
                        'pins' => [(int) $emp->zkbio_pin],
                    ])->json();
                if (($body['code'] ?? -1) !== 0) {
                    $this->error("  remove {$code} failed for pin {$emp->zkbio_pin}: " . json_encode($body));
                    $success = false;
                }
            }

            // 2. Assign to department's areas
            foreach ($targetCodes as $code) {
                $body = Http::timeout(10)
                    ->post("{$baseUrl}/api/attAreaPerson/set?access_token={$accessToken}", [
                        'code' => (string) $code,
                        'pins' => [(int) $emp->zkbio_pin],
                    ])->json();
                if (($body['code'] ?? -1) !== 0) {
                    $this->error("  assign {$code} failed for pin {$emp->zkbio_pin}: " . json_encode($body));
                    $success = false;
                }
            }

            if ($success) {
                $this->info("OK   pin {$emp->zkbio_pin} ({$emp->name}) => [" . implode(',', $targetCodes) . "]");
                $ok++;
            } else {
                $failed++;
            }

            usleep(200000);
        }

        $this->newLine();
        $this->info("Done. OK: {$ok}, Skipped: {$skipped}, Failed: {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
