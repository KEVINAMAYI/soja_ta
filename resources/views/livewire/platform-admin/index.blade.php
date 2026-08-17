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
    public string $activeParentTab = 'clients';
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


    // department creation validation rules
    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:departments,name,' . $this->editId,
            'description' => 'nullable|string|max:1000',
            'manager_id' => 'nullable|exists:users,id',
        ];
    }



    public function setActiveParentTab(string $parentTab, string $defaultChildTab = ''): void
    {

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

            case 'user_roles':
                $this->tabTitle = 'Roles & Permissions';
                $this->tabIcon = '<iconify-icon icon="mdi:shield-outline" class="fs-5"></iconify-icon>';
                break;

            case 'qr_token_management':
                $this->tabTitle = 'QR Token Management';
                $this->tabIcon = '<iconify-icon icon="mdi:qrcode-scan" class="fs-5"></iconify-icon>';
                break;

            default:
                $this->tabTitle = 'Platform';
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
                'label' => 'Platform Admin',
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

                <!-- Tenants -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'clients' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-clients-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-clients"
                        type="button"
                        wire:click="setActiveParentTab('clients', '')"
                        role="tab"
                        aria-controls="tab-clients"
                        aria-selected="false">
                        <i class="ti ti-smart-home  mx-1 fs-6"></i>
                        <span class="d-none d-md-block">Client Accounts</span>
                    </button>
                </li>
                
                <!-- User Roles & Permissions -->
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link position-relative rounded-0 {{ $activeParentTab === 'roles' ? 'active' : '' }} d-flex align-items-center justify-content-center bg-transparent fs-3 py-3 fw-bold"
                        id="tab-roles-permissions-tab"
                        data-bs-toggle="pill"
                        data-bs-target="#tab-roles-permissions"
                        type="button"
                        wire:click="setActiveParentTab('roles', 'users')"
                        role="tab"
                        aria-controls="tab-roles-permissions"
                        aria-selected="false">
                        <i class="ti ti-shield mx-1 fs-6"></i>
                        <span class="d-none d-md-block">User Roles & Permissions</span>
                    </button>
                </li>

            </ul>


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

                </ul>
            @endif


            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">

                    <!-- Working Hours Tab -->
                    <div class="tab-pane fade {{ $activeParentTab === 'clients' || $activeTab === 'clients'  ? 'show active' : '' }}"
                         id="tab-company-information">

                        <livewire:platform-admin.tenants :id="$org->id"/>

                    </div>



                    <!-- User Content -->
                    <div class="tab-pane fade  {{ $activeTab === 'users' ? 'show active' : '' }}" id="users" role="tabpanel"
                            aria-labelledby="users-tab">
                        <livewire:platform-admin.users.index/>
                    </div>


                     <!-- User Roles & Permissions Content -->
                    <div class="tab-pane fade  {{ $activeTab === 'user_roles' ? 'show active' : '' }}" id="user-roles" role="tabpanel"
                            aria-labelledby="user-roles-tab">
                        <livewire:platform-admin.roles.index/>
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




