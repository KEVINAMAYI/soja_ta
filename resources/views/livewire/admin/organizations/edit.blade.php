<?php

use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
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

    public function mount($id)
    {
        $this->editId = $id;

        $org = Organization::findOrFail($id);

        $this->getOrgData($org);
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


    public function getOrgData($org)
    {

        $this->name = $org->name;
        $this->address = $org->address;
        $this->location = $org->location;
        $this->email = $org->email;
        $this->phone_number = $org->phone_number;
        $this->description = $org->description;
        $this->website = $org->website;
        $this->logo_path = $org->logo_path;

    }


    public function updateOrganization()
    {
        $this->validate();

        DB::beginTransaction();

        try {

            $org = Organization::findOrFail($this->editId);

            $org->update([
                'name' => $this->name,
                'address' => $this->address,
                'location' => $this->location,
                'email' => $this->email,
                'phone_number' => $this->phone_number,
                'description' => $this->description,
                'website' => $this->website,
            ]);

            DB::commit();

            LivewireAlert::title('Awesome!')
                ->text('Organization updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            // Get updated version
            $org = Organization::findOrFail($this->editId);
            $this->getOrgData($org);


        } catch (\Throwable $e) {

            DB::rollBack();

            // Optional: Log the error for debugging
            \Log::error('Organization update failed', [
                'error' => $e->getMessage(),
                'organization_id' => $this->organizationId
            ]);

            LivewireAlert::title('Error!')
                ->text('Failed to update organization. Please try again.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }


};
?>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm p-4">
            <form wire:submit.prevent="updateOrganization">
                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Company Name <span
                            class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" wire:model.defer="name" class="form-control">
                        @error('name') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Address <span
                            class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" wire:model.defer="address" class="form-control">
                        @error('address') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Location <span
                            class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="text" wire:model.defer="location" class="form-control">
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
                    <label class="col-sm-3 col-form-label fw-semibold">Phone Number <span
                            class="text-danger">*</span></label>
                    <div class="col-sm-9">
                        <input type="tel" wire:model.defer="phone_number" class="form-control">
                        @error('phone_number') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="mb-3 row align-items-start">
                    <label class="col-sm-3 col-form-label fw-semibold">Description <span
                            class="text-danger">*</span></label>
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

                <div class="mb-3 row align-items-center">
                    <label class="col-sm-3 col-form-label fw-semibold">Logo</label>
                    <div class="col-sm-9 d-flex align-items-center gap-3">
                        @if($logo_path)
                            <img src="{{ asset('storage/' . $logo_path) }}" class="rounded-circle"
                                 style="height: 100px; width: 100px;">
                        @else
                            <div class="rounded-circle d-flex justify-content-center align-items-center"
                                 style="height: 100px; width: 100px; background-color: #8E44AD; color: white; font-size: 2rem;">
                                <span>{{ strtoupper(substr($name, 0, 2)) }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('organizations.index') }}" class="btn btn-info">
                        <i class="ti ti-arrow-left"></i> Back
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-edit"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>



