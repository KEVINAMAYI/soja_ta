<?php

use App\Models\WorkLocation;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {
    public $organization_id;
    public $name;
    public $type;
    public $address;
    public $radius_m = 100;
    public $description;
    public $active = true;
    public $editId;
    public $googleMapsApiKey;
    public $latitude;
    public $longitude;

    public function mount()
    {
        if (!auth()->user()?->can('manage-work-locations')) {
            abort(403, 'Unauthorized.');
        }

        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

    }


    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'radius_m' => 'required|integer|min:10',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ];
    }

    public function createWorkLocation()
    {
        $this->validate();

        try {
            DB::beginTransaction();


            $orgId = auth()->user()->employee->organization_id ?? null;

            WorkLocation::create([
                'organization_id' => $orgId,
                'name' => $this->name,
                'type' => $this->type,
                'address' => $this->address,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'radius_m' => $this->radius_m,
                'description' => $this->description,
                'active' => $this->active,
            ]);

            DB::commit();

            $this->dispatch('hide-worklocation-modal');

            LivewireAlert::text('Work Location added successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::text('Failed to add work location.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('edit-worklocation')]
    public function editWorkLocation($id)
    {
        $loc = WorkLocation::findOrFail($id);
        $this->editId = $id;
        $this->name = $loc->name;
        $this->type = $loc->type;
        $this->address = $loc->address;
        $this->latitude = $loc->latitude;
        $this->longitude = $loc->longitude;
        $this->radius_m = $loc->radius_m;
        $this->description = $loc->description;
        $this->active = $loc->active;

        $this->dispatch('show-worklocation-modal');
    }

    public function updateWorkLocation()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            WorkLocation::findOrFail($this->editId)->update([
                'name' => $this->name,
                'type' => $this->type,
                'address' => $this->address,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'radius_m' => $this->radius_m,
                'description' => $this->description,
                'active' => $this->active,
            ]);

            DB::commit();

            $this->dispatch('hide-worklocation-modal');

            LivewireAlert::text('Work Location updated successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::text('Failed to update work location.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    #[On('delete-worklocation')]
    public function deleteWorkLocation($id)
    {
        try {
            WorkLocation::findOrFail($id)->delete();

            LivewireAlert::text('Work Location deleted successfully.!')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Exception $e) {
            report($e);

            LivewireAlert::text('Failed to delete work location.!')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    public function resetForm()
    {
        $this->reset(['organization_id', 'name', 'type', 'radius_m', 'description', 'active', 'editId']);
    }

}; ?>

@push('styles')
    <style>

        #locationInputAssignment {
            margin-top: 0px !important;
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }

        label, .form-label {
            color: #212529 !important;
            font-weight: 500;
        }

        .pac-container {
            z-index: 1055 !important; /* Must be higher than modal */
        }

    </style>
@endpush


<div class="row">
    <div class="col-12">
        <div class="widget-content searchable-container list">
            <div class="card card-body">
                <div class="row">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                        <div></div>
                        <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                           data-bs-target="#worklocationModal">
                            <i class="ti ti-map-pin"></i> Add Work Location
                        </a>
                    </div>
                </div>

                {{-- Livewire Table --}}
                <livewire:work-location-table theme="bootstrap-4"/>

            </div>

            <div class="modal fade" id="worklocationModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editId ? 'Edit Work Location' : 'New Work Location' }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <form wire:submit.prevent="{{ $editId ? 'updateWorkLocation' : 'createWorkLocation' }}">
                            <div class="modal-body">
                                <div class="row">

                                    <div class="col-md-6 mb-3">
                                        <label>Name</label>
                                        <input type="text" wire:model.defer="name" class="form-control">
                                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label>Type</label>
                                        <select wire:model.defer="type" class="form-control">
                                            <option value="">Select Type</option>
                                            <option value="branch">Branch</option>
                                            <option value="remote">Remote</option>
                                            <option value="client">Client</option>
                                            <option value="adhoc">Adhoc</option>
                                        </select>
                                    </div>

                                    {{-- Use wire:ignore to prevent Livewire from destroying the input --}}
                                    <div class="form-group col-12 mb-1" wire:ignore>
                                        <label for="address_address">Address</label>
                                        <input type="text" id="address-input" name="address_address"
                                               class="form-control map-input">
                                        <input type="hidden" name="address_latitude" id="address-latitude"
                                               wire:model="latitude" value="0"/>
                                        <input type="hidden" name="address_longitude" id="address-longitude"
                                               wire:model="longitude" value="0"/>
                                    </div>
                                    <div wire:ignore class="mb-3" id="address-map-container"
                                         style="width:100%;height:400px; ">
                                        <div wire:ignore style="width: 100%; height: 100%" id="address-map"></div>
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label>Working Radius (meters)</label>
                                        <input type="number" wire:model.defer="radius_m" class="form-control">
                                    </div>

                                    <div class="col-md-12 mb-3">
                                        <label>Description</label>
                                        <textarea wire:model.defer="description" class="form-control"></textarea>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label>Active</label>
                                        <input type="checkbox" wire:model.defer="active" class="form-check-input">
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer d-flex gap-1">
                                <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal"
                                        wire:click="$dispatch('discard-worklocation-modal')">
                                    Discard
                                </button>
                                <button type="submit" class="btn btn-success">
                                    {{ $editId ? 'Save' : 'Add' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script async defer
            src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places"></script>

    <script>
        function initializeMapAutocomplete() {

            const locationInputs = document.getElementsByClassName("map-input");
            const geocoder = new google.maps.Geocoder();
            const autocompletes = [];

            for (let i = 0; i < locationInputs.length; i++) {
                const input = locationInputs[i];
                const fieldKey = input.id.replace("-input", "");
                const latField = document.getElementById(fieldKey + "-latitude");
                const lngField = document.getElementById(fieldKey + "-longitude");

                const latitude = parseFloat(latField.value) || -33.8688;
                const longitude = parseFloat(lngField.value) || 151.2195;
                const map = new google.maps.Map(document.getElementById(fieldKey + '-map'), {
                    center: {lat: latitude, lng: longitude},
                    zoom: 13
                });
                const marker = new google.maps.Marker({
                    map: map,
                    position: {lat: latitude, lng: longitude},
                    visible: latField.value !== '' && lngField.value !== ''
                });

                const autocomplete = new google.maps.places.Autocomplete(input);
                autocomplete.key = fieldKey;
                autocompletes.push({input, map, marker, autocomplete});
            }

            autocompletes.forEach(obj => {
                google.maps.event.addListener(obj.autocomplete, 'place_changed', function () {
                    obj.marker.setVisible(false);
                    const place = obj.autocomplete.getPlace();

                    if (!place.geometry) {
                        window.alert("No details available for input: '" + place.name + "'");
                        obj.input.value = "";
                    @this.set('address', formattedAddress)
                        ;
                        return;
                    }

                    if (place.geometry.viewport) {
                        obj.map.fitBounds(place.geometry.viewport);
                    } else {
                        obj.map.setCenter(place.geometry.location);
                        obj.map.setZoom(17);
                    }

                    obj.marker.setPosition(place.geometry.location);
                    obj.marker.setVisible(true);

                    // Set hidden fields
                    const lat = place.geometry.location.lat();
                    const lng = place.geometry.location.lng();
                    document.getElementById(obj.autocomplete.key + "-latitude").value = lat;
                    document.getElementById(obj.autocomplete.key + "-longitude").value = lng;

                    // Trigger Livewire update
                    document.getElementById(obj.autocomplete.key + "-latitude").dispatchEvent(new Event('input'));
                    document.getElementById(obj.autocomplete.key + "-longitude").dispatchEvent(new Event('input'));
                @this.set('address', place.formatted_address)
                    ;

                });
            });
        }

        // Initialize maps when modal opens
        document.getElementById('worklocationModal').addEventListener('shown.bs.modal', function () {
            if (typeof google !== 'undefined' && google.maps) {
                initializeMapAutocomplete();
            }
        });

        window.addEventListener('show-worklocation-modal', () => {
            new bootstrap.Modal(document.getElementById('worklocationModal')).show();
        });

        window.addEventListener('hide-worklocation-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('worklocationModal'))?.hide();
        });

    </script>
@endpush

