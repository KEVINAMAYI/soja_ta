<?php

namespace App\Http\Controllers\SuperAdmin\Clients;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Clients\ClientEmployeeDefaultsRequest;
use App\Http\Requests\SuperAdmin\Clients\CreateClientDepartmentRequest;
use App\Http\Requests\SuperAdmin\Clients\StoreClientRequest;
use App\Http\Requests\SuperAdmin\Clients\StoreJobTitleRequest;
use App\Http\Requests\SuperAdmin\Clients\UpdateClientRequest;
use App\Http\Requests\SuperAdmin\Clients\UploadClientLogoRequest;
use App\Http\Responses\ApiResponse;
use App\Models\JobTitle;
use App\Models\Organization;
use App\Services\ClientService;
use App\Utils\ApiConstants;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Superadmin/Clients')]
class ClientController extends Controller
{
    public function __construct(private readonly ClientService $service)
    {
    }

    /**
     * GET /super-man/clients
     *
     * Paginated client list: Company, Status, Joined, Last Active.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'sort' => 'nullable|string',
        ]);

        $query = $this->service->clientsQuery();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('status')) {
            $query->where('active', $request->input('status') === 'active');
        }

        $clients = PaginationHelper::paginate($query, $request);

        $clients->getCollection()->transform(fn ($organization) => [
            'id' => $organization->id,
            'company' => $organization->name,
            'status' => $organization->active ? 'Active' : 'Inactive',
            'joined' => $organization->created_at,
            'last_active' => $organization->last_active_at,
        ]);

        return ApiResponse::success(code: ApiConstants::SUCCESS_CODE, data: $clients);
    }

    /**
     * POST /super-man/clients
     *
     * Create a client organization, its default workspace and tenant admin account.
     */
    public function store(StoreClientRequest $request)
    {
        $organization = $this->service->createClient($request->validated());

        return ApiResponse::success($organization, message: 'Client created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/clients/{organization}/employee-defaults
     * Update the default employee settings for a client organization.
     */
    public function setClientEmployeeDefaults(ClientEmployeeDefaultsRequest $request, Organization $organization)
    {
        $this->service->setOrganizationEmployeeDefaults($organization, $request->validated());

        return ApiResponse::success(null, message: 'Organization employee defaults updated');
    }

    /**
     * PUT /super-man/clients/{organization}
     *
     * Update a client organization, its default workspace and tenant admin account.
     */
    public function update(UpdateClientRequest $request, Organization $organization)
    {
        $organization = $this->service->updateClient($organization, $request->validated());

        return ApiResponse::success($organization, message: 'Client updated');
    }

    /**
     * POST /super-man/clients/{organization}/logo
     *
     * Dedicated multipart endpoint for uploading/replacing the client logo.
     */
    public function uploadLogo(UploadClientLogoRequest $request, Organization $organization)
    {
        $organization = $this->service->updateLogo($organization, $request->file('logo'));

        return ApiResponse::success($organization, message: 'Client logo updated');
    }

    /**
     * GET /super-man/clients/{organization}/departments
     *
     * Retrieve the list of departments for a client organization.
     */
    public function getOrganizationDepartments(Organization $organization)
    {
        $departments = $this->service->getOrganizationDepartments($organization);

        return ApiResponse::success($departments, message: 'Organization departments retrieved');
    }

    /**
     * POST /super-man/clients/{organization}/departments
     * Create a new department for a client organization.
     */
    public function createOrganizationDepartment(CreateClientDepartmentRequest $request, Organization $organization)
    {

        $department = $this->service->createOrganizationDepartment($organization, $request->only(['name', 'description', 'manager_id']));

        return ApiResponse::success($department, message: 'Organization department created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/clients/{organization}/departments/{departmentId}
     * Update an existing department for a client organization.
     */
    public function updateOrganizationDepartment(CreateClientDepartmentRequest $request, Organization $organization, $departmentId)
    {
        $department = $organization->departments()->findOrFail($departmentId);

        $updatedDepartment = $this->service->updateOrganizationDepartment($department, $request->only(['name', 'description', 'manager_id']));

        return ApiResponse::success($updatedDepartment, message: 'Organization department updated');
    }

    /**
     * GET /super-man/clients/{organization}/hierarchy
     * Retrieve the hierarchical structure of a client organization.
     */
    public function getOrganizationHierarchy(Organization $organization)
    {
        $hierarchy = $this->service->getOrganizationHierarchy($organization);

        return ApiResponse::success($hierarchy, message: 'Organization hierarchy retrieved');
    }

    /**
     * POST /super-man/clients/{organization}/job-title
     * Create a new job title for a client organization.
     */
    public function storeJobTitle(StoreJobTitleRequest $request, Organization $organization)
    {
        $jobTitle = $this->service->saveJobTitle($organization, $request->validated());

        return ApiResponse::success($jobTitle, message: 'Job title created', httpStatusCode: 201);
    }

    /**
     * PUT /super-man/clients/{organization}/job-title/{jobTitleId}
     * Update an existing job title for a client organization.
     */
    public function updateJobTitle(StoreJobTitleRequest $request, Organization $organization, int $jobTitleId)
    {
        $jobTitle = JobTitle::where('organization_id', $organization->id)->findOrFail($jobTitleId);

        $updatedJobTitle = $this->service->updateJobTitle($jobTitle, $request->validated());

        return ApiResponse::success($updatedJobTitle, message: 'Job title updated');
    }
}
