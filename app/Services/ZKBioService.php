<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZKBioService
{
    protected string $baseUrl;
    protected string $accessToken;

    public function __construct()
    {
        $this->baseUrl    = config('zkbio.base_url');   // http://51.20.165.179:8098
        $this->accessToken = config('zkbio.access_token');
    }

    /**
     * Fetch paginated transactions for a device, optionally filtered by date range.
     */
    public function getTransactions(
        string  $deviceSn,
        int     $pageNo      = 1,
        int     $pageSize    = 100,
        ?string $startDate   = null,
        ?string $endDate     = null,
        ?string $baseUrl     = null,      // org override
        ?string $accessToken = null       // org override
    ): array {
        $url   = ($baseUrl     ?? $this->baseUrl) . "/api/transaction/device/{$deviceSn}";
        $token = $accessToken  ?? $this->accessToken;

        $params = [
            'access_token' => $token,
            'pageNo'       => $pageNo,
            'pageSize'     => $pageSize,
        ];

        if ($startDate) $params['startDate'] = $startDate;
        if ($endDate)   $params['endDate']   = $endDate;

        $response = Http::timeout(15)->get($url, $params);

        if ($response->failed()) {
            Log::error("ZKBio API error", ['status' => $response->status(), 'deviceSn' => $deviceSn]);
            return [];
        }

        $body = $response->json();
        if (($body['code'] ?? -1) !== 0) {
            Log::warning("ZKBio non-zero code", ['body' => $body]);
            return [];
        }

        return $body['data'] ?? [];
    }

    /**
     * Fetch ALL pages for a device on a given date.
     */
    public function getAllTransactionsForDate(string $deviceSn, string $date): array
    {
        $all      = [];
        $page     = 1;
        $pageSize = 100;

        do {
            $batch = $this->getTransactions($deviceSn, $page, $pageSize, $date, $date);
            $all   = array_merge($all, $batch);
            $page++;
        } while (count($batch) === $pageSize); // keep going if full page returned

        return $all;
    }
}
