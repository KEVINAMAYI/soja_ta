<?php

use App\Models\Overtime;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {


    #[On('approve')]
    public function approve($id)
    {
        $overtime = Overtime::findOrFail($id);
        $overtime->status = Overtime::STATUS_APPROVED;
        $overtime->approved_by = auth()->id();
        $overtime->rejected_reason = null; // clear if exists
        $overtime->save();

        LivewireAlert::title('Awesome!')
            ->text('Overtime Approved successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

        $this->dispatch('refreshDatatable');

    }

    #[On('reject')]
    public function reject($id)
    {
        $overtime = Overtime::findOrFail($id);
        $overtime->status = Overtime::STATUS_REJECTED;
        $overtime->approved_by = auth()->id();
        $overtime->rejected_reason = 'Not specified';
        $overtime->save();

        LivewireAlert::title('Awesome!')
            ->text('Overtime Rejected successfully.')
            ->success()
            ->toast()
            ->position('top-end')
            ->show();

        $this->dispatch('refreshDatatable');
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

        /* Main dropdown container */
        ul.dropdown-menu[role="menu"][default-colors][default-styling][default-width] {
            width: auto !important;
            min-width: 440px;
            padding: 0;
            margin-top: 12px;
            background: linear-gradient(145deg, #e3f2fd, #bbdefb);
            border: 1px solid #90caf9;
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            z-index: 1050;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Filter wrapper */
        #table-filter-month-wrapper {
            width: 100% !important;
            padding: 20px 24px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
        }

        /* Label styling */
        #table-filter-month-wrapper label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #0d47a1;
        }

        /* Input field styling */
        #table-filter-month-wrapper input[type="date"] {
            width: 100%;
            padding: 10px 14px;
            font-size: 0.95rem;
            border: 1px solid #90caf9;
            border-radius: 8px;
            background-color: #ffffff;
            color: #0d47a1;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        #table-filter-month-wrapper input[type="date"]:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 0.25rem rgba(25, 118, 210, 0.25);
            outline: none;
        }

        /* Clear button */
        ul.dropdown-menu .dropdown-item.btn {
            display: block;
            width: 100%;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #0d47a1;
            background-color: transparent;
            border-top: 1px solid rgba(255, 255, 255, 0.4);
            transition: background-color 0.2s ease-in-out;
        }

        ul.dropdown-menu .dropdown-item.btn:hover {
            background-color: rgba(33, 150, 243, 0.1);
        }

    </style>
@endpush
<div class="row">
    <div class="col-12">
        <div class="card card-body">

            <livewire:overtime-table theme="bootstrap-4"/>

        </div>
    </div>
</div>




