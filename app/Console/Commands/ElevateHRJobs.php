<?php

namespace App\Console\Commands;

use App\Services\HttpRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ElevateHRJobs extends Command
{
    protected $signature = 'elevate-hr:fetch';

    protected $description = 'Fetch data from ElevateHR and log the response';

    public function __construct(protected HttpRequestService $httpRequestService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $url = config('elevate_hr.url');

        if (empty($url)) {
            $this->error('ElevateHR URL is not configured (ELEVATE_HR_URL).');
            Log::warning('ElevateHRJobs: ELEVATE_HR_URL is not configured.');

            return;
        }

        $response = $this->httpRequestService->get($url);

        if ($response->failed()) {
            Log::error('ElevateHRJobs: request failed.', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        Log::info('ElevateHRJobs: data received.', [
            'url' => $url,
            'data' => $response->json() ?? $response->body(),
        ]);
    }
}
