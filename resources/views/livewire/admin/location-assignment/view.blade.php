<?php

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\WorkLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $workLocation;
    public $workLocationId;
    public $checkin_name;
    public $checkin_description;
    public $checkin_active = true;
    public $device_name, $platform = 'android', $checkpoint_id, $pin;
    public $device_location_id;
    public $editId;
    public $locations = [];
    public $deviceCount = 0;
    public $employeeCount = 0;
    public $checkinCount = 0;
    public $googleMapsApiKey;
    public $employeeLocations = [];
    public $activeTab = 'assigned-users';

    public function mount(WorkLocation $workLocation)
    {
        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

        // Current user's employee record (may be null)
        $employeeRecord = auth()->user()?->employee;

        // Organization id fallback
        $orgId = $employeeRecord?->organization_id ?? $workLocation->organization_id;

        $this->workLocation = $workLocation;
        $this->workLocationId = $workLocation->id;
        $this->locations = DeviceLocation::where('organization_id', $orgId)
            ->pluck('name', 'id')
            ->toArray();

        // Dynamic counts
        $this->refreshCounts();

        $today = Carbon::today();
        $employeeIds = Employee::where('organization_id', $this->workLocation->organization_id)
            ->pluck('id');

        $this->employeeLocations = Attendance::with([
            'employee.department',
            'employee.currentAssignment.location',
        ])
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', $today)
            ->whereNotNull('check_in_time')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('check_in_time', 'desc')
            ->get()
            ->groupBy('employee_id') // only keep one row per employee
            ->map(function ($records) use ($employeeRecord) {
                $last = $records->first(); // most recent check-in
                $workLocation = $last->employee->currentAssignment?->location;

                return [
                    'name' => $last->employee->name,
                    'department' => $last->employee->department->name ?? 'N/A',
                    'clock_in' => Carbon::parse($last->check_in_time)->format('h:i A'),
                    'lat' => $last->latitude,
                    'lng' => $last->longitude,

                    // 🔑 key for filtering/grouping in JS
                    'work_location_id' => $workLocation?->id
                        ?? $employeeRecord->organization->location?->id
                            ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    #[On('refreshCounts')]
    public function refreshCounts()
    {
        $this->deviceCount = $this->workLocation->devices()->count();
        $this->employeeCount = $this->workLocation->assignments()->count();
        $this->checkinCount = $this->workLocation->deviceLocations()->count();
    }

    #[On('show-devices')]
    public function showDevices($id)
    {
        $this->dispatch('show-devices-offcanvas');
    }

    public function addDeviceCheckinPoint()
    {

        $org_id = auth()->user()->employee->organization->id;

        $this->validate([
            'checkin_name' => 'required|string|max:255',
            'checkin_description' => 'nullable|string',
            'checkin_active' => 'required|boolean',
        ]);

        try {

            DB::beginTransaction();

            DeviceLocation::create([
                'work_location_id' => $this->workLocation->id,
                'organization_id' => $org_id,
                'name' => $this->checkin_name,
                'description' => $this->checkin_description ?? '',
                'active' => $this->checkin_active,
            ]);

            DB::commit();

            $this->dispatch('hide-device-location-modal');
            $this->activeTab = 'checkin-points';
            $this->refreshCounts();

            LivewireAlert::title('Awesome!')
                ->text('Checkpoint added successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            // Optional: Reset form
            $this->reset([
                'checkin_name',
                'checkin_description',
                'checkin_active',
            ]);

            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {

            DB::rollBack();

            LivewireAlert::title('Error!')
                ->text('Failed to add Checkpoint.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    public function generateCheckpointId()
    {
        $this->checkpoint_id = 'CHKPT-' . strtoupper(Str::random(5));
    }

    public function generatePin()
    {
        $this->pin = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    }


    public function saveDevice()
    {
        $this->validate([
            'device_name' => 'required|string|max:255',
            'platform' => 'required|in:android,ios',
            'device_location_id' => 'required|exists:device_locations,id',
            'checkpoint_id' => 'required|string|max:50|unique:devices,checkpoint_id,' . $this->editId,
            'pin' => 'required|string|max:10',
        ]);

        DB::beginTransaction();

        try {
            $orgId = auth()->user()->employee?->organization_id;

            Device::create([
                'organization_id' => $orgId,
                'device_name' => $this->device_name,
                'platform' => $this->platform,
                'checkpoint_id' => $this->checkpoint_id,
                'pin' => $this->pin,
                'device_location_id' => $this->device_location_id,
                'active' => true,
            ]);

            DB::commit();

            $this->dispatch('hide-device-modal');
            $this->activeTab = 'checkin-points';
            $this->refreshCounts();

            LivewireAlert::title('Awesome!')
                ->text('Device Added successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Device creation failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while adding device.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    #[On('remove-device')]
    public function removeDevice($deviceId)
    {
        var_dump('testst');

        DB::beginTransaction();

        try {
            $device = Device::findOrFail($deviceId);

            $device->delete();

            DB::commit();

            $this->dispatch('refreshDatatable');
            $this->activeTab = 'checkin-points';
            $this->refreshCounts();

            LivewireAlert::title('Deleted!')
                ->text('Device removed successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Device removal failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'device_id' => $deviceId,
            ]);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while removing device.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    #[On('unassign-work-location')]
    public function unassignWorkLocation($id)
    {
        DB::beginTransaction();

        try {
            // Find the current active assignment for this employee
            $assignment = EmployeeAssignment::where('employee_id', $id)
                ->where('is_current', true)
                ->where('work_location_id', $this->workLocationId)
                ->first();

            if ($assignment) {
                // Mark assignment as ended instead of deleting (safer for history)
                $assignment->update([
                    'is_current' => false,
                    'end_date' => now(),
                ]);

                DB::commit();

                $this->dispatch('refreshDatatable');

                LivewireAlert::title('Success!')
                    ->text('Employee has been unassigned from the work location.')
                    ->success()
                    ->toast()
                    ->position('top-end')
                    ->show();
            } else {
                DB::rollBack();

                LivewireAlert::title('Notice')
                    ->text('This employee is not currently assigned to any location.')
                    ->info()
                    ->toast()
                    ->position('top-end')
                    ->show();
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Failed to unassign employee from location.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    public function resetForm()
    {
        $this->reset(['device_name', 'platform', 'device_location_id', 'checkpoint_id', 'pin', 'editId']);
        $this->platform = 'android';
    }


}; ?>

@push('styles')
    <style>


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

        /* Profile Header */
        .profile-header {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }

        .profile-photo {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 3px solid #dee2e6;
            object-fit: cover;
            background: white;
        }

        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }

        .profile-info p {
            margin: 0;
            color: #6c757d;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
        }

        .nav-tabs .nav-link {
            color: #495057;
            border: none;
            padding: 0.75rem 1.25rem;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 600;
            border-radius: 0px;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }

        /* Cards */
        .stat-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            background: white;
        }

        /* Table styling */
        .table thead {
            background-color: #f8f9fa;
        }

        .table tbody tr:hover {
            background-color: #f1f3f5;
        }

        /* Badges */
        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-onleave {
            background-color: #0dcaf0;
            color: #212529;
        }

        .summary-info {
            margin-bottom: 1rem;
            font-weight: 600;
            color: #495057;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .summary-info .summary-left {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        .profile-header {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }

        .profile-initials {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 48px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            user-select: none;
            box-shadow: 0 0 10px rgba(13, 110, 253, 0.4);
        }

        .profile-info h3 {
            font-weight: 700;
            margin-bottom: 0.3rem;
            color: #2c3e50;
        }

        .profile-info p {
            margin: 0;
            color: #6c757d;
        }
    </style>
@endpush

<div>


    <livewire:admin.system-settings.bread-crumb
        title="Work Location"
        :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Account Settings',
            'url' => route('account-settings.index'),
            'icon' => '<iconify-icon icon=\'mdi:cog-outline\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Location and Assignments',
            'url' => route('account-settings.index'),
            'icon' => '<iconify-icon icon=\'mdi:map-marker-radius-outline\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => Str::title($workLocation->name),
            'icon' => '<iconify-icon icon=\'mdi:office-building-marker-outline\' class=\'fs-5\'></iconify-icon>',
        ],
    ]"
    />


    @php
        use Illuminate\Support\Str;

        // Dynamic text color class for active status
        $statusColor = match((bool) $workLocation->active) {
            true => 'text-success',
            false => 'text-danger'
        };
    @endphp

    <div class="container">

        <!-- Profile Header Card -->
        <div class="d-flex align-items-center p-4 bg-white shadow-sm rounded-3 mb-4">

            <!-- Circle Icon -->
            <div
                class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle bg-primary text-white me-3"
                style="width: 64px; height: 64px; font-size: 28px;">
                <iconify-icon icon="mdi:office-building"></iconify-icon>
            </div>

            <!-- Info -->
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-1">{{ Str::title($workLocation->name) }}</h4>

                <div class="text-muted small mb-2">
            <span class="me-3">
                <strong>Type:</strong> {{ ucfirst($workLocation->type) }}
            </span>
                    <span class="me-3">
                <strong>Geofence Radius:</strong> {{ $workLocation->radius_m }} M
            </span>
                    <span>
                <strong>Status:</strong>
                @if ($workLocation->active)
                            <span class="badge bg-light-success text-success">● Active</span>
                        @else
                            <span class="badge bg-light-danger text-danger">● Inactive</span>
                        @endif
            </span>
                </div>

                @if ($workLocation->description)
                    <p class="mb-0 text-muted">{{ ucfirst($workLocation->description) }}</p>
                @endif
            </div>
        </div>


        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header  justify-content-start">
                        <span style="color:black; font-weight:bold;">Location & Geofence</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="work-location-map" wire:ignore style="height:350px; width:100%;"></div>
                    </div>
                </div>
            </div>


            <!-- Branch Statistics -->
            <div class="col-md-4">
                <div class="card shadow-sm rounded-3 h-100">
                    <div style="color:black; font-weight:bold;" class="card-header fw-bold">Work Location Statistics
                    </div>
                    <div class="card-body">
                        <div class="row g-3">

                            <!-- Devices Inside -->
                            <div class="col-12">
                                <div class="card-action text-center p-3 bg-light rounded-3 shadow-sm">
                                    <span class="iconify mb-2 text-primary" data-icon="mdi:devices"
                                          style="font-size: 32px;"></span>
                                    <div class="fs-5 fw-bold text-primary">{{ $deviceCount }}</div>
                                    <span class="text-muted">Devices Inside</span>
                                </div>
                            </div>

                            <!-- Employees Assigned -->
                            <div class="col-12">
                                <div class="card-action text-center p-3 bg-light rounded-3 shadow-sm">
                                    <span class="iconify mb-2 text-success" data-icon="mdi:account-group-outline"
                                          style="font-size: 32px;"></span>
                                    <div class="fs-5 fw-bold text-success">{{ $employeeCount }}</div>
                                    <span class="text-muted">Employees Assigned</span>
                                </div>
                            </div>

                            <!-- Check-in Points -->
                            <div class="col-12">
                                <div class="card-action text-center p-3 bg-light rounded-3 shadow-sm">
                                    <span class="iconify mb-2 text-info" data-icon="mdi:map-marker-check-outline"
                                          style="font-size: 32px;"></span>
                                    <div class="fs-5 fw-bold text-info">{{ $checkinCount }}</div>
                                    <span class="text-muted">Clock-in Points</span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>


        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link {{ $activeTab === 'assigned-users' ? 'active' : '' }}"
                   href="#"
                   wire:click.prevent="$set('activeTab', 'assigned-users')">
                    Assigned Employees
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $activeTab === 'checkin-points' ? 'active' : '' }}"
                   href="#"
                   wire:click.prevent="$set('activeTab', 'checkin-points')">
                    Clock-in Points
                </a>
            </li>
        </ul>


        <div class="tab-content">

            <!-- Assigned Users Tab -->
            <div class="tab-pane fade {{ $activeTab === 'assigned-users' ? 'show active' : '' }}" id="assigned-users">
                <h6 class="mb-3">Users Assigned to This Location</h6>

                {{-- Livewire Table --}}
                <livewire:work-location-employee-table :workLocationId="$workLocationId ?? null" theme="bootstrap-4"/>

            </div>

            <!-- Device Check-in Points Tab -->
            <div class="tab-pane fade {{ $activeTab === 'checkin-points' ? 'show active' : '' }}" id="checkin-points">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Clock-in Points for This Location</h6>
                    <button class="btn btn-primary d-flex align-items-center gap-2 px-3 py-2 rounded"
                            data-bs-toggle="modal"
                            data-bs-target="#addCheckinPointModal">
                        <iconify-icon icon="mdi:plus-circle" style="font-size: 20px; color: white;"></iconify-icon>
                        Add Clock-in Point
                    </button>
                </div>

                {{-- Livewire Table --}}
                <livewire:device-locations-table :workLocationId="$workLocationId ?? null" theme="bootstrap-4"/>

            </div>

        </div>
    </div>

    <!-- Add Check-in Point Modal -->
    <div class="modal fade" id="addCheckinPointModal" tabindex="-1" aria-labelledby="addCheckinPointModalLabel"
         aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCheckinPointModalLabel">Add Check-in Point</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form wire:submit.prevent="addDeviceCheckinPoint">
                    <div class="modal-body">

                        <div class="mb-3">
                            <label for="checkinPointName" class="form-label">Name</label>
                            <input type="text" wire:model="checkin_name" class="form-control" id="checkinPointName"
                                   required>
                            @error('checkin_name')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="checkinPointDescription" class="form-label">Description</label>
                            <textarea wire:model="checkin_description" class="form-control" id="checkinPointDescription"
                                      rows="3"></textarea>
                            @error('checkin_description')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="checkinPointStatus" class="form-label">Status</label>
                            <select wire:model="checkin_active" class="form-select" id="checkinPointStatus" required>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            @error('checkin_active')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                    </div>

                    <div class="modal-footer d-flex gap-1">
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Check-in Point</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <div style="width: 80vw; max-width: 1000px;"
         class="offcanvas offcanvas-end"
         tabindex="-1"
         id="checkpointDevices"
         wire:ignore.self
         aria-labelledby="checkpointDevicesLabel"
         data-bs-backdrop="static">

        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="checkpointDevicesLabel">
                Devices for Checkpoint
            </h5>
            <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>

        <div class="offcanvas-body">

            <!-- Add Device Button -->
            <div class="d-flex justify-content-end my-3">
                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#deviceModal">
                    <i class="ti ti-plus"></i> Add Device
                </button>
            </div>

            {{-- Livewire Device Table --}}
            <livewire:devices-table theme="bootstrap-4"/>

        </div>
    </div>


    <!-- Add Device Modal -->
    <div class="modal fade" id="deviceModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveDevice">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">
                        <!-- Device Name -->
                        <div class="col-12">
                            <label class="form-label">Device Name</label>
                            <input type="text" class="form-control" wire:model="device_name"
                                   placeholder="e.g., Main Entrance Tablet">
                            @error('device_name') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Platform -->
                        <div class="col-md-12">
                            <label class="form-label">Platform</label>
                            <select class="form-select" wire:model="platform">
                                <option value="android">Android</option>
                                <option value="ios">iOS</option>
                            </select>
                            @error('platform') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Location -->
                        <div class="col-md-12">
                            <label class="form-label">Location</label>
                            <select class="form-select" wire:model="device_location_id">
                                <option value="">Select Location</option>
                                @foreach($locations as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('device_location_id') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Checkpoint ID (Input Group) -->
                        <div class="col-md-12">
                            <label class="form-label">Checkpoint ID</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       wire:model="checkpoint_id"
                                       placeholder="e.g., CHKPT-005">
                                <button type="button"
                                        wire:click="generateCheckpointId"
                                        class="custom-hover-white btn btn-outline-primary">
                                    Auto-generate
                                </button>
                            </div>
                            @error('checkpoint_id') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <!-- PIN (Input Group) -->
                        <div class="col-md-12">
                            <label class="form-label">PIN</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       wire:model="pin"
                                       placeholder="Leave empty to auto-generate">
                                <button type="button"
                                        wire:click="generatePin"
                                        class="custom-hover-white btn btn-outline-primary">
                                    Auto-generate
                                </button>
                            </div>
                            @error('pin') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                    </div>

                    <div class="mt-3 modal-footer">
                        <button type="submit" class="btn btn-success">Add Device</button>
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal"
                                wire:click="resetForm">Cancel
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>


</div>

@push('scripts')
    <script src="https://code.iconify.design/3/3.1.0/iconify.min.js"></script>

    <script>
        // Livewire/Alpine event listeners
        window.addEventListener('show-devices-offcanvas', () => {
            const el = document.getElementById('checkpointDevices');
            const offcanvas = new bootstrap.Offcanvas(el);
            offcanvas.show();
        });

        window.addEventListener('show-device-location-modal', () => {
            new bootstrap.Modal(document.getElementById('addCheckinPointModal')).show();
        });

        window.addEventListener('hide-device-location-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('addCheckinPointModal'))?.hide();
        });

        window.addEventListener('show-device-modal', () => {
            new bootstrap.Modal(document.getElementById('deviceModal')).show();
        });

        window.addEventListener('hide-device-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('deviceModal'))?.hide();
        });

        const workLocation = @json($workLocation);
        const employeeLocations = @json($employeeLocations);

        function initWorkLocationMap() {
            if (!workLocation.latitude || !workLocation.longitude) return;

            const center = {
                lat: parseFloat(workLocation.latitude),
                lng: parseFloat(workLocation.longitude)
            };

            const map = new google.maps.Map(document.getElementById("work-location-map"), {
                zoom: 14,
                center: center,
            });

            const bounds = new google.maps.LatLngBounds();
            let activeInfoWindow = null;

            // --- Draw geofence circle ---
            new google.maps.Circle({
                strokeColor: "#FF0000",
                strokeOpacity: 0.8,
                strokeWeight: 2,
                fillColor: "#FF0000",
                fillOpacity: 0.15,
                map,
                center: center,
                radius: parseFloat(workLocation.radius_m) || 100,
            });

            // --- Group employees checked in for this location ---
            const checkedInEmployees = employeeLocations.filter(e => e.work_location_id === workLocation.id);

            // --- Build styled info content ---
            let infoContent = `
                <div style="background:#fff; padding:12px; border-radius:6px;
                            box-shadow:0 2px 6px rgba(0,0,0,0.15); min-width:240px;">
                    <div style="font-weight:700; font-size:16px; color:#333; margin-bottom:4px;">
                        ${workLocation.name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                    </div>
                    <div style="font-size:13px; color:#555; margin-bottom:8px;">
                        ${workLocation.address || 'No address provided'}
                    </div>`;

            if (checkedInEmployees.length > 0) {
                infoContent += `
                    <div style="font-size:14px; color:#222; margin-bottom:6px;">
                        <b>${checkedInEmployees.length} Employee${checkedInEmployees.length > 1 ? 's' : ''}</b> checked in:
                    </div>
                `;
                checkedInEmployees.forEach(e => {
                    infoContent += `
                        <div style="font-size:13px; color:#444; margin-bottom:3px;">
                            <b>${e.name}</b> (${e.department}) – Clock In: ${e.clock_in}
                        </div>
                    `;
                });
            } else {
                infoContent += `<div style="font-size:13px; color:#888;">No employees checked in</div>`;
            }

            infoContent += `</div>`;

            // --- Add marker for work location ---
            const infoWindow = new google.maps.InfoWindow({content: infoContent});
            const marker = new google.maps.Marker({
                position: center,
                map,
                icon: {
                    url: "/images/map_marker.png",
                    scaledSize: new google.maps.Size(55, 60), // resize (width, height)
                    anchor: new google.maps.Point(20, 40)
                }
            });

            marker.addListener("click", () => {
                if (activeInfoWindow) activeInfoWindow.close();
                infoWindow.open(map, marker);
                activeInfoWindow = infoWindow;
            });

            bounds.extend(center);

            // --- Fit map to bounds ---
            if (!bounds.isEmpty()) {
                map.fitBounds(bounds);

                // 👇 cap zoom (don’t let it zoom out too far)
                google.maps.event.addListenerOnce(map, "bounds_changed", function () {
                    if (map.getZoom() > 16) map.setZoom(16);  // street level
                    if (map.getZoom() < 14) map.setZoom(14);  // prevent zooming out too much
                });
            } else {
                map.setCenter({lat: -1.2921, lng: 36.8219});
                map.setZoom(15);
            }

        }

    </script>

    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&callback=initWorkLocationMap">
    </script>
@endpush
