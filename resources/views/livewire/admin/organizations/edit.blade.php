<?php

use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use App\Models\Organization;
use Livewire\WithFileUploads;

new class extends Component {

    use WithFileUploads;

    public $editId;
    public $name;
    public $address;
    public $location;
    public $email;
    public $phone_number;
    public $description;
    public $website;
    public $logo_path;
    public $googleMapsApiKey;
    public $newLogo;
    public $latitude;
    public $longitude;
    public $primary_color;
    public $logo_height;
    public $logo_width;
    public $sidebar_bg_color;
    public $page_bg_color;

    public function mount($id)
    {
        $this->editId = $id;

        $this->googleMapsApiKey = env('GOOGLE_MAPS_API_KEY');

        $org = Organization::findOrFail($id);

        $this->getOrgData($org);
    }


    public function rules()
    {
        return [
            'name'          => 'required|string|max:255|unique:organizations,name,' . $this->editId,
            'address'       => 'nullable|string|max:255',
            'location'      => 'nullable|string|max:255',
            'email'         => 'required|email|unique:organizations,email,' . $this->editId,
            'phone_number'  => 'required|string|max:255',
            'description'   => 'nullable|string',
            'website'       => 'nullable|url',
            'newLogo'       => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'primary_color' => 'nullable|string|max:7',
            'logo_height'   => 'nullable|integer|min:20|max:300',
            'logo_width'    => 'nullable|integer|min:20|max:600',
            'sidebar_bg_color' => 'nullable|string|max:7',
            'page_bg_color'    => 'nullable|string|max:7',
        ];
    }


    public function getOrgData($org)
    {
        $this->name          = $org->name;
        $this->address       = $org->address;
        $this->location      = $org->location;
        $this->email         = $org->email;
        $this->phone_number  = $org->phone_number;
        $this->description   = $org->description;
        $this->website       = $org->website;
        $this->logo_path     = $org->logo_path;
        $this->primary_color = $org->primary_color ?? '#072639';
        $this->logo_height   = $org->logo_height   ?? 60;
        $this->logo_width    = $org->logo_width     ?? 200;
        $this->newLogo       = null;
        $this->sidebar_bg_color = $org->sidebar_bg_color;
        $this->page_bg_color    = $org->page_bg_color;
    }


    public function updateOrganization()
    {
        $this->validate();

        DB::beginTransaction();

        try {

            $org = Organization::findOrFail($this->editId);

            if ($this->newLogo) {
                if ($org->logo_path) {
                    Storage::disk('public')->delete($org->logo_path);
                }
                $logo_path = $this->newLogo->store('logos', 'public');
            }

            $org->update([
                'name'          => $this->name,
                'address'       => $this->address,
                'location'      => $this->location,
                'email'         => $this->email,
                'phone_number'  => $this->phone_number,
                'description'   => $this->description,
                'website'       => $this->website,
                'logo_path'     => $logo_path ?? $org->logo_path,
                'primary_color' => $this->primary_color,
                'logo_height'   => $this->logo_height,
                'logo_width'    => $this->logo_width,
                'sidebar_bg_color' => $this->sidebar_bg_color,
                'page_bg_color'    => $this->page_bg_color,
            ]);

            // --- Geofence Logic ---
            if ($this->location) {
                WorkLocation::updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'name'            => 'main_branch',
                    ],
                    [
                        'type'        => 'branch',
                        'address'     => $this->location,
                        'latitude'    => $this->latitude,
                        'longitude'   => $this->longitude,
                        'radius_m'    => 100,
                        'description' => 'Main Branch',
                        'active'      => 1,
                        'is_default'  => 1,
                    ]
                );
            }

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Organization updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $org = Organization::findOrFail($this->editId);
            $this->getOrgData($org);
            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {

            DB::rollBack();

            \Log::error('Organization update failed', [
                'error' => $e->getMessage(),
            ]);

            LivewireAlert::title('Error!')
                ->text('Failed to update organization. Please try again.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


    public function removeNewLogo()
    {
        $this->newLogo = null;
    }


    #[On('locationUpdated')]
    public function updateLocationFields($location, $latitude, $longitude)
    {
        $this->location  = $location;
        $this->latitude  = $latitude;
        $this->longitude = $longitude;
    }

};
?>

@push('styles')
    <style>
        #locationInput {
            margin-top: 0px !important;
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }
    </style>
@endpush


<div class="row">
    <div class="col-12">
        <div class="card shadow-sm p-4">
            <form wire:submit.prevent="updateOrganization">

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" wire:model.defer="name" class="form-control">
                        @error('name') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Address <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" wire:model.defer="address" class="form-control">
                        @error('address') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">
                        Location <span class="text-danger">*</span>
                    </label>
                    <input type="hidden" wire:model="location" id="locationSync">
                    <div class="col-sm-9" x-data="{ location: @entangle('location').defer }">
                        <div x-data="{ location: @entangle('location') }" class="input-group">
                            <input type="text"
                                   id="locationInput"
                                   class="form-control"
                                   placeholder="Search for a location..."
                                   autocomplete="off"
                                   x-model="location"
                                   value="{{ $location }}"
                            >
                            <button type="button" class="btn btn-primary" id="getCurrentLocationBtn">
                                <iconify-icon icon="tabler:current-location"></iconify-icon>
                            </button>
                        </div>
                        @error('location') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Email <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="email" wire:model.defer="email" class="form-control">
                        @error('email') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="tel" wire:model.defer="phone_number" class="form-control">
                        @error('phone_number') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-start">
                    <label class="col-sm-3 col-form-label fw-semibold">Description <span class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <textarea wire:model.defer="description" rows="3" class="form-control"></textarea>
                        @error('description') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Website</label>
                    <div class="col-sm-9">
                        <input type="url" wire:model.defer="website" class="form-control">
                        @error('website') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- Logo upload --}}
                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Logo</label>
                    <div class="col-sm-9">
                        <input type="file" wire:model="newLogo" class="form-control">
                        @error('newLogo') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row">
                    <label class="col-sm-3 col-form-label fw-semibold">Current/New Logo</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        @if ($newLogo)
                            <div class="position-relative">
                                <img src="{{ $newLogo->temporaryUrl() }}"
                                     style="height: 100px; width: 100px; object-fit: cover;">
                                <button type="button" wire:click="removeNewLogo"
                                        class="btn-close position-absolute top-0 start-100 translate-middle"
                                        aria-label="Remove image"></button>
                            </div>
                        @elseif($logo_path)
                            <img src="{{ asset('storage/' . $logo_path) }}"
                                 style="height: 100px; object-fit: contain;">
                        @else
                            <div class="d-flex justify-content-center align-items-center"
                                 style="height: 100px; width: 100px; background-color: {{ $primary_color }}; color: white; font-size: 2rem;">
                                <span>{{ strtoupper(substr($name, 0, 2)) }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Branding --}}
                <hr class="my-4">
                <h6 class="fw-semibold mb-3 text-muted">Branding & Logo Display</h6>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Primary Color</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        <input type="color" wire:model.defer="primary_color" class="form-control form-control-color" style="width: 60px; height: 38px;">
                        <input type="text" wire:model.defer="primary_color" class="form-control" placeholder="#072639" style="max-width: 120px;">
                        @error('primary_color') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Sidebar / Nav Background</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        <input type="color" wire:model.defer="sidebar_bg_color"
                               class="form-control form-control-color" style="width:60px; height:38px;">
                        <input type="text" wire:model.defer="sidebar_bg_color"
                               class="form-control" placeholder="#f7eee8" style="max-width:120px;">
                        @error('sidebar_bg_color') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Page / Body Background</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        <input type="color" wire:model.defer="page_bg_color"
                               class="form-control form-control-color" style="width:60px; height:38px;">
                        <input type="text" wire:model.defer="page_bg_color"
                               class="form-control" placeholder="#fefcfb" style="max-width:120px;">
                        @error('page_bg_color') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Logo Height <span class="text-muted fw-normal">(px)</span></label>
                    <div class="col-sm-9">
                        <input type="number" wire:model.defer="logo_height" class="form-control" style="max-width: 150px;" min="20" max="300">
                        @error('logo_height') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Logo Width <span class="text-muted fw-normal">(px)</span></label>
                    <div class="col-sm-9">
                        <input type="number" wire:model.defer="logo_width" class="form-control" style="max-width: 150px;" min="20" max="600">
                        @error('logo_width') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-edit"></i> Update
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

