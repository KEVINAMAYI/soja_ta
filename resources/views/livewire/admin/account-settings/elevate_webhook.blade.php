<?php

use App\Models\ElevateHrWebhook;
use App\Models\OrganizationSetting;
use App\Services\ElevateHrWebhookService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public string $generatedUrl = '';

    #[Locked]
    public string $generatedHash = '';

    public function mount(): void
    {
        $this->organizationId();
    }

    protected function organizationId(): int
    {
        Gate::authorize('manage-elevate-callback');
        $organizationId = auth()->user()?->employee?->organization_id;
        abort_unless($organizationId, 403, 'No organization found for this user.');

        return $organizationId;
    }

    public function generateWebhook(): void
    {
        $organizationId = $this->organizationId();
        $this->generatedUrl = app(ElevateHrWebhookService::class)->generate($organizationId, auth()->user());
        parse_str(parse_url($this->generatedUrl, PHP_URL_QUERY), $query);
        $this->generatedHash = hash('sha256', $query['token']);
    }

    public function with(): array
    {
        $organizationId = $this->organizationId();
        $activeHash = OrganizationSetting::where('organization_id', $organizationId)
            ->where('key', 'elevatehr_leave_webhook_token_hash')->value('value');

        return [
            'hasActiveWebhook' => filled($activeHash),
            'canCopyUrl' => $this->generatedUrl !== '' && $this->generatedHash === $activeHash,
            'history' => ElevateHrWebhook::where('organization_id', $organizationId)
                ->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    <h5 class="mb-3">ElevateHR Leave Webhook</h5>
    <p class="text-muted">
        Only one webhook URL is active for this organisation. Replacing it permanently revokes the
        previous URL; revoked URLs cannot be reused.
    </p>

    <div class="alert {{ $hasActiveWebhook ? 'alert-success' : 'alert-info' }}">
        {{ $hasActiveWebhook ? 'An active webhook is configured.' : 'No webhook has been generated yet.' }}
        Copy a newly generated URL into ElevateHR and keep it secret.
        For security, URLs are shown only immediately after generation and are not saved in history.
    </div>

    <button type="button" class="btn btn-primary mb-3"
            wire:click="generateWebhook"
            wire:confirm="Generate a new webhook URL? Any existing URL will immediately stop working. Update ElevateHR with the new URL."
            wire:loading.attr="disabled" wire:target="generateWebhook">
        <span wire:loading.remove wire:target="generateWebhook">
            {{ $hasActiveWebhook ? 'Replace webhook' : 'Generate webhook' }}
        </span>
        <span wire:loading wire:target="generateWebhook">Generating...</span>
    </button>

    @if ($canCopyUrl)
        <div class="mb-4" x-data="{ copied: false, copyError: false }" wire:key="webhook-{{ $generatedHash }}">
            <label for="elevate-webhook-url" class="form-label">New webhook URL</label>
            <div class="input-group">
                <input id="elevate-webhook-url" type="text" class="form-control" readonly
                       value="{{ $generatedUrl }}" x-ref="webhookUrl" autocomplete="off"
                       @click="$el.select()">
                <button type="button" class="btn btn-outline-primary"
                        @click="copied = false; copyError = false;
                            try {
                                await navigator.clipboard.writeText($refs.webhookUrl.value);
                                copied = true;
                            } catch (error) {
                                copyError = true;
                                $refs.webhookUrl.select();
                            }">
                    <span x-text="copied ? 'Copied!' : 'Copy URL'">Copy URL</span>
                </button>
            </div>
            <p class="text-success mt-2" x-show="copied" x-cloak role="status">Webhook URL copied.</p>
            <p class="text-danger mt-2" x-show="copyError" x-cloak role="alert">
                Clipboard access failed. The URL is selected; copy it manually.
            </p>
        </div>
    @elseif ($generatedUrl !== '')
        <div class="alert alert-warning">This URL has been replaced and can no longer be used.</div>
    @endif

    <h6 class="mb-3">Generation history (10 most recent)</h6>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Created by</th>
                    <th scope="col">Generated at</th>
                    <th scope="col">Status</th>
                    <th scope="col">Replaced / revoked at</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($history as $webhook)
                    <tr wire:key="webhook-history-{{ $webhook->id }}">
                        <td>#{{ $webhook->id }}</td>
                        <td>{{ $webhook->creator_name }}</td>
                        <td>{{ $webhook->created_at?->format('d M Y H:i:s T') ?? 'Unknown (legacy setup)' }}</td>
                        <td>
                            <span class="badge {{ $webhook->revoked_at ? 'bg-secondary' : 'bg-success' }}">
                                {{ $webhook->revoked_at ? 'Revoked' : 'Active' }}
                            </span>
                        </td>
                        <td>{{ $webhook->revoked_at?->format('d M Y H:i:s T') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center">No webhooks generated yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
