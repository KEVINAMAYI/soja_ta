<?php

namespace App\Jobs;

use App\Jobs\ProcessZKBioTransactions;
use App\Models\ZkbioProcessedLog;
use App\Services\ZKBioService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchZKBioTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public bool $manual = false) {}

    public function handle(ZKBioService $zkbio): void
    {
        $organizations = \App\Models\Organization::where('zkbio_enabled', true)
            ->whereNotNull('zkbio_device_sn')
            ->whereNotNull('zkbio_access_token')
            ->get();

        if ($organizations->isEmpty()) {
            Log::info('ZKBio fetch skipped — no organizations with ZKBio enabled');
            return;
        }

        foreach ($organizations as $org) {
            $this->fetchForOrganization($zkbio, $org);
        }
    }

    private function fetchForOrganization(ZKBioService $zkbio, $org): void
    {
        // Always look back 5 minutes — DB dedup handles duplicates
        $startDate = now()->subMinutes(5)->format('Y-m-d H:i:s');
        $endDate   = now()->format('Y-m-d H:i:s');

        Log::info('ZKBio fetch window', [
            'org'    => $org->name,
            'from'   => $startDate,
            'to'     => $endDate,
            'manual' => $this->manual,
        ]);

        $deviceSns = array_filter(array_map('trim', explode(',', $org->zkbio_device_sn)));

        foreach ($deviceSns as $deviceSn) {
            $this->fetchForDevice($zkbio, $org, $deviceSn, $startDate, $endDate);
        }
    }

    private function fetchForDevice(ZKBioService $zkbio, $org, string $deviceSn, string $startDate, string $endDate): void
    {
        try {
            $transactions = $zkbio->getTransactions(
                deviceSn:    $deviceSn,
                pageNo:      1,
                pageSize:    50,
                startDate:   $startDate,
                endDate:     $endDate,
                baseUrl:     $org->zkbio_base_url,
                accessToken: $org->zkbio_access_token,
            );

            if (empty($transactions)) {
                Log::info('ZKBio no transactions in window', ['org' => $org->name, 'device' => $deviceSn]);
                return;
            }

            // ── Filter out already processed logIds ──
            $incomingLogIds = array_filter(array_column($transactions, 'logId'));
            $alreadyProcessed = ZkbioProcessedLog::whereIn('log_id', $incomingLogIds)
                ->pluck('log_id')->toArray();

            $newTransactions = array_values(array_filter(
                $transactions,
                fn($tx) => !empty($tx['logId'])
                    && ($tx['logId'] ?? -1) !== -1          // ← skip disconnect events
                    && ($tx['eventNo'] ?? -1) === 0         // ← only Normal Verify Open
                    && !empty($tx['pin'])                   // ← skip events with no pin
                    && !in_array($tx['logId'], $alreadyProcessed)
            ));

            if (empty($newTransactions)) {
                Log::info('ZKBio all transactions already processed', ['org' => $org->name, 'device' => $deviceSn]);
                return;
            }

            // Sort oldest → newest
            usort($newTransactions, fn($a, $b) => strcmp($a['eventTime'], $b['eventTime']));

            Log::info('ZKBio new transactions to process', [
                'org'    => $org->name,
                'device' => $deviceSn,
                'count'  => count($newTransactions),
                'logIds' => array_column($newTransactions, 'logId'),
            ]);

            // ── One job per person, mark as processed ONLY after job succeeds ──
            $grouped = collect($newTransactions)->groupBy('pin');

            foreach ($grouped as $pin => $pinTransactions) {
                if (!$pin) continue;

                try {
                    ProcessZKBioTransactions::dispatchSync(
                        $pinTransactions->values()->all(),
                        $org->id
                    );

                    // ✅ Only mark as processed AFTER successful job execution
                    ZkbioProcessedLog::insert(
                        $pinTransactions->map(fn($tx) => [
                            'log_id'          => $tx['logId'],
                            'device_sn'       => $deviceSn,
                            'pin'             => $tx['pin'] ?? null,
                            'organization_id' => $org->id,
                            'processed_at'    => now(),
                        ])->all()
                    );

                } catch (\Throwable $e) {
                    // ❌ Job failed — do NOT mark as processed so it retries next cycle
                    Log::error('ZKBio job failed — will retry next cycle', [
                        'pin'    => $pin,
                        'org'    => $org->name,
                        'device' => $deviceSn,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Throwable $e) {
            Log::error('ZKBio fetch failed for device', [
                'org'    => $org->name,
                'device' => $deviceSn,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
