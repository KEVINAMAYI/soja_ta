<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\ClientHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckClientHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public array $organizationIds,
    ) {
    }

    public function handle(ClientHealthService $service): void
    {
        $result = $service->checkUrl($this->url);

        Organization::query()
            ->whereIn('id', $this->organizationIds)
            ->get()
            ->each(fn (Organization $organization) => cache()->put(
                $service->cacheKey($organization->id),
                $service->withOrganization($organization, $result),
                now()->addMinutes(10)
            ));
    }
}