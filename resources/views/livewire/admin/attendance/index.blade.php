<?php

use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {


    public $status;

    #[Url]
    public $filterStatus;

    public $startDate;
    public $endDate;

    public function mount()
    {
        $this->status = $this->filterStatus;
        $today = now()->toDateString();
        $this->startDate = $today;
        $this->endDate = $today;

    }


    #[On('filter-updated')]
    public function dateChaged()
    {
        // Emit event to other Livewire components
        $this->dispatch('date-range-updated', startDate: $this->startDate, endDate: $this->endDate, status: $this->filterStatus);

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
            background-color: #c2361d; /* darker shade for hover */
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


            <div class="row align-items-end mb-4 justify-content-end">

                {{-- BUTTON ON THE RIGHT --}}
                <div class="col-md-4 text-end">
                    @if (str_contains($filterStatus, 'sick_off'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Sick Off
                        </a>
                    @elseif (str_contains($filterStatus, 'off_shift'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Off Shifts
                        </a>
                    @elseif (str_contains($filterStatus, 'on_leave'))
                        <a href="{{ route('leaves.create') }}" class="btn btn-primary">
                            + Create Leave Request
                        </a>
                    @endif
                </div>
            </div>

            <div class="row align-items-end mb-4">
                {{-- DATE FILTERS --}}
                <div class="col-md-4">
                    <label class="form-label">Start Date</label>
                    <input
                        type="date"
                        id="attendance-start-date"
                        class="form-control"
                        wire:model="startDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>

                <div class="col-md-4">
                    <label class="form-label">End Date</label>
                    <input
                        type="date"
                        id="attendance-end-date"
                        class="form-control"
                        wire:model="endDate"
                        wire:change="$dispatch('filter-updated')"
                    />
                </div>


                <div class="col-md-4">
                    <label class="form-label">Attendance Status</label>
                    <select
                        class="form-control"
                        wire:model="filterStatus"
                        wire:change="$dispatch('filter-updated')">
                        <option value="">All</option>
                        <option value="present">Present [Clocked In + Clocked Out]</option>
                        <option value="clocked_in">Clocked In</option>
                        <option value="clocked_out">Clocked Out</option>
                        <option value="absent">Absent</option>
                        <option value="on_leave">On Leave</option>
                        <option value="off_shift">Off Shift</option>
                        <option value="sick_off">Sick Off</option>
                    </select>
                </div>

            </div>

            {{-- Livewire Table --}}
            <livewire:attendance-daily-table :status="$status ?? null" theme="bootstrap-4"/>
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






