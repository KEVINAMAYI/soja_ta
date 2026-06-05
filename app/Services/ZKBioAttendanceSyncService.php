<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZKBioAttendanceSyncService
{
    protected string $baseUrl;
    protected string $accessToken;

    public function __construct()
    {
        $this->baseUrl     = config('zkbio.base_url');
        $this->accessToken = config('zkbio.access_token');
    }

    /**
     * Pull transactions within a datetime range from ZKBio server.
     * Used for incremental sync — only fetches new data since last run.
     */
    public function pullForDateRange(string $startDatetime, string $endDatetime): array
    {
        $all      = [];
        $page     = 1;
        $pageSize = 100;

        do {
            $response = Http::timeout(15)->get(
                "{$this->baseUrl}/api/v2/transaction/listAttTransaction",
                [
                    'access_token' => $this->accessToken,
                    'pageNo'       => $page,
                    'pageSize'     => $pageSize,
                    'startDate'    => $startDatetime,
                    'endDate'      => $endDatetime,
                ]
            );

            if ($response->failed()) {
                Log::error('ZKBio pull failed', [
                    'status' => $response->status(),
                    'start'  => $startDatetime,
                    'end'    => $endDatetime,
                ]);
                break;
            }

            $body = $response->json();

            if (($body['code'] ?? -1) !== 0) {
                Log::warning('ZKBio non-zero response code', ['body' => $body]);
                break;
            }

            $records  = $body['data']['data'] ?? [];
            $all      = array_merge($all, $records);
            $lastPage = $body['data']['lastPage'] ?? true;
            $page++;

        } while (!$lastPage);

        // Sort ascending by event time
        usort($all, fn($a, $b) => strcmp($a['eventTime'], $b['eventTime']));

        return $all;
    }

    /**
     * Pull ALL punches for a specific employee PIN on a specific date.
     * Used by the classifier to get the full picture even during incremental sync.
     */
    public function getAllPunchesForEmployee(string $pin, string $date): array
    {
        $all      = [];
        $page     = 1;
        $pageSize = 100;

        do {
            $response = Http::timeout(15)->get(
                "{$this->baseUrl}/api/v2/transaction/listAttTransaction",
                [
                    'access_token' => $this->accessToken,
                    'pageNo'       => $page,
                    'pageSize'     => $pageSize,
                    'startDate'    => "{$date} 00:00:00",
                    'endDate'      => "{$date} 23:59:59",
                    'personPin'    => $pin,
                ]
            );

            if ($response->failed()) break;

            $body     = $response->json();
            $records  = $body['data']['data'] ?? [];
            $all      = array_merge($all, $records);
            $lastPage = $body['data']['lastPage'] ?? true;
            $page++;

        } while (!$lastPage);

        // Return only event times sorted ascending
        $times = array_column($all, 'eventTime');
        sort($times);

        return $times;
    }

    /**
     * Group raw transactions by employee PIN.
     * Each employee gets their punches sorted ascending.
     */
    public function groupByEmployee(array $transactions): array
    {
        $grouped = [];

        foreach ($transactions as $tx) {
            $pin = $tx['pin'];

            if (!isset($grouped[$pin])) {
                $grouped[$pin] = [
                    'pin'      => $pin,
                    'name'     => $tx['name'],
                    'lastName' => $tx['lastName'] ?? null,
                    'deptName' => $tx['deptName'] ?? null,
                    'punches'  => [],
                ];
            }

            $grouped[$pin]['punches'][] = $tx['eventTime'];
        }

        foreach ($grouped as &$data) {
            sort($data['punches']);
        }

        return $grouped;
    }

    /**
     * Push a person record from ZKBio server to a specific device.
     * Biometrics only sync if already enrolled on another device.
     */
    public function syncPersonToDevice(string $personPin, string $deviceSn): bool
    {
        $response = Http::timeout(15)->post(
            "{$this->baseUrl}/api/device/syncPerson",
            [
                'access_token' => $this->accessToken,
                'devSn'        => $deviceSn,
                'personPin'    => $personPin,
            ]
        );

        $success = $response->successful() && ($response->json()['code'] ?? -1) === 0;

        Log::info("Sync person {$personPin} to device {$deviceSn}: " . ($success ? 'OK' : 'FAILED'));

        return $success;
    }

    /**
     * Push a person to ALL configured devices.
     */
    public function syncPersonToAllDevices(string $personPin): array
    {
        $devices = config('zkbio.devices', []);
        $results = [];

        foreach ($devices as $device) {
            $results[$device['name']] = [
                'sn'      => $device['sn'],
                'success' => $this->syncPersonToDevice($personPin, $device['sn']),
            ];
        }

        return $results;
    }
}
