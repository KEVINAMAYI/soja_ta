<?php

namespace App\Services;

use App\Jobs\CheckClientHealthJob;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ClientHealthService
{
    private const CACHE_TTL_MINUTES = 10;

    public function cacheKey(int $organizationId): string
    {
        return "client-health:organization:{$organizationId}";
    }

    public function check(Organization $organization): array
    {
        $result = $this->checkUrl($organization->client_ta_base_url);

        Cache::put($this->cacheKey($organization->id), $this->withOrganization($organization, $result), now()->addMinutes(self::CACHE_TTL_MINUTES));

        return Cache::get($this->cacheKey($organization->id));
    }

    public function queueForOrganizations(iterable $organizations): void
    {
        $byUrl = [];

        foreach ($organizations as $organization) {
            if (Cache::has($this->cacheKey($organization->id))) {
                continue;
            }

            if (blank($organization->client_ta_base_url)) {
                Cache::put(
                    $this->cacheKey($organization->id),
                    $this->withOrganization($organization, $this->checkUrl(null)),
                    now()->addMinutes(self::CACHE_TTL_MINUTES)
                );

                continue;
            }

            Cache::put(
                $this->cacheKey($organization->id),
                $this->withOrganization($organization, [
                    'status' => 'loading',
                    'client_issues' => [],
                    'checked_at' => null,
                ]),
                now()->addMinutes(self::CACHE_TTL_MINUTES)
            );

            $byUrl[$organization->client_ta_base_url][] = $organization->id;
        }

        foreach ($byUrl as $url => $organizationIds) {
            CheckClientHealthJob::dispatch($url, $organizationIds);
        }
    }

    public function invalidateAll(): void
    {
        Organization::query()->pluck('id')->each(fn (int $id) => Cache::forget($this->cacheKey($id)));
    }

    public function checkUrl(?string $url): array
    {
        if (blank($url)) {
            return [
                'status' => 'no url set!!!',
                'client_issues' => [['message' => 'No client TA base URL is configured.']],
                'checked_at' => now()->toISOString(),
            ];
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($url);

            if ($response->successful()) {
                return [
                    'status' => 'healthy',
                    'client_issues' => [],
                    'checked_at' => now()->toISOString(),
                ];
            }

            return [
                'status' => 'unhealthy',
                'client_issues' => [[
                    'message' => "Health check returned HTTP {$response->status()}.",
                    'http_status' => $response->status(),
                ]],
                'checked_at' => now()->toISOString(),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'unhealthy',
                'client_issues' => [['message' => $exception->getMessage()]],
                'checked_at' => now()->toISOString(),
            ];
        }
    }

    public function withOrganization(Organization $organization, array $result): array
    {
        return array_merge([
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'client_ta_base_url' => $organization->client_ta_base_url,
        ], $result);
    }
}