<?php

use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use App\Models\Department;

new class extends Component {

    public $startDate;
    public $endDate;
    public $unit_id = null;
    public $department_id = null;
    public $section_id = null;
    public $subsection_id = null;
    public $includeOutsourced = false;

    public function mount()
    {
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;
    }

    public function units()
    {
        return \App\Models\Unit::where('organization_id', auth()->user()->employee->organization_id)
            ->orderBy('name')->get();
    }

    public function departmentsForUnit()
    {
        if (!$this->unit_id) return collect();
        return Department::where('unit_id', $this->unit_id)->orderBy('name')->get();
    }

    public function sectionsForDepartment()
    {
        if (!$this->department_id) return collect();
        return \App\Models\Section::where('department_id', $this->department_id)->orderBy('name')->get();
    }

    public function subsectionsForSection()
    {
        if (!$this->section_id) return collect();
        return \App\Models\Subsection::where('section_id', $this->section_id)->orderBy('name')->get();
    }

    #[On('filter-updated')]
    public function filterChanged($unit_id = null, $department_id = null, $section_id = null, $subsection_id = null, $includeOutsourced = null)
    {
        if ($unit_id !== null) $this->unit_id = $unit_id;
        if ($department_id !== null) $this->department_id = $department_id;
        if ($section_id !== null) $this->section_id = $section_id;
        if ($subsection_id !== null) $this->subsection_id = $subsection_id;
        if ($includeOutsourced !== null) $this->includeOutsourced = $includeOutsourced;

        // Emit event to the table component
        $this->dispatch('timesheet-range-updated',
            startDate: $this->startDate,
            endDate: $this->endDate,
            unit_id: $this->unit_id,
            department_id: $this->department_id,
            section_id: $this->section_id,
            subsection_id: $this->subsection_id,
            include_outsourced: $this->includeOutsourced,
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

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-6">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input
                        type="date"
                        class="form-control"
                        wire:model="startDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                <div class="col-6 col-md-6">
                    <label class="form-label fw-semibold">End Date</label>
                    <input
                        type="date"
                        class="form-control"
                        wire:model="endDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>
            </div>

            <div class="border rounded-3 bg-light-subtle p-3 mb-4">
                <div class="fw-bold text-uppercase small text-muted mb-3" style="letter-spacing:.04em;">
                    <i class="ti ti-sitemap me-1"></i>Filter by Organization
                </div>

                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Unit</label>
                        <select class="form-control" wire:model="unit_id" wire:change="$dispatch('filter-updated')">
                            <option value="">All Units</option>
                            @foreach ($this->units() as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Department</label>
                        <select class="form-control" wire:model="department_id" wire:change="$dispatch('filter-updated')" @disabled(!$unit_id)>
                            <option value="">{{ $unit_id ? 'All Departments' : 'Select a Unit first' }}</option>
                            @foreach ($this->departmentsForUnit() as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Section</label>
                        <select class="form-control" wire:model="section_id" wire:change="$dispatch('filter-updated')" @disabled(!$department_id)>
                            <option value="">{{ $department_id ? 'All Sections' : '—' }}</option>
                            @foreach ($this->sectionsForDepartment() as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold text-uppercase text-muted mb-1">Subsection</label>
                        <select class="form-control" wire:model="subsection_id" wire:change="$dispatch('filter-updated')" @disabled(!$section_id)>
                            <option value="">{{ $section_id ? 'All Subsections' : '—' }}</option>
                            @foreach ($this->subsectionsForSection() as $sub)
                                <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 border border-dashed rounded-2 px-3 py-2 mt-3" style="background:rgba(0,0,0,.015);">
                    <input type="checkbox" class="form-check-input mt-0 flex-shrink-0" id="timesheetsInclOut" wire:model="includeOutsourced" wire:change="$dispatch('filter-updated')">
                    <label class="form-check-label small mb-0" for="timesheetsInclOut">
                        <span class="fw-semibold">Include Outsourced staff</span>
                        <span class="text-muted d-block d-md-inline"> — excluded by default since they don't sit under Unit/Department/Section.</span>
                    </label>
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
