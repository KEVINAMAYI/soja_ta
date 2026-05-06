<?php

namespace App\Jobs;

use App\Models\ZkbioProcessedLog;
use App\Services\ZKBioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillZKBioTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600; // 10 min — backfill windows can be large

    public function __construct(
        public string $startDate,   // Y-m-d H:i:s
        public string $endDate,     // Y-m-d H:i:s
        public ?int   $organizationId = null  // null = all orgs
    ) {}

    public function handle(ZKBioService $zkbio): void
    {
        $query = \App\Models\Organization::where('zkbio_enabled', true)
            ->whereNotNull('zkbio_device_sn')
            ->whereNotNull('zkbio_access_token');

        if ($this->organizationId) {
            $query->where('id', $this->organizationId);
        }

        $organizations = $query->get();

        if ($organizations->isEmpty()) {
            Log::info('ZKBio backfill skipped — no organizations with ZKBio enabled');
            return;
        }

        foreach ($organizations as $org) {
            $this->backfillForOrganization($zkbio, $org);
        }
    }

    private function backfillForOrganization(ZKBioService $zkbio, $org): void
    {
        Log::info('ZKBio backfill window', [
            'org'   => $org->name,
            'from'  => $this->startDate,
            'to'    => $this->endDate,
        ]);

        $deviceSns = array_filter(array_map('trim', explode(',', $org->zkbio_device_sn)));

        foreach ($deviceSns as $deviceSn) {
            $this->backfillForDevice($zkbio, $org, $deviceSn);
        }
    }

    private function backfillForDevice(ZKBioService $zkbio, $org, string $deviceSn): void
    {
        try {
            // Paginate until we have all transactions in the window
            $transactions = [];
            $pageNo       = 1;
            $pageSize     = 200;

            do {
                $batch = $zkbio->getTransactions(
                    deviceSn:    $deviceSn,
                    pageNo:      $pageNo,
                    pageSize:    $pageSize,
                    startDate:   $this->startDate,
                    endDate:     $this->endDate,
                    baseUrl:     $org->zkbio_base_url,
                    accessToken: $org->zkbio_access_token,
                );
                $transactions = array_merge($transactions, $batch);
                $pageNo++;
            } while (count($batch) === $pageSize);

            if (empty($transactions)) {
                Log::info('ZKBio backfill — no transactions in window', [
                    'org'    => $org->name,
                    'device' => $deviceSn,
                ]);
                return;
            }

            // Same dedup logic as FetchZKBioTransactions
            $incomingLogIds = array_filter(array_column($transactions, 'logId'));
            $alreadyProcessed = ZkbioProcessedLog::whereIn('log_id', $incomingLogIds)
                ->pluck('log_id')->toArray();

            $newTransactions = array_values(array_filter(
                $transactions,
                fn($tx) => !empty($tx['logId'])
                    && ($tx['logId'] ?? -1) !== -1
                    && ($tx['eventNo'] ?? -1) === 0
                    && !empty($tx['pin'])
                    && !in_array($tx['logId'], $alreadyProcessed)
            ));

            if (empty($newTransactions)) {
                Log::info('ZKBio backfill — all transactions already processed', [
                    'org'    => $org->name,
                    'device' => $deviceSn,
                ]);
                return;
            }

            // Sort oldest → newest
            usort($newTransactions, fn($a, $b) => strcmp($a['eventTime'], $b['eventTime']));

            Log::info('ZKBio backfill — new transactions found', [
                'org'    => $org->name,
                'device' => $deviceSn,
                'count'  => count($newTransactions),
                'logIds' => array_column($newTransactions, 'logId'),
            ]);

            // Group by (pin, date) so ProcessZKBioTransactions always receives same-day transactions
            $grouped = collect($newTransactions)->groupBy(
                fn($tx) => ($tx['pin'] ?? '') . '|' . substr($tx['eventTime'] ?? '', 0, 10)
            );

            foreach ($grouped as $key => $pinDateTransactions) {
                [$pin] = explode('|', $key);
                if (!$pin) continue;

                try {
                    ProcessZKBioTransactions::dispatchSync(
                        $pinDateTransactions->values()->all(),
                        $org->id
                    );

                    ZkbioProcessedLog::insert(
                        $pinDateTransactions->map(fn($tx) => [
                            'log_id'          => $tx['logId'],
                            'device_sn'       => $deviceSn,
                            'pin'             => $tx['pin'] ?? null,
                            'organization_id' => $org->id,
                            'processed_at'    => now(),
                        ])->all()
                    );

                } catch (\Throwable $e) {
                    Log::error('ZKBio backfill job failed for pin/date', [
                        'pin'    => $pin,
                        'org'    => $org->name,
                        'device' => $deviceSn,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Throwable $e) {
            Log::error('ZKBio backfill fetch failed for device', [
                'org'    => $org->name,
                'device' => $deviceSn,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
