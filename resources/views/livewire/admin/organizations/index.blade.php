<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\WithFileUploads;
use Spatie\Permission\Models\Permission;

new class extends Component {

    use WithFileUploads;

    public $name;
    public $address;
    public $location;
    public $email;
    public $phone_number;
    public $description;
    public $website;
    public $logo_path;
    public $editId;


    public function mount()
    {
        if (!auth()->user()?->can('view-organizations')) {
            abort(403, 'Unauthorized.');
        }

    }


    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:organizations,name,' . $this->editId,
            'address' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'email' => 'required|email|unique:organizations,email,' . $this->editId,
            'phone_number' => 'required|string|max:255',
            'description' => 'nullable|string',
            'website' => 'nullable|url',
            'logo_path' => 'nullable|file|mimes:jpg,jpeg,png,gif|max:2048',
        ];
    }

    public function createOrganization()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $logoPath = $this->logo_path ? $this->logo_path->store('logos', 'public') : null;

            // Create organization
            $organization = Organization::create([
                'name' => $this->name,
                'address' => $this->address,
                'location' => $this->location,
                'email' => $this->email,
                'phone_number' => $this->phone_number,
                'description' => $this->description,
                'website' => $this->website,
                'logo_path' => $logoPath,
            ]);

            // Default shift
            $shift = Shift::firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'name' => 'Morning Shift',
                ],
                [
                    'start_time' => '08:00:00',
                    'end_time' => '17:00:00',
                    'break_minutes' => 30,
                    'overtime_rate' => 1.5,
                    'status' => 'active',
                    'notes' => 'Standard 8-hour day shift with 30-minute break.',
                ]
            );

            // Create default user (org admin)
            $user = User::firstOrCreate(
                ['email' => $this->email],
                [
                    'name' => $this->name,
                    'password' => bcrypt('password'),
                ]
            );

            $department = Department::firstOrCreate(
                ['name' => 'ICT', 'organization_id' => $organization->id],
                [
                    'description' => 'ICT',
                    'manager_id' => $user->id,
                ]
            );

            // Setup default roles + assign admin to user
            $this->setupDefaultRoles($organization, $user);

            // Create default employee record
            Employee::firstOrCreate(
                ['email' => $this->email],
                [
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                    'shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'name' => $this->name,
                    'id_number' => 'ADMIN999',
                    'email' => $this->email,
                    'phone' => $this->phone_number,
                    'active' => true,
                ]
            );

            DB::commit();

            // Hide modal + alert
            $this->dispatch('hide-organization-modal');

            LivewireAlert::title('Awesome!')
                ->text('Organization added successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

            // Send password reset link to the new org admin
//            Password::broker()->sendResetLink(
//                ['email' => $user->email],
//                function ($user, $token) use ($organization) {
//                    $user->sendPasswordResetNotificationWithOrganization($token, $organization);
//                }
//            );

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text($e->getMessage())
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    /**
     * Setup default roles + permissions for an organization
     */
    private function setupDefaultRoles(Organization $organization, User $user): void
    {
        // Define default roles for each org
        $defaultRoles = [
            'admin',
            'supervisor',
            'employee',
            'department-manager',
        ];

        foreach ($defaultRoles as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'organization_id' => $organization->id,
            ]);
        }

        // Get all permissions
        $allPermissions = Permission::all()->pluck('name')->toArray();

        // Role → permissions map
        $rolePermissions = [
            'admin' => array_filter($allPermissions, fn($p) => !str_contains($p, 'organizations')),
            'supervisor' => array_filter($allPermissions, fn($p) => !str_contains($p, 'organizations')),
            'employee' => [],
            'department-manager' => ['approve-manual-timesheets'],
        ];

        foreach ($rolePermissions as $role => $perms) {
            $roleInstance = Role::firstOrCreate([
                'name' => $role,
                'organization_id' => $organization->id,
            ]);

            $roleInstance->syncPermissions($perms);
        }

        // Assign org admin role to the default user
        $adminRole = Role::where('name', 'admin')
            ->where('organization_id', $organization->id)
            ->first();

        if ($adminRole) {
            $user->assignRole($adminRole);
        }
    }


    #[On('edit-organization')]
    public function editOrganization($id)
    {
        $org = Organization::findOrFail($id);
        $this->editId = $id;
        $this->name = $org->name;
        $this->address = $org->address;
        $this->location = $org->location;
        $this->email = $org->email;
        $this->phone_number = $org->phone_number;
        $this->description = $org->description;
        $this->website = $org->website;
        $this->logo_path = $org->logo_path;

        $this->dispatch('show-organization-modal');
    }

    public function updateOrganization()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $logoPath = $this->logo_path ? $this->logo_path->store('logos', 'public') : null;

            Organization::findOrFail($this->editId)->update([
                'name' => $this->name,
                'address' => $this->address,
                'location' => $this->location,
                'email' => $this->email,
                'phone_number' => $this->phone_number,
                'description' => $this->description,
                'website' => $this->website,
                'logo_path' => $logoPath,
            ]);

            DB::commit();

            $this->dispatch('hide-organization-modal');

            LivewireAlert::title('Awesome!')
                ->text('Organization updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Failed to update organization.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }


    #[On('delete-organization')]
    public function deleteOrganization($id)
    {
        try {
            DB::beginTransaction();

            Organization::findOrFail($id)->delete();

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Organization deleted successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);


            LivewireAlert::title('Error!')
                ->text('Failed to delete organization.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('discard-organization-modal')]
    public function discardOrganizationModal()
    {
        $this->dispatch('hide-organization-modal');
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['name', 'editId']);
    }
};

?>

@push('styles')
    <style>

        #table-bulkActionsDropdown {
            background-color: #e14326;
            border: none;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        #table-bulkActionsDropdown:hover {
            background-color: #c2361d; /* darker shade for hover */
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(225, 67, 38, 0.4);
        }

        .btn-outline-secondary {
            margin-left: 0.5rem !important;
            padding: 6px 16px !important;
            border-radius: 8px !important;
            font-size: 0.875rem !important;
            transition: all 0.2s ease-in-out !important;
            border-color: red !important;
        }

        .btn-outline-secondary:hover {
            background-color: #f1f1f1 !important;
            border-color: #aaa !important;
            color: #000 !important;
        }

        .btn-outline-secondary svg,
        .btn-outline-secondary svg * {
            fill: red !important;
            stroke: red !important;
        }

        .btn-outline-secondary:hover svg,
        .btn-outline-secondary:hover svg * {
            fill: white !important;
            stroke: white !important;
        }

        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

    </style>
