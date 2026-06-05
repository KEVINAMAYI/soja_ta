<?php

use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use App\Models\Department;

new class extends Component {

    public $department_id;
    public $startDate;
    public $endDate;
    public $departments = [];

    public function mount()
    {
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;
        $this->department_id = 'all';

        // Load departments for the current organization
        $orgId = auth()->user()->employee->organization_id ?? null;
        if ($orgId) {
            $this->departments = Department::where('organization_id', $orgId)
                ->orderBy('name')
                ->get();
        }
    }

    #[On('filter-updated')]
    public function filterChanged()
    {
        // Emit event to the table component
        $this->dispatch('timesheet-range-updated',
            startDate: $this->startDate,
            endDate: $this->endDate,
            department_id: $this->department_id
        );
    }

}; ?>

@push('styles')
    <style>

        #table-bulkActionsDropdown {
            background-color: #e14326;
            border: none;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        #table-bulkActionsDropdown:hover {
            background-color: #c2361d;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(225, 67, 38, 0.4);
        }

        .btn-outline-secondary {
            margin-left: 0.5rem !important;
            padding: 6px 16px !important;
            border-radius: 8px !important;
            font-size: 0.875rem !important;
            transition: all 0.2s ease-in-out !important;
            border-color: red !important;
        }

        .btn-outline-secondary:hover {
            background-color: #f1f1f1 !important;
            border-color: #aaa !important;
            color: #000 !important;
        }

        .btn-outline-secondary svg,
        .btn-outline-secondary svg * {
            fill: red !important;
            stroke: red !important;
        }

        .btn-outline-secondary:hover svg,
        .btn-outline-secondary:hover svg * {
            fill: white !important;
            stroke: white !important;
        }

        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            background-clip: padding-box !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
            transition: all 0.2s ease-in-out !important;
        }

        table.dataTable td {
            vertical-align: middle !important;
        }

        .fw-semibold {
            font-weight: 600 !important;
        }

        .text-secondary {
            color: #46259a !important;
        }

        .text-muted {
            color: #adb5bd !important;
        }

        table.dataTable tbody tr:hover {
            background-color: #f8f9fa !important;
        }

        iconify-icon {
            vertical-align: middle !important;
        }
    </style>
@endpush

<div class="row">
    <div class="col-12">

        @php
            $statusLabel = Route::currentRouteName() === 'attendance.index'
                ? 'Attendance'
                : 'Timesheets';

            $breadcrumbItems = [
                [
                    'label' => 'Dashboard',
                    'url' => route('dashboard'),
                    'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
                ],
                [
                    'label' => $statusLabel,
                    'url' => Route::currentRouteName() === 'timesheets.index'
                        ? route('timesheets.index')
                        : route('attendance.index'),
                    'icon' => Route::currentRouteName() === 'timesheets.index'
                        ? '<iconify-icon icon="mdi:calendar-clock" class="fs-5 text-primary"></iconify-icon>'
                        : '<iconify-icon icon="mdi:clipboard-text-check-outline" class="fs-5"></iconify-icon>',
                ],
            ];
        @endphp

        <livewire:admin.system-settings.bread-crumb
            :title="$statusLabel"
            :items="$breadcrumbItems"
        />

        <div class="card card-body">

            <div class="row align-items-end mb-4">

                {{-- START DATE --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input
                        type="date"
                        class="form-control"
                        wire:model="startDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                {{-- END DATE --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">End Date</label>
                    <input
                        type="date"
                        class="form-control"
                        wire:model="endDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                {{-- DEPARTMENT FILTER --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department</label>
                    <select
                        class="form-control"
                        wire:model="department_id"
                        wire:change="$dispatch('filter-updated')">
                        <option value="all">All Departments</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Livewire Table --}}
            <livewire:attendance-monthly-table theme="bootstrap-4"/>
        </div>

    </div>
</div>

@push('scripts')
    <script>
        window.addEventListener('replace-url', event => {
            window.history.replaceState({}, '', event.detail.url);
        });
    </script>
@endpush
