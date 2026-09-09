<?php

use App\Services\SuperAdminPasswordResetService;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    #[Locked]
    public bool $tokenIsValid = false;

    public string $new_password = '';
    public string $confirm_password = '';

    public function mount(SuperAdminPasswordResetService $service): void
    {
        $this->token = (string) request()->query('token', '');

        $reset = $this->token !== '' ? $service->findActiveReset($this->token) : null;

        if ($reset) {
            $this->tokenIsValid = true;
            $this->email = $reset->email;
        }
    }

    public function resetPassword(SuperAdminPasswordResetService $service): void
    {
        $this->validate([
            'new_password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'confirm_password' => ['required', 'string', 'same:new_password'],
        ], [
            'confirm_password.same' => __('The confirm password does not match the new password.'),
        ]);

        if (!$service->resetPassword($this->token, $this->new_password)) {
            $this->tokenIsValid = false;

            return;
        }

        Session::flash('status', __('Your password has been reset. You can now sign in.'));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    @if (! $tokenIsValid)
        <x-auth-header :title="__('Link no longer valid')" :description="__('This password reset link is invalid, has expired, or has already been used.')" />

        <div class="text-center text-sm">
            <flux:link :href="route('login')" wire:navigate>{{ __('Back to sign in') }}</flux:link>
        </div>
    @else
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form wire:submit="resetPassword" class="flex flex-col gap-6">
            <flux:input
                :label="__('Email')"
                type="email"
                value="{{ $email }}"
                readonly
                disabled
            />

            <flux:input
                wire:model="new_password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('New password')"
                viewable
            />

            <flux:input
                wire:model="confirm_password"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