@endpush


<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="Organizations"
            :items="[
            [
             'label' => 'Dashboard',
             'url' => route('dashboard'),
             'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
           ],
           [
             'label' => 'Organizations',
             'icon' => '<iconify-icon icon=\'mdi:domain\' class=\'fs-5\'></iconify-icon>',
           ],
        ]"
        />

        <div class="card card-body">
            {{-- Top Bar: Search + Create Button --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                {{-- Left side: Optional Search (if added) --}}
                <div class="mb-2">
                    {{-- Placeholder for filters/search --}}
                </div>

                {{-- Right side: Create Employee button --}}
                <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                   data-bs-target="#organizationModal">
                    <i class="ti ti-building fs-5"></i> Add Client
                </a>

            </div>


            {{-- Livewire Table --}}
            <livewire:organization-table theme="bootstrap-4"/>

        </div>
    </div>

    <div class="modal fade" id="organizationModal" tabindex="-1"
         aria-labelledby="organizationModalTitle" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editId ? 'Edit Organization' : 'New Organization' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form wire:submit.prevent="{{ $editId ? 'updateOrganization' : 'createOrganization' }}">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="name" class="form-label">Organization Name</label>
                                <input type="text" wire:model="name" id="name" class="form-control"
                                       placeholder="Organization Name"/>
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="email" class="form-label">Admin Email</label>
                                <input type="email" wire:model="email" id="email" class="form-control"
                                       placeholder="Email"/>
                                @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="phone_number" class="form-label">Admin Phone Number</label>
                                <input type="tel" wire:model="phone_number" id="phone_number" class="form-control"
                                       placeholder="Phone Number"/>
                                @error('phone_number') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" wire:model="website" id="website" class="form-control"
                                       placeholder="Website URL"/>
                                @error('website') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea wire:model="description" id="description" class="form-control" rows="3"
                                          placeholder="Description"></textarea>
                                @error('description') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label for="logo_path" class="form-label">Upload Logo</label>
                                <input type="file" wire:model="logo_path" id="logo_path" class="form-control"
                                       placeholder="e.g., /logos/isuzu.png"/>
                                @error('logo_path') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>
                        </div>


                        <div class="modal-footer d-flex gap-1">
                            <button type="submit" class="btn btn-success">
                                {{ $editId ? 'Save' : 'Add' }}
                            </button>
                            <button wire:click="$dispatch('discard-organization-modal')" type="button"
                                    class="btn btn-outline-danger" data-bs-dismiss="modal">
                                Discard
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('show-organization-modal', () => {
            new bootstrap.Modal(document.getElementById('organizationModal')).show();
        });

        window.addEventListener('hide-organization-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('organizationModal'))?.hide();
        });
    </script>
@endpush







