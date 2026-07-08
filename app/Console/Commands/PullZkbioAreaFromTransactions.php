<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ZkbioArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PullZkbioAreaFromTransactions extends Command
{
    protected $signature = 'zkbio:pull-areas-from-transactions
                            {--org=3}
                            {--days=90 : How many days back to look}
                            {--pin= : Single employee by zkbio_pin}';

    protected $description = 'Derive each employee\'s actual area(s) from ZKBio attendance transactions and sync locally';

    public function handle(): int
    {
        $org = Organization::findOrFail((int) $this->option('org'));
        $baseUrl = rtrim(env('ZKBIO_BASE_URL'), '/');
        $token = env('ZKBIO_ACCESS_TOKEN');

        $startDate = now()->subDays((int) $this->option('days'))->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $query = Employee::where('organization_id', $org->id)
            ->where('active', 1)
            ->whereNotNull('zkbio_pin');

        if ($pin = $this->option('pin')) {
            $query->where('zkbio_pin', $pin);
        }

        if ($this->option('missing-only')) {
            $query->whereDoesntHave('zkbioAreas');
        }

        $employees = $query->get();

        $this->info("Checking transactions for {$employees->count()} employees ({$startDate} to {$endDate})...");

        $updated = 0; $noData = 0;

        foreach ($employees as $emp) {
            $response = Http::timeout(15)->get("{$baseUrl}/api/transaction/listAttTransaction", [
                'personPin' => $emp->zkbio_pin,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'pageNo' => 1,
                'pageSize' => 200,
                'access_token' => $token,
            ]);

            $body = $response->json();

            if (($body['code'] ?? -1) !== 0 || empty($body['data'])) {
                $this->warn("NO DATA pin {$emp->zkbio_pin} ({$emp->name}) — no punches in range");
                $noData++;
                continue;
            }

            $areaCodes = collect($body['data'])
                ->pluck('accZone')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $areaNames = collect($body['data'])
                ->pluck('areaName')
                ->filter()
                ->unique()
                ->values()
                ->implode(', ');

            $areaIds = ZkbioArea::where('organization_id', $org->id)
                ->whereIn('area_code', $areaCodes)
                ->pluck('id')
                ->toArray();

            $emp->zkbioAreas()->sync($areaIds);

            $this->line("OK pin {$emp->zkbio_pin} ({$emp->name}) => codes[" . implode(',', $areaCodes) . "] names[{$areaNames}]");
            $updated++;

            usleep(150000);
        }

        $this->newLine();
        $this->info("Done. Updated: {$updated}, No transaction data: {$noData}");
        return 0;
    }
}
