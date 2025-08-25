<?php

use Livewire\Volt\Component;

new class extends Component {


    public $status;

    public function mount($status = null)
    {
        $this->status = $status;

    }

}; ?>

@push('styles')
    <style>
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

    </style>
@endpush

<div class="row">
    <div class="col-12">

        @php
            $statusLabel = $status
            ? match(strtolower($status)) {
               'clock_in' => 'Clocked In',
               'clock_out' => 'Clocked Out',
               'absent' => 'Absent',
               'unchecked_in' => 'Unchecked In',
               default => ucwords(str_replace('_', ' ', $status)),
           }
           : 'All Attendance';

               $breadcrumbItems = array_filter([
                   [
                       'label' => 'Dashboard',
                       'url' => route('dashboard'),
                       'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>',
                   ],
                   [
                       'label' => 'Attendance',
                       'url' => route('attendance.index'),
                       'icon' => '<iconify-icon icon="mdi:clipboard-text-check-outline" class="fs-5"></iconify-icon>',
                   ],
                   $status ? [
                       'label' => match(strtolower($status)) {
                           'clock_in' => 'Clocked In',
                           'clock_out' => 'Clocked Out',
                           'absent' => 'Absent',
                           'unchecked_in' => 'Unchecked In',
                           default => ucfirst($status)
                       },
                       'icon' => match(strtolower($status)) {
                           'clock_in' => '<iconify-icon icon="mdi:clock-in" class="fs-5 text-success"></iconify-icon>',
                           'clock_out' => '<iconify-icon icon="mdi:clock-out" class="fs-5 text-info"></iconify-icon>',
                           'absent' => '<iconify-icon icon="mdi:close-circle-outline" class="fs-5 text-danger"></iconify-icon>',
                           'unchecked_in' => '<iconify-icon icon="mdi:account-question" class="fs-5 text-warning"></iconify-icon>',
                           default => '<iconify-icon icon="mdi:alert-circle-outline" class="fs-5 text-secondary"></iconify-icon>',
                       }
                   ] : null
               ]);
        @endphp

        <livewire:admin.system-settings.bread-crumb
            :title="$statusLabel"
            :items="$breadcrumbItems"
        />


        <div class="card card-body">

            {{-- Livewire Table --}}
            <livewire:attendance-daily-table :status="$status ?? null" theme="bootstrap-4"/>

        </div>
    </div>
</div>