@push('scripts')
    <script src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const locationInput = document.getElementById('locationInput');
            const syncInput = document.getElementById('locationSync');

            const autocomplete = new google.maps.places.Autocomplete(locationInput, {types: ['geocode']});

            autocomplete.addListener('place_changed', function () {
                const place = autocomplete.getPlace();
                if (place && place.formatted_address) {
                    formattedAddress = place.formatted_address;
                    locationInput.value = formattedAddress;
                    syncInput.value = place.formatted_address;
                    syncInput.dispatchEvent(new Event('input'));

                    const latitude = place.geometry.location.lat();
                    const longitude = place.geometry.location.lng();

                    Livewire.dispatch('locationUpdated', {
                        location: formattedAddress,
                        latitude: latitude,
                        longitude: longitude
                    });
                }
            });

            document.getElementById('getCurrentLocationBtn').addEventListener('click', function () {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function (position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;

                        const geocoder = new google.maps.Geocoder();
                        geocoder.geocode({location: {lat, lng}}, function (results, status) {
                            if (status === 'OK' && results[0]) {
                                const address = results[0].formatted_address;
                                locationInput.value = address;
                                syncInput.value = address;
                                syncInput.dispatchEvent(new Event('input'));
                            } else {
                                alert("Unable to retrieve address.");
                            }
                        });
                    }, function () {
                        alert("Unable to retrieve your location.");
                    });
                } else {
                    alert("Geolocation not supported.");
                }
            });

        });
    </script>
@endpush
