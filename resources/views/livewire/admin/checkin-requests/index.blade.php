<?php

use Illuminate\Pagination\Paginator;
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
    public string $filter = 'all';

    public string $search = '';

    public array $breadcrumbItems = [];

    public function boot(): void
    {
        // Use Bootstrap-themed pagination links so they match this page's styling
        Paginator::useBootstrapFive();
    }

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

        $query = CheckInApprovalRequest::with(['employee', 'activeWindowLog', 'resolvedBy'])
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

    public function export()
    {
        $orgId = auth()->user()->employee?->organization_id;

        $query = CheckInApprovalRequest::with(['employee', 'resolvedBy'])
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

        $rows = $query->orderByDesc('submitted_at')->get();

        $filename = 'checkin-requests-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'ID Number', 'Date', 'Check-in Time', 'Minutes Late', 'Submitted At', 'Status', 'Resolved By', 'Resolved At']);

            foreach ($rows as $req) {
                fputcsv($out, [
                    $req->employee->name ?? '',
                    $req->employee->id_number ?? '',
                    $req->date?->format('Y-m-d'),
                    $req->check_in_time?->format('H:i'),
                    $req->minutes_late,
                    $req->submitted_at?->format('Y-m-d H:i'),
                    ucfirst($req->status),
                    $req->resolvedBy->name ?? '',
                    $req->status !== 'pending' ? $req->updated_at?->format('Y-m-d H:i') : '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
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
        $request = CheckInApprovalRequest::where('organization_id', $orgId)->where('id', $requestId)->first();

        if (!$request) {
            LivewireAlert::title('Error!')->text('Request not found.')->error()->toast()->position('top-end')->show();
            return;
        }

        if (!$request->isPending()) {
            LivewireAlert::title('Already resolved')->text('This request has already been actioned.')->warning()->toast()->position('top-end')->show();
            return;
        }

        app(CheckInApprovalService::class)->resolve($request, $decision, auth()->id());

        LivewireAlert::title($decision === 'approved' ? 'Approved ✓' : 'Rejected')
            ->text('The check-in request has been ' . $decision . '.')
            ->success()->toast()->position('top-end')->show();
    }

}; ?>


@push('styles')
    <style>

        .text-muted {
            margin-top: 10px;
            margin-right: 10px;
            --bs-text-opacity: 1;
            color: var(--bs-secondary-color) !important;
        }

        .pagination {
            margin-bottom: 0;
            gap: .25rem;
        }

        .pagination .page-link {
            border: 1px solid #e2e8f0;
            border-radius: 8px !important;
            color: #475569;
            font-size: .82rem;
            padding: .35rem .7rem;
            margin: 0;
        }

        .pagination .page-item.active .page-link {
            background: #072639;
            border-color: #072639;
            color: #fff;
        }

        .pagination .page-item.disabled .page-link {
            color: #cbd5e1;
            background: #fff;
            border-color: #f1f5f9;
        }

        .pagination .page-link:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
    </style>
@endpush


<div class="row">
    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb title="Check-in Requests" :items="$breadcrumbItems"/>

        {{-- ── Summary cards ─────────────────────────────────────────────── --}}
        <div class="row g-3 mb-4">

            @php
                $cards = [
                    ['label' => 'Total',    'value' => $this->counts['total'],    'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => 'mdi:clipboard-list-outline',   'filter' => 'all'],
                    ['label' => 'Pending',  'value' => $this->counts['pending'],  'color' => '#d97706', 'bg' => '#fffbeb', 'icon' => 'mdi:timer-sand',               'filter' => 'pending'],
                    ['label' => 'Approved', 'value' => $this->counts['approved'], 'color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'mdi:check-circle-outline',     'filter' => 'approved'],
                    ['label' => 'Rejected', 'value' => $this->counts['rejected'], 'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'mdi:close-circle-outline',     'filter' => 'rejected'],
                ];
            @endphp

            @foreach($cards as $card)
                <div class="col-lg-3 col-md-6 col-12">
                    <div wire:click="setFilter('{{ $card['filter'] }}')"
                         class="summary-card d-flex align-items-center gap-3"
                         style="background:#fff;border:1.5px solid {{ $filter === $card['filter'] ? $card['color'] : 'rgba(0,0,0,.06)' }};
                                border-radius:14px;padding:1.1rem 1.3rem;cursor:pointer;transition:border-color .2s;">
                        <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                             style="width:44px;height:44px;background:{{ $card['bg'] }};">
                            <iconify-icon icon="{{ $card['icon'] }}"
                                          style="font-size:1.4rem;color:{{ $card['color'] }};"></iconify-icon>
                        </div>
                        <div>
                            <p class="text-uppercase fw-semibold text-muted mb-0"
                               style="font-size:.68rem;letter-spacing:.6px;">{{ $card['label'] }}</p>
                            <div class="fw-bold lh-1 mt-1"
                                 style="font-size:1.8rem;color:#1e293b;">{{ $card['value'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Table card ─────────────────────────────────────────────────── --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">

                {{-- Toolbar --}}
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 px-4 pt-4 pb-3">
                    <div>
                        <h6 class="fw-bold mb-0" style="color:#1e293b;">Check-in Approval Requests</h6>
                        <span class="text-muted"
                              style="font-size:.78rem;">{{ $this->requests->total() }} total results</span>
                    </div>

                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        {{-- Search --}}
                        <div class="input-group input-group-sm" style="width:240px;">
                            <span class="input-group-text bg-white border-end-0">
                                <iconify-icon icon="mdi:magnify" class="text-muted"></iconify-icon>
                            </span>
                            <input type="text" class="form-control border-start-0 ps-0"
                                   placeholder="Search employee..."
                                   wire:model.live.debounce.400ms="search">
                        </div>

                        {{-- Filter pills --}}
                        <div class="d-flex gap-1">
                            @foreach(['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $val => $label)
                                <button type="button"
                                        wire:click="setFilter('{{ $val }}')"
                                        class="btn btn-sm px-3"
                                        style="{{ $filter === $val
                                            ? 'background:#072639;color:#fff;border-color:#072639;'
                                            : 'background:#fff;color:#64748b;border:1px solid #e2e8f0;' }}">
                                    {{ $label }}
                                    @if($val !== 'all')
                                        <span class="ms-1 badge rounded-pill"
                                              style="{{ $filter === $val ? 'background:rgba(255,255,255,.25);color:#fff;' : 'background:#f1f5f9;color:#64748b;' }}">
                                            {{ $this->counts[$val] }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        {{-- Export --}}
                        <button wire:click="export" class="btn btn-sm d-flex align-items-center gap-1"
                                style="background:#072639;color:#fff;border-color:#072639;">
                            <iconify-icon icon="mdi:download-outline"></iconify-icon>
                            Export
                        </button>
                    </div>
                </div>

                {{-- Table --}}
                <div class="table-responsive">
                    <table class="table mb-0" style="font-size:.85rem;">
                        <thead style="background:#f8fafc;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
                        <tr class="text-uppercase" style="font-size:.68rem;letter-spacing:.5px;color:#94a3b8;">
                            <th class="px-4 py-3 fw-semibold">Employee</th>
                            <th class="py-3 fw-semibold">Date</th>
                            <th class="py-3 fw-semibold">Check-in</th>
                            <th class="py-3 fw-semibold">Submitted</th>
                            <th class="py-3 fw-semibold">Window</th>
                            <th class="py-3 fw-semibold">Status</th>
                            <th class="py-3 pe-4 fw-semibold text-end">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($this->requests as $req)
                            @php
                                $emp      = $req->employee;
                                $initials = collect(explode(' ', $emp->name ?? ''))->map(fn($p) => mb_substr($p,0,1))->take(2)->implode('');
                                $avatarColors = [
                                    ['bg'=>'#fee2e2','color'=>'#b91c1c'],
                                    ['bg'=>'#dbeafe','color'=>'#1d4ed8'],
                                    ['bg'=>'#dcfce7','color'=>'#15803d'],
                                    ['bg'=>'#fef9c3','color'=>'#92400e'],
                                    ['bg'=>'#ede9fe','color'=>'#6d28d9'],
                                ];
                                $ac = $avatarColors[$emp->id % count($avatarColors)];
                            @endphp
                            <tr wire:key="req-{{ $req->id }}"
                                style="border-bottom:1px solid #f1f5f9;transition:background .15s;"
                                onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">

                                {{-- Employee --}}
                                <td class="px-4 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <div
                                            class="d-flex align-items-center justify-content-center rounded-circle fw-bold flex-shrink-0"
                                            style="width:34px;height:34px;background:{{ $ac['bg'] }};color:{{ $ac['color'] }};font-size:.72rem;">
                                            {{ $initials ?: '?' }}
                                        </div>
                                        <div>
                                            <div class="fw-semibold"
                                                 style="color:#1e293b;">{{ $emp->name ?? '—' }}</div>
                                            <div style="font-size:.72rem;color:#94a3b8;">
                                                {{ $emp->employee_number ?? $emp->id_number ?? ('EMP'.str_pad((string)$emp->id, 3, '0', STR_PAD_LEFT)) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Date --}}
                                <td class="py-3" style="color:#475569;">{{ $req->date->format('d M Y') }}</td>

                                {{-- Check-in time --}}
                                <td class="py-3">
                                    <span
                                        style="color:#1e293b;font-weight:500;">{{ $req->check_in_time->format('h:i A') }}</span>
                                    <span class="ms-1 badge rounded-pill"
                                          style="background:#fef2f2;color:#b91c1c;font-size:.68rem;">
                                        +{{ $req->minutes_late }}m
                                    </span>
                                </td>

                                {{-- Submitted --}}
                                <td class="py-3" style="color:#64748b;font-size:.8rem;">
                                    {{ $req->submitted_at->format('d M · h:i A') }}
                                </td>

                                {{-- Window --}}
                                <td class="py-3">
                                    @if($req->status === 'pending')
                                        <span class="badge rounded-pill"
                                              style="background:#eff6ff;color:#1d4ed8;font-size:.72rem;">
                                            W{{ $req->current_window }}
                                        </span>
                                    @else
                                        <span style="color:#cbd5e1;font-size:.8rem;">—</span>
                                    @endif
                                </td>

                                {{-- Status --}}
                                <td class="py-3">
                                    @if($req->status === 'pending')
                                        <span class="badge rounded-pill d-inline-flex align-items-center gap-1"
                                              style="background:#fffbeb;color:#92400e;font-weight:500;padding:.35em .75em;">
                                            <iconify-icon icon="mdi:timer-sand"
                                                          style="font-size:.85rem;"></iconify-icon> Pending
                                        </span>
                                    @elseif($req->status === 'approved')
                                        <span class="badge rounded-pill d-inline-flex align-items-center gap-1"
                                              style="background:#f0fdf4;color:#15803d;font-weight:500;padding:.35em .75em;">
                                            <iconify-icon icon="mdi:check-circle-outline"
                                                          style="font-size:.85rem;"></iconify-icon> Approved
                                        </span>
                                    @else
                                        <span class="badge rounded-pill d-inline-flex align-items-center gap-1"
                                              style="background:#fef2f2;color:#b91c1c;font-weight:500;padding:.35em .75em;">
                                            <iconify-icon icon="mdi:close-circle-outline"
                                                          style="font-size:.85rem;"></iconify-icon> Rejected
                                        </span>
                                    @endif

                                    {{-- Resolved timestamp --}}
                                    @if($req->status !== 'pending' && $req->updated_at)
                                        <div style="font-size:.7rem;color:#94a3b8;margin-top:.3rem;">
                                            {{ $req->updated_at->format('d M Y · h:i A') }}
                                        </div>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td class="py-3 pe-4 text-end">
                                    @if($req->status === 'pending')
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button wire:click="approve({{ $req->id }})"
                                                    wire:confirm="Approve this check-in request?"
                                                    class="btn btn-sm d-flex align-items-center gap-1"
                                                    style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">
                                                <iconify-icon icon="mdi:check"></iconify-icon>
                                                Approve
                                            </button>
                                            <button wire:click="reject({{ $req->id }})"
                                                    wire:confirm="Reject this check-in request?"
                                                    class="btn btn-sm d-flex align-items-center gap-1"
                                                    style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;">
                                                <iconify-icon icon="mdi:close"></iconify-icon>
                                                Reject
                                            </button>
                                        </div>
                                    @else
                                        <span style="font-size:.78rem;color:#94a3b8;">
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
                                <td colspan="7" class="text-center py-5">
                                    <iconify-icon icon="mdi:clock-check-outline"
                                                  style="font-size:2.5rem;color:#cbd5e1;"></iconify-icon>
                                    <p class="text-muted mt-2 mb-0">No check-in requests found.</p>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination — Bootstrap-styled, single location --}}
                @if($this->requests->hasPages())
                    <div class="px-4 py-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-3"
                         style="background:#fafafa;">
                        <span class="text-muted mb-0" style="font-size:.78rem;white-space:nowrap;">
                            Showing {{ $this->requests->firstItem() }}–{{ $this->requests->lastItem() }} of {{ $this->requests->total() }}
                        </span>
                        <nav class="d-flex align-items-center">
                            {{ $this->requests->onEachSide(1)->links() }}
                        </nav>
                    </div>
                @endif

            </div>
        </div>

    </div>
</div>

