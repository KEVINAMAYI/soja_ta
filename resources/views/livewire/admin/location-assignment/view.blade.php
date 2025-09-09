<?php

use App\Models\DeviceLocation;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Volt\Component;

new class extends Component {

    public $workLocation;
    public $workLocationId;
    public $checkin_name;
    public $checkin_description;
    public $checkin_active = true;

    public function mount(WorkLocation $workLocation)
    {
        $this->workLocation = $workLocation;
        $this->workLocationId = $workLocation->id;

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

            dd($e->getMessage());

            LivewireAlert::title('Error!')
                ->text('Failed to add Checkpoint.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
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

    @php
        use Illuminate\Support\Str;

        // Dynamic text color class for active status
        $statusColor = match((bool) $workLocation->active) {
            true => 'text-success',
            false => 'text-danger'
        };
    @endphp

    <div class="container py-4">
        <!-- Profile Header -->
        <div class="profile-header mb-4">
            <div class="profile-initials">
                <iconify-icon icon="mdi:office-building"></iconify-icon>
            </div>
            <div class="profile-info">
                <h3 class="mb-1">{{ Str::title($workLocation->name) }}</h3>
                <p class="mb-2 text-muted">
                    Type:
                    <span class="fw-medium text-dark">{{ ucfirst($workLocation->type) }}</span>
                </p>

                <p class="mb-1">
                <span class="me-3">
                    Geofence Radius: <strong>{{ $workLocation->radius_m }} M</strong>
                </span>

                </p>
                <p>
                    <span>
                    Status:
                    <span class="fw-bold {{ $statusColor }}">
                        {{ $workLocation->active ? 'Active' : 'Inactive' }}
                    </span>
                </span>
                </p>

                @if ($workLocation->description)
                    <p class="text-muted mt-2 mb-0">
                        {{ ucfirst($workLocation->description) }}
                    </p>
                @endif
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#assigned-users">Assigned Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#checkin-points">Device Check-in Points</a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- Assigned Users Tab -->
            <div class="tab-pane fade show active" id="assigned-users">
                <h6 class="mb-3">Users Assigned to This Location</h6>

                {{-- Livewire Table --}}
                <livewire:work-location-employee-table :workLocationId="$workLocationId ?? null" theme="bootstrap-4"/>

            </div>

            <!-- Device Check-in Points Tab -->
            <div class="tab-pane fade" id="checkin-points">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Check-in Points for This Location</h6>
                    <button class="btn btn-primary d-flex align-items-center gap-2 px-3 py-2 rounded"
                            data-bs-toggle="modal"
                            data-bs-target="#addCheckinPointModal">
                        <iconify-icon icon="mdi:plus-circle" style="font-size: 20px; color: white;"></iconify-icon>
                        Add Check-in Point
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

</div>

@push('scripts')
    <script>
        window.addEventListener('show-device-location-modal', () => {
            new bootstrap.Modal(document.getElementById('addCheckinPointModal')).show();
        });

        window.addEventListener('hide-device-location-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('addCheckinPointModal'))?.hide();
        });
    </script>
@endpush
