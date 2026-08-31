<?php

namespace App\Http\Controllers\SuperAdmin\Clients;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\Clients\StoreClientRequest;
use App\Http\Requests\SuperAdmin\Clients\UpdateClientRequest;
use App\Http\Requests\SuperAdmin\Clients\UploadClientLogoRequest;
use App\Http\Responses\ApiResponse;
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
}
