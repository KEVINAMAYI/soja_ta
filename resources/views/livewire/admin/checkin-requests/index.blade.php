<?php

use App\Models\CheckInApprovalRequest;
use App\Services\CheckInApprovalService;
use Illuminate\Support\Facades\Route;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $filter = 'all'; // all | pending | approved | rejected

    public string $search = '';

    public array $breadcrumbItems = [];

    public function mount(): void
    {
        $this->breadcrumbItems = [
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>'],
            ['label' => 'Check-in Requests', 'icon' => '<iconify-icon icon="mdi:clock-alert-outline" class="fs-5"></iconify-icon>'],
        ];
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function getCountsProperty(): array
    {
        $orgId = auth()->user()->employee?->organization_id;

        $base = CheckInApprovalRequest::where('organization_id', $orgId);

        return [
            'total' => $base->clone()->count(),
            'pending' => $base->clone()->where('status', 'pending')->count(),
            'approved' => $base->clone()->where('status', 'approved')->count(),
            'rejected' => $base->clone()->where('status', 'rejected')->count(),
        ];
    }

    public function getRequestsProperty()
    {
        $orgId = auth()->user()->employee?->organization_id;

        $query = CheckInApprovalRequest::with(['employee', 'activeWindowLog'])
            ->where('organization_id', $orgId);

        if ($this->filter !== 'all') {
            $query->where('status', $this->filter);
        }

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('id_number', 'like', "%{$this->search}%");
            });
        }

        return $query->orderByDesc('submitted_at')->paginate(10);
    }

    public function approve(int $requestId): void
    {
        $this->actOn($requestId, 'approved');
    }

    public function reject(int $requestId): void
    {
        $this->actOn($requestId, 'rejected');
    }

    private function actOn(int $requestId, string $decision): void
    {
        $orgId = auth()->user()->employee?->organization_id;

        $request = CheckInApprovalRequest::where('organization_id', $orgId)
            ->where('id', $requestId)
            ->first();

        if (!$request) {
            LivewireAlert::title('Error!')->text('Request not found.')->error()->toast()->position('top-end')->show();
            return;
        }

        if (!$request->isPending()) {
            LivewireAlert::title('Already resolved')->text('This request has already been actioned.')->warning()->toast()->position('top-end')->show();
            return;
        }

        app(CheckInApprovalService::class)->resolve($request, $decision, auth()->id());

        LivewireAlert::title($decision === 'approved' ? 'Approved' : 'Rejected')
            ->text('The check-in request has been ' . $decision . '.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

}; ?>

<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb title="Check-in Requests" :items="$breadcrumbItems"/>

        {{-- Summary cards --}}
        <div class="row g-3 mb-4">
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card"
                     style="background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:1.2rem 1.4rem;">
                    <p class="text-uppercase small fw-semibold text-muted mb-1"
                       style="font-size:.72rem;letter-spacing:.6px;">Total Requests</p>
                    <div class="fw-bold" style="font-size:2rem;color:#1e293b;">{{ $this->counts['total'] }}</div>
                    <p class="small text-muted mb-0">This week</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card"
                     style="background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:1.2rem 1.4rem;">
                    <p class="text-uppercase small fw-semibold text-muted mb-1"
                       style="font-size:.72rem;letter-spacing:.6px;">Pending</p>
                    <div class="fw-bold" style="font-size:2rem;color:#d97706;">{{ $this->counts['pending'] }}</div>
                    <p class="small text-muted mb-0">Awaiting action</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card"
                     style="background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:1.2rem 1.4rem;">
                    <p class="text-uppercase small fw-semibold text-muted mb-1"
                       style="font-size:.72rem;letter-spacing:.6px;">Approved</p>
                    <div class="fw-bold" style="font-size:2rem;color:#16a34a;">{{ $this->counts['approved'] }}</div>
                    <p class="small text-muted mb-0">This week</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="summary-card"
                     style="background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:1.2rem 1.4rem;">
                    <p class="text-uppercase small fw-semibold text-muted mb-1"
                       style="font-size:.72rem;letter-spacing:.6px;">Rejected</p>
                    <div class="fw-bold" style="font-size:2rem;color:#dc2626;">{{ $this->counts['rejected'] }}</div>
                    <p class="small text-muted mb-0">This week</p>
                </div>
            </div>
        </div>

        {{-- Table card --}}
        <div class="card card-body">

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div class="flex-grow-1" style="max-width:360px;">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><iconify-icon icon="mdi:magnify"></iconify-icon></span>
                        <input type="text" class="form-control" placeholder="Search by name or employee ID..."
                               wire:model.live.debounce.400ms="search">
                    </div>
                </div>

                <div class="d-flex gap-2 align-items-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button"
                                class="btn {{ $filter === 'all' ? 'btn-danger' : 'btn-outline-secondary' }}"
                                wire:click="setFilter('all')">All
                        </button>
                        <button type="button"
                                class="btn {{ $filter === 'pending' ? 'btn-danger' : 'btn-outline-secondary' }}"
                                wire:click="setFilter('pending')">Pending
                        </button>
                        <button type="button"
                                class="btn {{ $filter === 'approved' ? 'btn-danger' : 'btn-outline-secondary' }}"
                                wire:click="setFilter('approved')">Approved
                        </button>
                        <button type="button"
                                class="btn {{ $filter === 'rejected' ? 'btn-danger' : 'btn-outline-secondary' }}"
                                wire:click="setFilter('rejected')">Rejected
                        </button>
                    </div>

                    <button class="btn btn-sm d-flex align-items-center gap-2" style="background:#072639;color:#fff;">
                        <iconify-icon icon="mdi:download"></iconify-icon>
                        Export
                    </button>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0 fw-bold" style="color:#1e293b;">Check-in Approval Requests</h6>
                <span class="text-muted small">{{ $this->requests->total() }} results</span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                    <tr class="text-muted small text-uppercase">
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Check-in Time</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($this->requests as $req)
                        @php
                            $emp = $req->employee;
                            $initials = collect(explode(' ', $emp->name ?? ''))->map(fn($p) => mb_substr($p, 0, 1))->take(2)->implode('');
                        @endphp
                        <tr wire:key="checkin-req-{{ $req->id }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div
                                        class="d-flex align-items-center justify-content-center rounded-circle fw-semibold text-danger"
                                        style="width:34px;height:34px;background:#fee2e2;font-size:.75rem;">
                                        {{ $initials ?: '?' }}
                                    </div>
                                    <div>
                                        <div class="fw-semibold"
                                             style="font-size:.85rem;color:#1e293b;">{{ $emp->name ?? '—' }}</div>
                                        <div class="text-muted"
                                             style="font-size:.72rem;">{{ $emp->employee_number ?? $emp->id_number ?? ('EMP'.str_pad((string)$emp->id, 3, '0', STR_PAD_LEFT)) }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="small">{{ $req->date->format('d M Y') }}</td>
                            <td class="small">
                                {{ $req->check_in_time->format('h:i A') }}
                                <div class="text-muted" style="font-size:.7rem;">{{ $req->minutes_late }}m late</div>
                            </td>
                            <td class="small">{{ $req->submitted_at->format('d M Y · h:i A') }}</td>
                            <td>
                                @if($req->status === 'pending')
                                    <span class="badge rounded-pill"
                                          style="background:#fef9c3;color:#92400e;font-weight:500;">
                                            <iconify-icon icon="mdi:timer-sand"></iconify-icon> Pending
                                            <span class="text-muted">(W{{ $req->current_window }})</span>
                                        </span>
                                @elseif($req->status === 'approved')
                                    <span class="badge rounded-pill"
                                          style="background:#dcfce7;color:#15803d;font-weight:500;">
                                            <iconify-icon icon="mdi:check-circle-outline"></iconify-icon> Approved
                                        </span>
                                @else
                                    <span class="badge rounded-pill"
                                          style="background:#fee2e2;color:#b91c1c;font-weight:500;">
                                            <iconify-icon icon="mdi:close-circle-outline"></iconify-icon> Rejected
                                        </span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($req->status === 'pending')
                                    <div class="d-flex gap-2 justify-content-end">
                                        <button wire:click="approve({{ $req->id }})"
                                                wire:confirm="Approve this check-in request?"
                                                class="btn btn-sm btn-outline-success">
                                            <iconify-icon icon="mdi:check"></iconify-icon>
                                            Approve
                                        </button>
                                        <button wire:click="reject({{ $req->id }})"
                                                wire:confirm="Reject this check-in request?"
                                                class="btn btn-sm btn-outline-danger">
                                            <iconify-icon icon="mdi:close"></iconify-icon>
                                            Reject
                                        </button>
                                    </div>
                                @else
                                    <span class="text-muted small">
                                            {{ ucfirst($req->status) }}
                                        @if($req->resolvedBy)
                                            by {{ $req->resolvedBy->name }}
                                        @endif
                                        </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No check-in requests found.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <span class="text-muted small">Showing {{ $this->requests->count() }} of {{ $this->requests->total() }} requests</span>
                {{ $this->requests->links() }}
            </div>

        </div>

    </div>
</div>
