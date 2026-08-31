<?php

namespace App\Http\Controllers\SuperAdmin\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Integrations\UpdateActiveDirectorySettingsRequest;
use App\Http\Requests\SuperAdmin\Integrations\UpdateApiDocsSettingsRequest;
use App\Http\Requests\SuperAdmin\Integrations\UpdateZkbioSettingsRequest;
use App\Http\Resources\SuperAdmin\IntegrationSettingsResource;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Services\IntegrationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Integrations')]
class IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationService $service)
    {
    }

    /**
     * GET /super-man/clients/{organization}/integrations
     *
     * Client portal integration settings: API keys, ZKBio and Active Directory sync.
     */
    public function show(Organization $organization)
    {
        return ApiResponse::success(
            new IntegrationSettingsResource($organization->load('apiKeys')),
            message: 'Integration settings retrieved'
        );
    }

    /**
     * PUT /super-man/clients/{organization}/integrations/zkbio
     *
     * Update ZKBio CV hardware integration settings for a client.
     */
    public function updateZkbio(UpdateZkbioSettingsRequest $request, Organization $organization)
    {
        $organization = $this->service->updateZkbioSettings($organization, $request->validated());

        return ApiResponse::success(new IntegrationSettingsResource($organization->load('apiKeys')), message: 'ZKBio settings updated');
    }

    /**
     * PUT /super-man/clients/{organization}/integrations/active-directory
     *
     * Update Microsoft Entra ID (Active Directory) sync settings for a client.
     */
    public function updateActiveDirectory(UpdateActiveDirectorySettingsRequest $request, Organization $organization)
    {
        $organization = $this->service->updateActiveDirectorySettings($organization, $request->validated());

        return ApiResponse::success(new IntegrationSettingsResource($organization->load('apiKeys')), message: 'Active Directory settings updated');
    }

    /**
     * PUT /super-man/clients/{organization}/integrations/api-docs
     *
     * Update the client portal API documentation link.
     */
    public function updateApiDocs(UpdateApiDocsSettingsRequest $request, Organization $organization)
    {
        $organization = $this->service->updateApiDocsSettings($organization, $request->validated());

        return ApiResponse::success(new IntegrationSettingsResource($organization->load('apiKeys')), message: 'API documentation settings updated');
    }

    /**
     * POST /super-man/clients/{organization}/integrations/api-keys/{environment}/generate
     *
     * Generate (or regenerate) a client API key for the "test" or "production" environment.
     * The plaintext key is returned once and cannot be retrieved again.
     */
    public function generateApiKey(Request $request, Organization $organization, string $environment)
    {
        $request->merge(['environment' => $environment])->validate([
            'environment' => 'required|string|in:test,production',
        ]);

        $result = $this->service->generateApiKey($organization, $environment);

        return ApiResponse::success([
            'api_key' => $result['plain'],
            'settings' => new IntegrationSettingsResource($organization->fresh()->load('apiKeys')),
        ], message: 'API key generated. Store it securely, it will not be shown again.', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/clients/{organization}/integrations/api-keys/{environment}/toggle
     *
     * Enable or disable a client's API key for the given environment.
     */
    public function toggleApiKey(Request $request, Organization $organization, string $environment)
    {
        $request->merge(['environment' => $environment])->validate([
            'environment' => 'required|string|in:test,production',
        ]);

        $this->service->toggleApiKey($organization, $environment);

        return ApiResponse::success(new IntegrationSettingsResource($organization->fresh()->load('apiKeys')), message: 'API key status updated');
    }
}
