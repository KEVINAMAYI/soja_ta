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

    public function mount()
    {
        $orgId = auth()->user()->employee?->organization_id;

        $this->locations = DeviceLocation::where('organization_id', $orgId)
            ->pluck('name', 'id')
            ->toArray();
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


<div class="row">
    <div class="col-12">
        <div class="card card-body">
            {{-- Top Bar: Add Button --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Devices</h5>
                <a href="javascript:void(0)" class="btn btn-primary" wire:click="resetForm" data-bs-toggle="modal"
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


</div>

@push('scripts')
    <script>
        window.addEventListener('show-device-modal', () => {
            new bootstrap.Modal(document.getElementById('deviceModal')).show();
        });

        window.addEventListener('hide-device-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('deviceModal'))?.hide();
        });
    </script>
@endpush


