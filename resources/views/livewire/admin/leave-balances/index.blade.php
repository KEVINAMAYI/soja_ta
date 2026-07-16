<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveApprovalService;
use Illuminate\Pagination\Paginator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LeaveBalancesExcelExport;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public $leaveTypeId = '';
    public $departmentId;
    public $search = '';
    public $year;

    public array $leaveTypes = [];
    public array $departments = [];

    // ── Edit balance modal state ─────────────────────────────────────────
    public $lbEmployeeId;
    public $lbLeaveTypeId;
    public $lbEmployeeName;
    public $lbLeaveTypeName;
    public $lbEntitledDays;
    public $lbUsedDays;
    public bool $lbHasOverride = false;

    public function boot(): void
    {
        // Use Bootstrap-themed pagination links so they match this page's styling
        Paginator::useBootstrapFive();
    }

    public function mount()
    {
        $orgId = auth()->user()->employee?->organization_id;
        $this->year = now()->year;

        $types = LeaveType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $this->leaveTypes = $types->toArray();

        $this->departments = Department::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    public function getGroupedRowsProperty(): array
    {
        return collect($this->rows)
            ->groupBy('employee_id')
            ->map(function ($rows, $employeeId) {
                $first = $rows->first();

                return [
                    'employee_id' => (int)$employeeId,
                    'employee_name' => $first['employee_name'],
                    'department' => $first['department'] ?? '—',
                    'summary' => [
                        'annual' => $this->summaryFor($rows, 'annual'),
                        'sick' => $this->summaryFor($rows, 'sick'),
                        'personal' => $this->summaryFor($rows, 'personal'),
                    ],
                    'leave_types' => $rows->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function summaryFor($rows, string $keyword): ?array
    {
        $row = $rows->first(
            fn($r) => str_contains(strtolower($r['leave_type_name']), $keyword)
        );

        if (!$row) {
            return null;
        }

        return [
            'entitled' => $row['entitled_days'], // null = Unlimited
            'leave_type_id' => $row['leave_type_id'],
        ];
    }

    public function updated($property)
    {
        if (in_array($property, ['leaveTypeId', 'departmentId', 'search', 'year'])) {
            $this->resetPage();
        }
    }

    public function getEmployeesProperty()
    {
        $orgId = auth()->user()->employee?->organization_id;

        return Employee::where('organization_id', $orgId)
            ->when($this->departmentId, fn($q) => $q->where('department_id', $this->departmentId))
            ->when($this->search, fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->with('department')
            ->orderBy('name')
            ->paginate(15);
    }

    public function getRowsProperty(): array
    {
        $employees = collect($this->employees->items());

        if ($employees->isEmpty()) {
            return [];
        }

        $service = app(LeaveApprovalService::class);

        if ($this->leaveTypeId) {
            $type = LeaveType::find($this->leaveTypeId);
            return $type ? $service->balancesForType($type, $employees, (int)$this->year) : [];
        }

        $types = collect($this->leaveTypes)->map(fn($t) => LeaveType::find($t['id']))->filter();

        return $service->balancesForTypes($types, $employees, (int)$this->year);
    }

    public function editBalanceHandler(int $employeeId, int $leaveTypeId): void
    {
        $row = collect($this->rows)->first(
            fn($r) => $r['employee_id'] === $employeeId && $r['leave_type_id'] === $leaveTypeId
        );

        if (!$row) {
            return;
        }

        $this->lbEmployeeId = $employeeId;
        $this->lbLeaveTypeId = $leaveTypeId;
        $this->lbEmployeeName = $row['employee_name'];
        $this->lbLeaveTypeName = $row['leave_type_name'];
        $this->lbEntitledDays = $row['entitled_days'];
        $this->lbUsedDays = $row['used_days'];
        $this->lbHasOverride = $row['has_override'] ?? false;

        $this->dispatch('show-leave-balance-modal');
    }

    public function saveBalance(): void
    {
        $this->validate([
            'lbEntitledDays' => 'required|numeric|min:0',
            'lbUsedDays' => 'required|numeric|min:0',
        ]);

        $orgId = auth()->user()->employee?->organization_id;

        app(LeaveApprovalService::class)->setBalanceOverride(
            $orgId,
            (int)$this->lbEmployeeId,
            (int)$this->lbLeaveTypeId,
            (int)$this->year,
            (float)$this->lbEntitledDays,
            (float)$this->lbUsedDays
        );

        $this->dispatch('hide-leave-balance-modal');
        $this->resetBalanceForm();

        LivewireAlert::title('Saved!')
            ->text('Leave balance updated successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();
    }

    public function resetBalanceForm(): void
    {
        $this->reset(['lbEmployeeId', 'lbLeaveTypeId', 'lbEmployeeName', 'lbLeaveTypeName', 'lbEntitledDays', 'lbUsedDays', 'lbHasOverride']);
    }

    #[On('discard-leave-balance-modal')]
    public function discardBalanceModal(): void
    {
        $this->resetBalanceForm();
    }

    public function exportExcel()
    {
        $orgId = auth()->user()->employee?->organization_id;

        $employees = Employee::where('organization_id', $orgId)
            ->when($this->departmentId, fn($q) => $q->where('department_id', $this->departmentId))
            ->when($this->search, fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->with('department')
            ->orderBy('name')
            ->get();

        $service = app(LeaveApprovalService::class);

        if ($this->leaveTypeId) {
            $type = LeaveType::find($this->leaveTypeId);
            $rows = $type ? $service->balancesForType($type, $employees, (int)$this->year) : [];
        } else {
            $types = LeaveType::where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get();
            $rows = $service->balancesForTypes($types, $employees, (int)$this->year);
        }

        $orgName = auth()->user()->employee?->organization?->name ?? 'Organization';

        return Excel::download(new LeaveBalancesExcelExport($rows, $orgName, (int)$this->year), 'leave-balances.xlsx');
    }

    public function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $initials = strtoupper(($parts[0][0] ?? '') . ($parts[1][0] ?? ''));
        return $initials ?: '?';
    }

    public function avatarColor(string $name): string
    {
        $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];
        $index = crc32($name) % count($palette);
        return $palette[$index];
    }
};
?>

<div class="container-fluid">
    <livewire:admin.system-settings.bread-crumb
        title="Leave Balances"
        :items="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>'],
            ['label' => 'Leave Balances', 'icon' => '<iconify-icon icon=\'mdi:calendar-account-outline\' class=\'fs-5\'></iconify-icon>'],
        ]"
    />

    <div class="card card-body">
        <div class="d-flex flex-wrap justify-content-end align-items-center mb-4">
            <div class="d-flex gap-2">
                <button wire:click="exportExcel" class="btn btn-outline-success btn-sm d-flex align-items-center gap-1">
                    <iconify-icon icon="mdi:microsoft-excel"></iconify-icon>
                    Export Excel
                </button>
                <a class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1"
                   href="{{ route('leave-balances.export.pdf', [
                        'leave_type_id' => $leaveTypeId,
                        'department_id' => $departmentId,
                        'search' => $search,
                        'year' => $year,
                   ]) }}"
                   target="_blank">
                    <iconify-icon icon="mdi:file-pdf-box"></iconify-icon>
                    Export PDF
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small text-uppercase text-muted fw-semibold">Leave Type</label>
                <select class="form-select" wire:model.live="leaveTypeId">
                    <option value="">All Types</option>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small text-uppercase text-muted fw-semibold">Department</label>
                <select class="form-select" wire:model.live="departmentId">
                    <option value="">All departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept['id'] }}">{{ $dept['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small text-uppercase text-muted fw-semibold">Year</label>
                <input type="number" class="form-control" wire:model.live.debounce.500ms="year">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-uppercase text-muted fw-semibold">Search Employee</label>
                <input type="text" class="form-control" placeholder="Employee name..."
                       wire:model.live.debounce.300ms="search">
            </div>
        </div>

        <div class="accordion" id="leaveBalancesAccordion">
            @forelse($this->groupedRows as $emp)
                @php $collapseId = 'emp-' . $emp['employee_id']; @endphp
                <div class="accordion-item mb-2 border rounded">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed d-flex align-items-center gap-3"
                                type="button" data-bs-toggle="collapse"
                                data-bs-target="#{{ $collapseId }}">

                            <div
                                class="d-flex align-items-center justify-content-center rounded-circle fw-bold text-white flex-shrink-0"
                                style="width:36px;height:36px;font-size:.75rem;background-color:{{ $this->avatarColor($emp['employee_name']) }};">
                                {{ $this->initials($emp['employee_name']) }}
                            </div>

                            <div class="flex-grow-1 d-flex flex-wrap align-items-center gap-4">
                                <div style="min-width:180px;">
                                    <div class="fw-semibold">{{ $emp['employee_name'] }}</div>
                                    <div class="text-muted small">{{ $emp['department'] }}</div>
                                </div>

                                <div class="text-center" style="min-width:80px;">
                                    <div class="text-muted small text-uppercase">Annual</div>
                                    <div class="fw-semibold">
                                        {{ $emp['summary']['annual']['entitled'] ?? '—' }}
                                    </div>
                                </div>

                                <div class="text-center" style="min-width:80px;">
                                    <div class="text-muted small text-uppercase">Sick</div>
                                    <div class="fw-semibold">
                                        @if(is_null($emp['summary']['sick']['entitled'] ?? null))
                                            <span class="fs-5">&infin;</span>
                                        @else
                                            {{ $emp['summary']['sick']['entitled'] }}
                                        @endif
                                    </div>
                                </div>

                                <div class="text-center" style="min-width:80px;">
                                    <div class="text-muted small text-uppercase">Personal</div>
                                    <div class="fw-semibold">
                                        {{ $emp['summary']['personal']['entitled'] ?? '—' }}
                                    </div>
                                </div>
                            </div>
                        </button>
                    </h2>

                    <div id="{{ $collapseId }}" class="accordion-collapse collapse"
                         data-bs-parent="#leaveBalancesAccordion">
                        <div class="accordion-body">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr class="text-uppercase small text-muted">
                                    <th class="border-0">Leave Type</th>
                                    <th class="border-0 text-end">Entitled</th>
                                    <th class="border-0 text-end">Used</th>
                                    <th class="border-0 text-end">Pending</th>
                                    <th class="border-0 text-end">Remaining</th>
                                    <th class="border-0 text-end">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($emp['leave_types'] as $row)
                                    <tr>
                                        <td>
                                    <span class="badge bg-primary-subtle text-primary fw-semibold">
                                        {{ $row['leave_type_icon'] ?? '' }} {{ $row['leave_type_name'] }}
                                    </span>
                                        </td>
                                        <td class="text-end">
                                            @if($row['entitled_days'] === null)
                                                <span class="badge bg-secondary-subtle text-secondary">Unlimited</span>
                                            @else
                                                <span class="fw-semibold">{{ $row['entitled_days'] }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $row['used_days'] }}</td>
                                        <td class="text-end">
                                            @if($row['pending_days'] > 0)
                                                <span
                                                    class="badge bg-warning-subtle text-warning">{{ $row['pending_days'] }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($row['remaining_days'] === null)
                                                <span class="badge bg-secondary-subtle text-secondary">Unlimited</span>
                                            @else
                                                <span
                                                    class="badge {{ $row['remaining_days'] < 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }} fw-semibold">
                                            {{ $row['remaining_days'] }}
                                        </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary"
                                                    wire:click="editBalanceHandler({{ $row['employee_id'] }}, {{ $row['leave_type_id'] }})"
                                                    title="Edit balance">
                                                <i class="ti ti-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-5">
                    <div class="d-flex flex-column align-items-center">
                        <iconify-icon icon="mdi:calendar-search-outline" class="fs-1 text-muted mb-2"></iconify-icon>
                        <h6 class="text-muted">No records found</h6>
                    </div>
                </div>
            @endforelse
        </div>

        <div class="mt-3">
            {{ $this->employees->onEachSide(1)->links() }}
        </div>
    </div>

    {{-- Edit Balance Modal --}}
    <div class="modal fade" id="leaveBalanceModal" tabindex="-1"
         aria-labelledby="leaveBalanceModalTitle" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Balance — {{ $lbEmployeeName }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form wire:submit.prevent="saveBalance">
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            {{ $lbLeaveTypeName }} &middot; {{ $year }}
                            @if(!$lbHasOverride)
                                <br>No override set yet — these values are the leave type's default.
                            @endif
                        </p>

                        <div class="mb-3">
                            <label for="lbEntitledDays" class="form-label">Entitled Days</label>
                            <input type="number" step="0.5" min="0" wire:model="lbEntitledDays"
                                   id="lbEntitledDays" class="form-control">
                            @error('lbEntitledDays') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>

                        <div class="mb-3">
                            <label for="lbUsedDays" class="form-label">Used Days</label>
                            <input type="number" step="0.5" min="0" wire:model="lbUsedDays"
                                   id="lbUsedDays" class="form-control">
                            @error('lbUsedDays') <small class="text-danger">{{ $message }}</small> @enderror
                        </div>
                    </div>

                    <div class="modal-footer d-flex gap-1">
                        <button type="submit" class="btn btn-success">Save</button>
                        <button wire:click="$dispatch('discard-leave-balance-modal')" type="button"
                                class="btn btn-outline-danger" data-bs-dismiss="modal">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('show-leave-balance-modal', () => {
            new bootstrap.Modal(document.getElementById('leaveBalanceModal')).show();
        });

        window.addEventListener('hide-leave-balance-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('leaveBalanceModal'))?.hide();
        });
    </script>
@endpush
