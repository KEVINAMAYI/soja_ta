<?php

namespace App\Http\Resources\SuperAdmin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $apiKeys = collect(['test', 'production'])->map(function (string $environment) {
            $key = $this->apiKeys->firstWhere('environment', $environment);

            return [
                'environment' => $environment,
                'base_url' => config("client_portal.{$environment}.base_url"),
                'daily_quota' => config("client_portal.{$environment}.daily_quota"),
                'active' => (bool) $key?->active,
                'masked_key' => $key ? $key->key_prefix . str_repeat('•', 8) . $key->last_four : null,
                'last_used_at' => $key?->last_used_at,
                'generated_at' => $key?->created_at,
            ];
        });

        return [
            'organization_id' => $this->id,
            'company' => $this->name,
            'api_docs' => [
                'enabled' => (bool) $this->api_docs_enabled,
                'url' => $this->api_docs_url,
            ],
            'api_keys' => $apiKeys,
            'zkbio' => [
                'enabled' => (bool) $this->zkbio_enabled,
                'base_url' => $this->zkbio_base_url,
                'has_access_token' => !empty($this->zkbio_access_token),
            ],
            'active_directory' => [
                'enabled' => (bool) $this->ad_sync_enabled,
                'tenant_id' => $this->ad_tenant_id,
                'client_id' => $this->ad_client_id,
                'has_client_secret' => !empty($this->ad_client_secret),
            ],
        ];
    }
}
