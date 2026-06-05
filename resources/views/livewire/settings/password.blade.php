<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';


    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {

        $validated = $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed'],
        ]);

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
            'password_changed' => true
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');

        LivewireAlert::title('Awesome!')
            ->text('Password updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

    }


}; ?>

@push('styles')
    <style>
        .password-toggle-btn {
            border-color: rgb(204, 202, 255) !important;
            background-color: transparent;
            color: rgb(204, 202, 255);
            transition: all 0.3s ease;
        }

        .password-toggle-btn:hover {
            background-color: rgb(204, 202, 255);
            color: white;
        }
    </style>
@endpush

<section class="w-full">

    <form wire:submit.prevent="updatePassword" class="my-4">

        <!-- Current Password -->
        <div class="mb-3" x-data="{ show: false }">
            <label for="current_password" class="form-label">{{ __('Current password') }}</label>
            <div class="input-group">
                <input :type="show ? 'text' : 'password'" id="current_password"
                       wire:model="current_password" class="form-control" required autocomplete="current-password">
                <button type="button" class="btn password-toggle-btn" @click="show = !show" tabindex="-1">
                    <i :class="show ? 'ti ti-eye-off fs-5' : 'ti ti-eye fs-5'"></i>
                </button>
            </div>
            @error('current_password')
            <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <!-- New Password -->
        <div class="mb-3" x-data="{ show: false }">
            <label for="password" class="form-label">
                {{ __('New password') }}
            </label>
            <div class="input-group">
                <input :type="show ? 'text' : 'password'" id="password"
                       wire:model="password" class="form-control" required autocomplete="new-password">
                <button type="button" class="btn password-toggle-btn" @click="show = !show" tabindex="-1">
                    <i :class="show ? 'ti ti-eye-off fs-5' : 'ti ti-eye fs-5'"></i>
                </button>
            </div>
            @error('password')
            <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <!-- Confirm Password -->
        <div class="mb-3" x-data="{ show: false }">
            <label for="password_confirmation" class="form-label">{{ __('Confirm Password') }}</label>
            <div class="input-group">
                <input :type="show ? 'text' : 'password'" id="password_confirmation"
                       wire:model="password_confirmation" class="form-control" required autocomplete="new-password">
                <button type="button" class="btn password-toggle-btn" @click="show = !show" tabindex="-1">
                    <i :class="show ? 'ti ti-eye-off fs-5' : 'ti ti-eye fs-5'"></i>
                </button>
            </div>
            @error('password_confirmation')
            <div class="text-danger small">{{ $message }}</div> @enderror
        </div>

        <!-- Submit -->
        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>

</section>


