<?php

use Livewire\Volt\Component;

new class extends Component {
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

        <livewire:admin.system-settings.bread-crumb
            title="Department Reports"
            :items="[
        [
            'label' => 'Dashboard',
            'url' => route('dashboard'),
            'icon' => '<iconify-icon icon=\'solar:home-2-line-duotone\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Reports',
            'icon' => '<iconify-icon icon=\'mdi:file-chart-outline\' class=\'fs-5\'></iconify-icon>',
        ],
        [
            'label' => 'Departments',
            'icon' => '<iconify-icon icon=\'mdi:office-building-outline\' class=\'fs-5\'></iconify-icon>',
        ]
      ]"
        />


        <div class="card card-body">

            {{-- Livewire Table --}}
            <livewire:departmental-attendance-table theme="bootstrap-4"/>

        </div>
    </div>
</div>







