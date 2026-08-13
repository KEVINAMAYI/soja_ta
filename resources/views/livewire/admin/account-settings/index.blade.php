<?php

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $organizationId;
    public $org;
    public $name;
    public $editId;
    public $description;
    public $manager_id;
    public string $activeParentTab = 'company';
    public string $activeTab = 'company';
    public string $tabTitle;
    public string $tabIcon;
    public array $breadcrumbItems = [];
    public int $requireEmployeePhoto;
    public int $autoAssignEmployeeId;

    public function mount()
    {
        // Get the logged in user
        $user = auth()->user();

        // Ensure they have an employee record with an organization
        if ($user && $user->employee && $user->employee->organization_id) {
            $this->organizationId = $user->employee->organization_id;

            // Fetch the organization
            $this->org = Organization::findOrFail($this->organizationId);

        } else {

            abort(403, 'No organization found for this user.');
        }


        $this->changeBreadcrumb();

    }

    #[On('tabChanged')]
    public function tabChanged($tabId)
    {

        $this->activeTab = $tabId;
        $this->changeBreadcrumb();

    }



    public function saveQrCodeSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'generate_employee_qr_on_create';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->generateQrOnCreate = $value;

        LivewireAlert::title('Success!')
            ->text('QR code generation setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    // department creation validation rules
    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:departments,name,' . $this->editId,
            'description' => 'nullable|string|max:1000',
            'manager_id' => 'nullable|exists:users,id',
        ];
    }

    public function createDepartment()
    {
        $this->validate();

        try {

            DB::beginTransaction();

            $org = auth()->user()->employee->organization;

            Department::create([
                'name' => $this->name,
                'description' => $this->description,
                'manager_id' => $this->manager_id,
                'organization_id' => $org->id
            ]);


            if ($this->manager_id) {
                $manager = User::find($this->manager_id);
                if ($manager && !$manager->hasRole('department-manager')) {
                    $manager->assignRole('department-manager');
                }
            }


            DB::commit();

            $this->dispatch('hide-department-modal');

            LivewireAlert::title('Awesome!')
                ->text('Department added successfully.')
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
                ->text('Failed to add department.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function saveEmployeePhotoSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'require_employee_photo';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->requireEmployeePhoto = $value;

        LivewireAlert::title('Success!')
            ->text('Employee photo requirement setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function saveAutoAssignEmployeeIdSetting($value)
    {
        $org = auth()->user()->employee->organization;
        $value = (int)$value;

        $key = 'auto_assign_employee_id';
        $org->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
            ['type' => 'boolean']
        );

        $this->autoAssignEmployeeId = $value;

        LivewireAlert::title('Success!')
            ->text('Auto-assign employee ID setting updated.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }


    public function setActiveParentTab(string $parentTab, string $defaultChildTab = ''): void
    {
        // if (!in_array($parentTab, ['attendance', 'leaves', 'help_support'], true)) {
        //     return;
        // }

        $this->activeParentTab = $parentTab;
        $this->activeTab = $defaultChildTab ? $defaultChildTab : $this->activeTab;

        $this->changeBreadcrumb();
    }


    public function setActiveTab(string $tabId, string $defaultParentTab = ''): void
    {
        $this->activeTab = $tabId;
        $this->activeParentTab = $defaultParentTab ?: $this->activeParentTab;

        $this->changeBreadcrumb();
    }

    public function changeBreadcrumb()
    {
        switch ($this->activeTab) {

            case 'company':
                $this->tabTitle = 'Company';
                $this->tabIcon = '<iconify-icon icon="mdi:office-building-outline" class="fs-5"></iconify-icon>';
                break;

            case 'employee_defaults':
                $this->tabTitle = 'Employee Defaults';
                $this->tabIcon = '<iconify-icon icon="mdi:account-multiple-outline" class="fs-5"></iconify-icon>';
                break;

            case 'departments':
                $this->tabTitle = 'Departments';
                $this->tabIcon = '<iconify-icon icon="mdi:office-building-outline" class="fs-5"></iconify-icon>';
                break;

            case 'users':
                $this->tabTitle = 'Users';
                $this->tabIcon = '<iconify-icon icon="mdi:user-outline" class="fs-5"></iconify-icon>';
                break;

            case 'user_roles':
                $this->tabTitle = 'Roles & Permissions';
                $this->tabIcon = '<iconify-icon icon="mdi:shield-outline" class="fs-5"></iconify-icon>';
                break;

            case 'qr_token_management':
                $this->tabTitle = 'QR Token Management';
                $this->tabIcon = '<iconify-icon icon="mdi:qrcode-scan" class="fs-5"></iconify-icon>';
                break;

            case 'location':
                $this->tabTitle = 'Locations';
                $this->tabIcon = '<iconify-icon icon="mdi:map-marker-outline" class="fs-5"></iconify-icon>';
                break;

            case 'devices':
                $this->tabTitle = 'Devices';
                $this->tabIcon = '<iconify-icon icon="mdi:device-mobile-outline" class="fs-5"></iconify-icon>';
                break;

            default:
                $this->tabTitle = 'Settings';
                $this->tabIcon = '<iconify-icon icon="mdi:cog-outline" class="fs-5"></iconify-icon>';
                break;
        }

        $this->breadcrumbItems = [
            [
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
            ],
            [
                'label' => 'Account Settings',
                'url' => '#',
                'icon' => '<iconify-icon icon="mdi:cog-outline" class="fs-5"></iconify-icon>',
            ],
            [
                'label' => $this->tabTitle,
                'icon' => $this->tabIcon,
            ],
        ];
    }


}; ?>

@push('styles')
    <style>
        /* Buttons */
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

        /* Input fields */
        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

        /* Accordion wrapper */
        .accordion-item {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        /* Accordion header button */
        .accordion-button {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
            padding: 1rem 1.25rem;
            transition: background-color 0.3s ease;
            border: none;
        }

        .accordion-button:not(.collapsed) {
            background-color: #e9ecef;
            color: #000;
        }

        .accordion-button:hover {
            background-color: #e2e6ea;
        }

        /* Remove default arrow */
        .accordion-button::after {
            display: none !important;
        }

        /* Accordion collapse body */
        .accordion-collapse {
            background-color: white;
            padding: 1rem 1.25rem;
            border-top: 1px solid #dee2e6;
            border-bottom-left-radius: 0px;
            border-bottom-right-radius: 0px;
        }

        /* Flatten Livewire inner card */
        .accordion-collapse .card {
            box-shadow: none !important;
            border: none !important;
            border-radius: 0 !important;
            margin: 0 !important;
        }

        /* Rounded corners when closed */
        .accordion-item .accordion-button.collapsed {
            border-radius: 8px;
        }

        /* Only top corners when open */
        .accordion-item .accordion-button:not(.collapsed) {
            border-top-left-radius: 0px;
            border-top-right-radius: 0px;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }

        .accordion-collapse {
            background-color: white;
            padding: 1rem 1.25rem;
            border-top: 1px solid #dee2e6;
            border-bottom-left-radius: 0px;
            border-bottom-right-radius: 0px;

            /* Smooth transition */
            transition: height 0.3s ease, padding 0.3s ease;
            overflow: hidden;
        }

        /* Inner tabs underline style */
        #innerRolesTab .nav-link {
            border: none !important;
            border-bottom: 40px solid transparent !important;
            color: #6b7280 !important; /* neutral gray */
            font-size: 14.5px !important;
            font-weight: 600 !important;
            transition: all 0.2s ease-in-out !important;
            background-color: transparent !important;
        }

        #innerRolesTab .nav-link.active {
            border-bottom: 40px solid #e14326 !important; /* custom underline color */
            color: #e14326 !important;
            background-color: transparent !important;
        }

        #innerRolesTab .nav-link:hover {
            color: #e14326 !important;
        }

        /* Remove the gray divider completely */
        #innerRolesTab {
            border: none !important;
        }

        .user-profile-tab .nav-link.active {
            border-bottom: 3.0px solid #e14326 !important;
        }


    </style>
@endpush
<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            :title="$tabTitle"
            :items="$breadcrumbItems"
        />

        <div class="card">

            <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">
                <!-- Company Information -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'company' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-company-information-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-company-information"
                        type="button"
                        wire:click="setActiveParentTab('company', 'company')"
                        role="tab"
                        aria-controls="tab-company-information"
                        aria-selected="true">
                        <i class="ti ti-user-circle mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Company Information</span>
                    </button>
                </li>


                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'location-assignment' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-location-assignment-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-location-assignment"
                        type="button"
                        wire:click="setActiveParentTab('location-assignment', 'location')"
                        role="tab"
                        aria-controls="tab-location-assignment"
                        aria-selected="false">
                        <i class="ti ti-map-pin mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Locations & Devices</span>
                    </button>
                </li>


                <!-- User -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'users' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-users-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-users"
                        type="button"
                        wire:click="setActiveParentTab('users', 'employee_defaults')"
                        role="tab"
                        aria-controls="tab-users"
                        aria-selected="false">
                        <i class="ti ti-users mx-1 fs-6"></i>
                        <span class="d-none d-md-block">
                            {{ auth()->user()->employee?->organization?->is_student_record ? 'Users' :
                            'Employees & Departments' }}
                        </span>
                    </button>
                </li>


                <!-- Roles & Permissions -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'roles' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-roles-permissions-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-roles-permissions"
                        type="button"
                        wire:click="setActiveParentTab('roles', 'user_roles')"
                        role="tab"
                        aria-controls="tab-roles-permissions"
                        aria-selected="false">
                        <i class="ti ti-shield mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Access & Security</span>
                    </button>
                </li>

            </ul>


            @if($activeParentTab === 'location-assignment')
                <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist"  style="background-color: #F3F4F6;">
                    
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'location' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-location-assignment-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-location-assignment"
                            wire:click="setActiveTab('location')"
                            type="button"
                            role="tab"
                            aria-controls="tab-location-assignment"
                            aria-selected="false">
                            <i class="ti ti-map-pin mx-1 fs-3"></i>
                            <span class="d-none d-md-block">Locations</span>
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'devices' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-devices-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-devices"
                            wire:click="setActiveTab('devices')"
                            type="button"
                            role="tab"
                            aria-controls="tab-devices"
                            aria-selected="false">
                            <i class="ti ti-device-mobile fs-3"></i>
                            <span class="d-none d-md-block">Devices</span>
                        </button>
                    </li>

                </ul>
            @endif

            @if($activeParentTab === 'users')
                <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist"  style="background-color: #F3F4F6;">
                    

                    <!-- User -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'employee_defaults' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-employee-defaults-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-employee-defaults"
                            type="button"
                            wire:click="setActiveTab('employee_defaults')"
                            role="tab"
                            aria-controls="tab-employee-defaults"
                            aria-selected="false">
                            <i class="ti ti-users mx-1 fs-3"></i>
                            <span class="d-none d-md-block"> Employee Defaults</span>
                        </button>
                    </li>


                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'departments' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-departments-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-departments"
                            type="button"
                            wire:click="setActiveTab('departments')"
                            role="tab"
                            aria-controls="tab-departments"
                            aria-selected="false">
                            <i class="ti ti-building-warehouse mx-1 fs-3"></i>
                            <span class="d-none d-md-block">Departments</span>
                        </button>
                    </li>

                </ul>
            @endif

            @if($activeParentTab === 'roles')
                <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist"  style="background-color: #F3F4F6;">
                    

                    <!-- User -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'users' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-users-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#tab-users"
                            type="button"
                            wire:click="setActiveTab('users')"
                            role="tab"
                            aria-controls="tab-users"
                            aria-selected="false">
                            <i class="ti ti-users mx-1 fs-3"></i>
                            <span class="d-none d-md-block"> Users</span>
                        </button>
                    </li>


                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'user_roles' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-user-roles-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#user-roles"
                            type="button"
                            wire:click="setActiveTab('user_roles')"
                            role="tab"
                            aria-controls="tab-user-roles"
                            aria-selected="false">
                            <i class="ti ti-building-warehouse mx-1 fs-3"></i>
                            <span class="d-none d-md-block">Roles & Permissions</span>
                        </button>
                    </li>



                    <!-- Roles & Permissions -->
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link position-relative rounded-0 {{ $activeTab === 'qr_token_management' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-medium"
                            id="tab-qr-token-management-tab"
                            data-bs-toggle="pill"
                            data-bs-target="#qr-token"
                            type="button"
                            wire:click="setActiveTab('qr_token_management')"
                            role="tab"
                            aria-controls="tab-qr-token-management"
                            aria-selected="false">
                            <i class="ti ti-qrcode mx-1 fs-3"></i>
                            <span class="d-none d-md-block">QR Token Management</span>
                        </button>
                    </li>

                </ul>
            @endif


            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade {{ $activeParentTab === 'company' || $activeTab === 'company'  ? 'show active' : '' }}"
                         id="tab-company-information">

                        <livewire:admin.organizations.edit :id="$org->id"/>

                    </div>

                    <!-- Locations and Assignments Tab -->
                    <div class="tab-pane fade {{ $activeTab === 'location' ? 'show active' : '' }}"
                         id="tab-location-assignment">

                        <livewire:admin.location-assignment.index/>

                    </div>

                    <!-- Devices Tab -->
                    <div class="tab-pane fade {{ $activeTab === 'devices' ? 'show active' : '' }}"
                         id="tab-devices">

                        <livewire:admin.devices.index/>

                    </div>



                     <!-- User Roles & Permissions Content -->
                    <div class="tab-pane fade  {{ $activeTab === 'user_roles' ? 'show active' : '' }}" id="user-roles" role="tabpanel"
                            aria-labelledby="user-roles-tab">
                        <livewire:admin.roles.index/>
                    </div>

                    <!-- QR Token Management Placeholder -->
                    <div class="tab-pane fade  {{ $activeTab === 'qr_token_management' ? 'show active' : '' }}" id="qr-token" role="tabpanel" aria-labelledby="qr-token-tab">
                        <livewire:admin.account-settings.token_management/>
                    </div>


                    <!-- Employee defaults -->                        
                    <div class="tab-pane fade {{ $activeTab === 'employee_defaults' ? 'show active' : '' }}" id="tab-employee-defaults" role="tabpanel" aria-labelledby="employee-defaults-tab">
                        <livewire:admin.account-settings.employee_defaults/>
                    </div>

                    <!-- Employee defaults -->                        
                    <div class="tab-pane fade {{ $activeTab === 'departments' ? 'show active' : '' }}" id="tab-departments" role="tabpanel" aria-labelledby="departments-tab">                        
                        <livewire:admin.departments.index/>
                    </div>


                </div>
            </div>
        </div>

    </div>
</div>



@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('button[data-bs-toggle="pill"]');

            tabs.forEach(tab => {
                tab.addEventListener('shown.bs.tab', function (event) {
                    const tabId = event.target.id;

                    // Map Bootstrap tab IDs to your internal tab names
                    let mappedTab;
                    switch (tabId) {
                        case 'tab-company-information':
                            mappedTab = 'company';
                            break;
                        case 'tab-roles-permissions-tab':
                            mappedTab = 'roles';
                            break;
                        case 'tab-users-tab':
                            mappedTab = 'users';
                            break;
                        case 'tab-location-assignment-tab':
                            mappedTab = 'location-assignment';
                            break;
                        case 'tab-devices-tab':
                            mappedTab = 'devices';
                            break;
                        default:
                            mappedTab = 'company';
                    }

                    Livewire.dispatch('tabChanged', {tabId: mappedTab});

                });
            });
        });
    </script>
@endpush




