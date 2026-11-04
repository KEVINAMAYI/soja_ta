<?php

use App\Models\ElevateHrWebhook;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Services\ElevateHrWebhookMonitor;
use App\Services\ElevateHrWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public string $generatedUrl = '';

    #[Locked]
    public string $generatedHash = '';

    public $failureThreshold = 10;

    public string $notificationEmails = '';

    public string $alertFrequency = 'once_daily';

    public bool $alertSettingsSaved = false;

    public function mount(): void
    {
        $settings = app(ElevateHrWebhookMonitor::class)->settings((string) $this->organizationId());
        $this->failureThreshold = $settings['threshold'];
        $this->notificationEmails = implode(', ', $settings['emails']);
        $this->alertFrequency = $settings['frequency'];
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

    public function saveAlertSettings(): void
    {
        $organizationId = $this->organizationId();
        $this->alertSettingsSaved = false;
        $this->validate([
            'failureThreshold' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notificationEmails' => ['nullable', 'string', 'max:5000'],
            'alertFrequency' => ['required', Rule::in(['once_daily', 'every_threshold'])],
        ]);
        $emails = array_values(array_unique(array_map('strtolower',
            preg_split('/[\s,;]+/', trim($this->notificationEmails), -1, PREG_SPLIT_NO_EMPTY)
        )));
        Validator::make(['notificationEmails' => $emails], [
            'notificationEmails' => ['array', 'max:20'],
            'notificationEmails.*' => ['required', 'email:rfc', 'max:254'],
        ])->validate();

        Organization::findOrFail($organizationId)->setSetting(ElevateHrWebhookMonitor::SETTINGS_KEY, json_encode([
            'threshold' => (int) $this->failureThreshold,
            'emails' => $emails,
            'frequency' => $this->alertFrequency,
        ], JSON_THROW_ON_ERROR), 'json');
        $this->notificationEmails = implode(', ', $emails);
        $this->alertSettingsSaved = true;
    }

    public function with(): array
    {
        $organizationId = $this->organizationId();
        $activeHash = OrganizationSetting::where('organization_id', $organizationId)
            ->where('key', 'elevatehr_leave_webhook_token_hash')->value('value');
        $stats = DB::table('elevate_webhook_daily_stats')->where('organization_id', (string) $organizationId);

        return [
            'hasActiveWebhook' => filled($activeHash),
            'canCopyUrl' => $this->generatedUrl !== '' && $this->generatedHash === $activeHash,
            'history' => ElevateHrWebhook::where('organization_id', $organizationId)
                ->latest('id')->limit(10)->get(),
            'overallStats' => (clone $stats)->selectRaw('COALESCE(SUM(received), 0) as received, COALESCE(SUM(failed), 0) as failed')->first(),
            'todayStats' => (clone $stats)->where('date', now()->toDateString())->first(),
            'dailyStats' => (clone $stats)->orderByDesc('date')->limit(30)->get(),
            'recentFailures' => DB::table('failed_elevate_webhooks')
                ->where('organization_id', (string) $organizationId)->latest('id')->limit(5)->get(),
        ];
    }
}; ?>

<div>
    <h5 class="mb-3">ElevateHR Leave Webhook</h5>
    <div wire:poll.30s>
        <h6>Delivery statistics</h6>
        <p class="text-muted">Counts include rejected requests, rate limits and retries. Overall totals cover deliveries since monitoring was enabled. Dates use {{ config('app.timezone') }}.</p>
        <div class="row mb-3">
            <div class="col-md-6"><strong>Today:</strong> {{ $todayStats->received ?? 0 }} received / {{ $todayStats->failed ?? 0 }} failed</div>
            <div class="col-md-6"><strong>Overall:</strong> {{ $overallStats->received }} received / {{ $overallStats->failed }} failed</div>
        </div>
        <details class="mb-3">
            <summary>Daily totals (30 most recent days with deliveries)</summary>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Received</th><th>Failed</th></tr></thead>
                    <tbody>
                        @forelse ($dailyStats as $day)
                            <tr><td>{{ $day->date }}</td><td>{{ $day->received }}</td><td>{{ $day->failed }}</td></tr>
                        @empty
                            <tr><td colspan="3">No deliveries recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>
        <h6>Five most recent failure causes</h6>
        <div class="table-responsive mb-4">
            <table class="table">
                <thead><tr><th>Time</th><th>Source IP</th><th>HTTP status</th><th>Cause</th></tr></thead>
                <tbody>
                    @forelse ($recentFailures as $failure)
                        <tr wire:key="webhook-failure-{{ $failure->id }}">
                            <td>{{ $failure->created_at }}</td>
                            <td>{{ $failure->source_ip ?? '-' }}</td>
                            <td>{{ $failure->status_code }}</td>
                            <td>
                                {{ $failure->reason }}
                                @if ($failure->validation_errors)
                                    <ul class="mb-0">
                                        @foreach (json_decode($failure->validation_errors, true, 512, JSON_THROW_ON_ERROR) as $field => $messages)
                                            @foreach ($messages as $message)
                                                <li>{{ $field }}: {{ $message }}</li>
                                            @endforeach
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">No webhook failures recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form wire:submit="saveAlertSettings" class="mb-4">
        <h6>Failure email alerts</h6>
        <p class="text-muted">Choose who receives alerts when the daily failure count reaches the threshold. Leave recipients empty to disable email alerts. Saving does not rotate the webhook URL.</p>
        <div class="mb-3">
            <label for="webhook-failure-threshold" class="form-label">Daily failure threshold</label>
            <input id="webhook-failure-threshold" type="number" min="1" max="1000000" wire:model="failureThreshold" class="form-control">
            @error('failureThreshold') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label for="webhook-alert-emails" class="form-label">Notification emails (up to 20, separated by commas or new lines)</label>
            <textarea id="webhook-alert-emails" wire:model="notificationEmails" class="form-control" rows="2"></textarea>
            @error('notificationEmails') <div class="text-danger">{{ $message }}</div> @enderror
            @error('notificationEmails.*') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label for="webhook-alert-frequency" class="form-label">Alert frequency</label>
            <select id="webhook-alert-frequency" wire:model="alertFrequency" class="form-select">
                <option value="once_daily">Once per day when the threshold is reached</option>
                <option value="every_threshold">Repeat at each threshold multiple within the day</option>
            </select>
            @error('alertFrequency') <div class="text-danger">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveAlertSettings">Save alert settings</button>
        @if ($alertSettingsSaved)
            <span class="text-success ms-2" role="status">Alert settings saved.</span>
        @endif
    </form>

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
