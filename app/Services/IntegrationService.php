<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationApiKey;
use Illuminate\Support\Arr;

class IntegrationService
{
    public function updateZkbioSettings(Organization $organization, array $data): Organization
    {
        $organization->update([
            'zkbio_enabled' => $data['zkbio_enabled'],
            'zkbio_sync_enabled' => $data['zkbio_enabled'],
            'zkbio_base_url' => $data['zkbio_base_url'] ?? $organization->zkbio_base_url,
            'zkbio_access_token' => $data['zkbio_access_token'] ?? $organization->zkbio_access_token,
        ]);

        return $organization->fresh();
    }

    public function updateActiveDirectorySettings(Organization $organization, array $data): Organization
    {
        $organization->update(
            Arr::where([
                'ad_sync_enabled' => $data['ad_sync_enabled'] ?? false,
                'ad_tenant_id' => $data['ad_tenant_id'] ?? $organization->ad_tenant_id,
                'ad_client_id' => $data['ad_client_id'] ?? $organization->ad_client_id,
                'ad_client_secret' => $data['ad_client_secret'] ?? $organization->ad_client_secret,
            ], fn ($value) => $value !== null)
        );

        return $organization->fresh();
    }

    public function updateApiDocsSettings(Organization $organization, array $data): Organization
    {
        $organization->update([
            'api_docs_enabled' => $data['api_docs_enabled'],
            'api_docs_url' => $data['api_docs_url'] ?? $organization->api_docs_url,
        ]);

        return $organization->fresh();
    }

    /**
     * Generate (or regenerate) a client API key for the given environment.
     * Returns the plaintext key alongside the persisted record; the plaintext
     * is never stored and cannot be retrieved again after this call.
     *
     * @return array{key: OrganizationApiKey, plain: string}
     */
    public function generateApiKey(Organization $organization, string $environment): array
    {
        $generated = OrganizationApiKey::generatePlainKey($environment);

        $key = $organization->apiKeys()->updateOrCreate(
            ['environment' => $environment],
            [
                'key_prefix' => $generated['prefix'],
                'last_four' => $generated['last_four'],
                'key_hash' => $generated['hash'],
                'active' => true,
            ]
        );

        return ['key' => $key, 'plain' => $generated['plain']];
    }

    public function toggleApiKey(Organization $organization, string $environment): OrganizationApiKey
    {
        $key = $organization->apiKeys()->where('environment', $environment)->firstOrFail();

        $key->update(['active' => !$key->active]);

        return $key->fresh();
    }
}
