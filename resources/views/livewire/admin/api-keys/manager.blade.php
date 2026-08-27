<?php

use App\Models\ApiKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Volt\Component;

new class extends Component {

    public $apiKeys = [];
    public string $name = '';
    public string $environment = 'sandbox';
    public ?string $revealedKey = null;

    public function mount(): void
    {
        $this->loadKeys();
    }

    protected function organization()
    {
        return Auth::user()->employee?->organization;
    }

    public function loadKeys(): void
    {
        $organization = $this->organization();

        $this->apiKeys = $organization
            ? $organization->apiKeys()->latest()->get()
            : collect();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'environment' => 'required|in:sandbox,production',
        ];
    }

    public function create(): void
    {
        if (!Auth::user()->can('manage-api-keys')) {
            abort(403);
        }

        $this->validate();

        $organization = $this->organization();

        if (!$organization) {
            LivewireAlert::text('No organization found for your account.')->error()->toast()->position('top-end')->show();
            return;
        }

        try {
            $result = ApiKey::generateFor($organization, $this->environment, $this->name, Auth::id());

            $this->revealedKey = $result['plainTextKey'];
            $this->reset(['name', 'environment']);
            $this->environment = 'sandbox';
            $this->loadKeys();
            $this->dispatch('show-reveal-key-modal');

            LivewireAlert::text('API key generated successfully.')->success()->toast()->position('top-end')->show();
        } catch (\Throwable $e) {
            Log::error('Failed to generate API key: ' . $e->getMessage());
            LivewireAlert::text('Failed to generate API key.')->error()->toast()->position('top-end')->show();
        }
    }

    public function revoke(int $id): void
    {
        if (!Auth::user()->can('manage-api-keys')) {
            abort(403);
        }

        $organization = $this->organization();

        $apiKey = $organization?->apiKeys()->whereKey($id)->first();

        if (!$apiKey) {
            LivewireAlert::text('API key not found.')->error()->toast()->position('top-end')->show();
            return;
        }

        $apiKey->update([
            'revoked_at' => now(),
            'revoked_by' => Auth::id(),
        ]);

        $this->loadKeys();

        LivewireAlert::text('API key revoked.')->success()->toast()->position('top-end')->show();
    }

}; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>API Keys</span>
        @can('manage-api-keys')
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createApiKeyModal">
                <iconify-icon icon="mdi:plus" class="me-1"></iconify-icon>New API Key
            </button>
        @endcan
    </div>

    <div class="card-body">
        @if(empty($apiKeys) || (is_countable($apiKeys) && count($apiKeys) === 0))
            <p class="text-muted mb-0">No API keys have been generated yet.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Environment</th>
                            <th>Key</th>
                            <th>Last Used</th>
                            <th>Status</th>
                            @can('manage-api-keys')
                                <th class="text-end">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($apiKeys as $apiKey)
                            <tr>
                                <td>{{ $apiKey->name }}</td>
                                <td>
                                    <span class="badge {{ $apiKey->environment === 'production' ? 'bg-danger' : 'bg-secondary' }}">
                                        {{ ucfirst($apiKey->environment) }}
                                    </span>
                                </td>
                                <td><code>{{ $apiKey->masked_key }}</code></td>
                                <td>{{ $apiKey->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                <td>
                                    @if($apiKey->isRevoked())
                                        <span class="badge bg-secondary">Revoked</span>
                                    @else
                                        <span class="badge bg-success">Active</span>
                                    @endif
                                </td>
                                @can('manage-api-keys')
                                    <td class="text-end">
                                        @if(!$apiKey->isRevoked())
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="revoke({{ $apiKey->id }})"
                                                    wire:confirm="Revoke API key '{{ $apiKey->name }}'? Requests using it will stop working immediately.">
                                                Revoke
                                            </button>
                                        @endif
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Create API key modal --}}
    <div class="modal fade" id="createApiKeyModal" tabindex="-1" wire:ignore.self>
        <div class="modal-dialog">
            <div class="modal-content">
                <form wire:submit.prevent="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Generate New API Key</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   wire:model="name" placeholder="e.g. Mobile App">
                            @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Environment</label>
                            <select class="form-select @error('environment') is-invalid @enderror" wire:model="environment">
                                <option value="sandbox">Sandbox</option>
                                <option value="production">Production</option>
                            </select>
                            @error('environment') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Reveal generated key modal (shown once) --}}
    <div class="modal fade" id="revealApiKeyModal" tabindex="-1" wire:ignore.self
         x-data
         x-on:show-reveal-key-modal.window="
            bootstrap.Modal.getOrCreateInstance(document.getElementById('createApiKeyModal')).hide();
            bootstrap.Modal.getOrCreateInstance($el).show();
         ">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">API Key Generated</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Copy this key now. For security, you won't be able to see it again.</p>
                    <div class="input-group">
                        <input type="text" class="form-control" readonly value="{{ $revealedKey }}" id="revealedApiKeyInput">
                        <button class="btn btn-outline-secondary" type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('revealedApiKeyInput').value)">
                            <iconify-icon icon="mdi:content-copy"></iconify-icon>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>
</div>
