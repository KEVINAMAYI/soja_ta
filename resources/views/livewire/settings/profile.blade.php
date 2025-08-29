<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public string $activeTab = 'profile';
    public string $tabTitle = ''; // ✅ Prevents null assignment error
    public string $tabIcon = '';  // ✅ Prevents null assignment error
    public array $breadcrumbItems = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;

        $this->changeBreadcrumb();

    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);

        LivewireAlert::title('Awesome!')
            ->text('Profile updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        LivewireAlert::title('Awesome!')
            ->text('Verification Link sent successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

    }


    #[On('tabChanged')]
    public function tabChanged($tabId)
    {

        $this->activeTab = $tabId;
        $this->changeBreadcrumb();

    }

    public function changeBreadcrumb()
    {
        switch ($this->activeTab) {

            case 'profile':
                $this->tabTitle = 'Profile';
                $this->tabIcon = '<iconify-icon icon="mdi:account-circle-outline" class="fs-5"></iconify-icon>';
                break;

            case 'password-reset':
                $this->tabTitle = 'Password Reset';
                $this->tabIcon = '<iconify-icon icon="mdi:lock-reset" class="fs-5"></iconify-icon>';
                break;

            case 'delete-user':
                $this->tabTitle = 'Deactivate Account';
                $this->tabIcon = '<iconify-icon icon="mdi:delete-outline" class="fs-5"></iconify-icon>';
                break;

            default:
                $this->tabTitle = 'Profile';
                $this->tabIcon = '<iconify-icon icon="mdi:account-circle-outline" class="fs-5"></iconify-icon>';
                break;
        }


        $this->breadcrumbItems = [
            [
                'label' => 'Dashboard',
                'url' => route('dashboard'),
                'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
            ],
            [
                'label' => 'System Settings',
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
        /* Make nav-pills tabs flat (no rounded corners) */
        .nav-pills .nav-link {
            border-radius: 0 !important;
        }

        /* Optionally, remove border from active tab */
        .nav-pills .nav-link.active {
            border-radius: 0 !important;
        }

    </style>
@endpush


<div class="container-fluid">

    <livewire:admin.system-settings.bread-crumb
        :title="$tabTitle"
        :items="$breadcrumbItems"
    />

    <div class="card">
        <ul class="nav nav-pills user-profile-tab" id="pills-tab" role="tablist">

            <!-- Profile Tab -->
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link {{ $activeTab === 'profile' ? 'active' : '' }}"
                    id="tab-profile-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-profile"
                    type="button" role="tab"
                    aria-controls="tab-profile"
                    aria-selected="{{ $activeTab === 'profile' ? 'true' : 'false' }}">
                    <i class="ti ti-user-circle me-2 fs-6"></i>
                    <span>Profile</span>
                </button>
            </li>

            <!-- Password Reset Tab -->
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link {{ $activeTab === 'password-reset' ? 'active' : '' }}"
                    id="tab-password-reset-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-password-reset"
                    type="button" role="tab"
                    aria-controls="tab-password-reset"
                    aria-selected="{{ $activeTab === 'password-reset' ? 'true' : 'false' }}">
                    <i class="ti ti-lock me-2 fs-6"></i>
                    <span>Password Reset</span>
                </button>
            </li>

            <!-- Delete User Tab -->
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link {{ $activeTab === 'delete-user' ? 'active' : '' }}"
                    id="tab-delete-user-tab"
                    data-bs-toggle="pill"
                    data-bs-target="#tab-delete-user"
                    type="button" role="tab"
                    aria-controls="tab-delete-user"
                    aria-selected="{{ $activeTab === 'delete-user' ? 'true' : 'false' }}">
                    <i class="ti ti-user-minus me-2 fs-6"></i>
                    <span>Deactivate Account</span>
                </button>
            </li>

        </ul>

        <div class="card-body">
            <div class="tab-content" id="pills-tabContent">

                <!-- Profile Tab -->
                <div class="tab-pane fade {{ $activeTab === 'profile' ? 'show active' : '' }}" id="tab-profile">
                    <form wire:submit.prevent="updateProfileInformation" class="my-4">

                        <!-- Name -->
                        <div class="mb-3">
                            <label for="name" class="form-label">{{ __('Name') }}</label>
                            <input type="text" id="name" wire:model="name" class="form-control" required autofocus
                                   autocomplete="name">
                            @error('name')
                            <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>

                        <!-- Email -->
                        <div class="mb-3">
                            <label for="email" class="form-label">{{ __('Email') }}</label>
                            <input type="email" id="email" wire:model="email" class="form-control" required
                                   autocomplete="email">
                            @error('email')
                            <div class="text-danger small">{{ $message }}</div> @enderror

                            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !auth()->user()->hasVerifiedEmail())
                                <div class="mt-2 text-warning small">
                                    {{ __('Your email address is unverified.') }}
                                    <a href="#" wire:click.prevent="resendVerificationNotification"
                                       class="text-primary">{{ __('Click here to re-send the verification email.') }}</a>
                                </div>
                                @if (session('status') === 'verification-link-sent')
                                    <div class="mt-2 text-success small">
                                        {{ __('A new verification link has been sent to your email address.') }}
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- Submit -->
                        <div class="d-flex align-items-center gap-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>

                            <x-action-message class="text-success" on="profile-updated">
                                {{ __('Saved.') }}
                            </x-action-message>
                        </div>
                    </form>
                </div>

                <!-- Password Reset Tab -->
                <div class="tab-pane fade {{ $activeTab === 'password-reset' ? 'show active' : '' }}"
                     id="tab-password-reset">
                    <livewire:settings.password/>
                </div>

                <!-- Delete User Tab -->
                <div class="tab-pane fade {{ $activeTab === 'delete-user' ? 'show active' : '' }}" id="tab-delete-user">
                    <livewire:settings.delete-user-form/>
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

                    let mappedTab;
                    switch (tabId) {
                        case 'tab-profile-tab':
                            mappedTab = 'profile';
                            break;
                        case 'tab-password-reset-tab':
                            mappedTab = 'password-reset';
                            break;
                        case 'tab-delete-user-tab':
                            mappedTab = 'delete-user';
                            break;
                        default:
                            mappedTab = 'profile';
                    }

                    Livewire.dispatch('tabChanged', {tabId: mappedTab});
                });
            });
        });
    </script>
@endpush

