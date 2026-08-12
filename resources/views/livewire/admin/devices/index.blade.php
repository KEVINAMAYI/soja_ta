<?php

use App\Models\Device;
use App\Models\DeviceLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public $device_name, $platform = 'android', $checkpoint_id, $pin;
    public $device_location_id;
    public $editId;
    public $locations = [];
    public int $active_device_count = 0;
    public int $inactive_device_count = 0;
    public $device = null;

    public function mount()
    {
        $orgId = auth()->user()->employee?->organization_id;

        $this->locations = DeviceLocation::where('organization_id', $orgId)
            ->withCount('devices')
            ->pluck('name', 'id')
            ->toArray();

        $device_count = Device::query()
            ->where('organization_id', $orgId)
            ->selectRaw('
                SUM(active = 1) as active_devices_count,
                SUM(active = 0) as inactive_devices_count
            ')
            ->first();

        $this->active_device_count = $device_count->active_devices_count ?? 0;
        $this->inactive_device_count = $device_count->inactive_devices_count ?? 0;
    }

    public function rules()
    {
        return [
            'device_name' => 'required|string|max:255',
            'platform' => 'required|in:android,ios',
            'device_location_id' => 'required|exists:device_locations,id',
            'checkpoint_id' => 'required|string|max:50|unique:devices,checkpoint_id,' . $this->editId,
            'pin' => 'required|string|max:10',
        ];
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
        $this->validate();

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
                ->text('Something went wrong while updating the employee.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    #[On('show-device')]
    public function showDevice($id)
    {
        $this->device = Device::where('id', $id)->first();
        $this->dispatch('show-view-device-modal', ['device' => $this->device->toArray()]);
    }


    #[On('remove-device')]
    public function removeDevice($id)
    {

        DB::beginTransaction();

        try {
            $device = Device::findOrFail($id);

            $device->delete();

            DB::commit();

            $this->dispatch('refreshDatatable');
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
                'device_id' => $id,
            ]);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while removing device.')
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
};

?>



@push('styles')
    <style>


        .import-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .summary-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.4rem 1.5rem 1.2rem;
            height: 100%;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
        }

        .summary-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 0.9rem;
        }

        .summary-card-title {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.35rem;
        }

        .summary-card-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }

        .summary-card-subtitle {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }

    </style>
@endpush


<div class="">
    <!-- Commented out summary of devices... might be needed in the future -->
    <!-- <div class="row">
        <div class="col-lg-4 col-md-6 col-12 border-radius-1">
            <div class="summary-card">
                <p class="summary-card-title">Total Devices</p>
                <div class="summary-card-value">{{
                    $active_device_count + $inactive_device_count
                    }}</div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6 col-12">
            <div class="summary-card">
                <p class="summary-card-title">Online</p>
                <div class="summary-card-value text-secondary">{{$active_device_count}}</div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6 col-12">
            <div class="summary-card">
                <p class="summary-card-title">Offline</p>
                <div class="summary-card-value text-danger">{{$inactive_device_count}}</div>
            </div>
        </div>

    </div> -->

    <div class="col-12">
        <div class="card card-body">
            {{-- Top Bar: Add Button --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Devices</h5>
                <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                   data-bs-target="#deviceModal">
                    <i class="ti ti-device-mobile fs-5"></i> Add Device
                </a>
            </div>

            {{-- Livewire Device Table --}}
            <livewire:devices-table theme="bootstrap-4"/>
        </div>
    </div>

    <!-- Modal -->
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


    <!-- Show device Modal -->
    <div class="modal fade" id="showDeviceModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="saveDevice">
                    <div class="modal-header">
                        <h5 class="modal-title">Viewing Device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">
                        <!-- Device Name -->
                        <div class="col-12">
                            <label class="form-label">Device Name</label>
                            <input type="text" class="form-control"
                                value="{{ $device? $device->device_name : '' }}">
                            @error('device_name') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Platform -->
                        <div class="col-md-12">
                            <label class="form-label">Platform</label>
                            <select class="form-select" wire:model="platform">
                                <option value="android" {{ $device && $device->platform === 'android' ? 'selected' : '' }}>Android</option>
                                <option value="ios" {{ $device && $device->platform === 'ios' ? 'selected' : '' }}>iOS</option>
                            </select>
                            @error('platform') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Location -->
                        <div class="col-md-12">
                            <label class="form-label">Location</label>
                            <select class="form-select" wire:model="device_location_id">
                                <option value="">Select Location</option>
                                @foreach($locations as $id => $name)
                                    <option value="{{ $id }}" {{ $device && $device->device_location_id == $id ? 'selected' : '' }}>{{ $name }}</option>
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
                                       value="{{ $device? $device->checkpoint_id : '' }}" readonly>
                                <!-- <button type="button"
                                        wire:click="generateCheckpointId"
                                        class="custom-hover-white btn btn-outline-primary">
                                    Auto-generate
                                </button> -->
                            </div>
                            @error('checkpoint_id') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <!-- PIN (Input Group) -->
                        <div class="col-md-12">
                            <label class="form-label">PIN</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       value="{{ $device? $device->pin : '' }}" readonly>
                                <!-- <button type="button"
                                        wire:click="generatePin"
                                        class="custom-hover-white btn btn-outline-primary">
                                    Auto-generate
                                </button> -->
                            </div>
                            @error('pin') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                    </div>

                    <div class="mt-3 modal-footer">
                        <!-- <button type="submit" class="btn btn-success">Add Device</button> -->
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
    <script>
        window.addEventListener('show-device-modal', () => {
            new bootstrap.Modal(document.getElementById('deviceModal')).show();
        });

        window.addEventListener('hide-device-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('deviceModal'))?.hide();
        });

        window.addEventListener('show-view-device-modal', () => {
            new bootstrap.Modal(document.getElementById('showDeviceModal')).show();
        });

        window.addEventListener('hide-view-device-modal', () => {
            console.log("HIDING VIEW DEVICE MODAL...");
            bootstrap.Modal.getInstance(document.getElementById('showDeviceModal'))?.hide();
        });
    </script>
@endpush


