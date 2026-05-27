<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        // Assuming User hasOne Employee
        $employee = $user->employee;

        if ($employee) {
            $employee->status = 'inactive';
            $employee->save();
        }

        // Log out user
        $logout();

        // Redirect
        $this->redirect('/', navigate: true);
    }

}; ?>

<section>

    <div class="mb-4">
        <h3>{{ __('Deactivate account') }}</h3>
        <p class="text-muted">{{ __('Deactivate your account') }}</p>

        <!-- Trigger Button -->
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmUserDeletionModal">
            {{ __('Deactivate account') }}
        </button>
    </div>

    <!-- Modal -->
    <div wire:ignore.self class="modal fade" id="confirmUserDeletionModal" tabindex="-1"
         aria-labelledby="confirmUserDeletionLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form wire:submit.prevent="deleteUser" class="modal-content space-y-3">

                <div class="modal-header">
                    <h5 class="modal-title"
                        id="confirmUserDeletionLabel">{{ __('Are you sure you want to deactivate your account?') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="{{ __('Close') }}"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3 text-muted">
                        {{ __('Once your account is deactivated, you wont login until activated by an Admin. Please enter your password to confirm you would like to deactivate your account.') }}
                    </p>

                    <div class="mb-3">
                        <label for="password" class="form-label">{{ __('Password') }}</label>
                        <input type="password" id="password" wire:model="password" class="form-control" required>
                        @error('password')
                        <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Deactivate account') }}</button>
                </div>
            </form>
        </div>
    </div>

</section>
