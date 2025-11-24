<?php

use Livewire\Volt\Component;
use App\Models\Employee;
use App\Models\Attendance;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {

    public $totalEmployees;
    public $present;
    public $absent;
    public $onLeave;
    public $offShift;
    public $sickOff;
    public $inactiveEmployees;

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;

        // Total employees in the organization
        $this->totalEmployees = Employee::where('organization_id', $orgId)->count();

        $today = Carbon::today();

        // Fetch all today's attendances for this org
        $attendances = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereDate('date', $today)->get();

        // Present (clocked in/out) — unique employees
        $this->present = $attendances
            ->whereIn('status', ['clocked_in', 'clocked_out'])
            ->unique('employee_id')
            ->count();

        // Absent — unique employees
        $this->absent = $attendances
            ->whereIn('status', ['absent', 'unchecked_in'])
            ->unique('employee_id')
            ->count();

        // On Leave — unique employees
        $this->onLeave = $attendances
            ->where('status', 'on_leave')
            ->unique('employee_id')
            ->count();

        // Off Shift — unique employees
        $this->offShift = $attendances
            ->where('status', 'off_shift')
            ->unique('employee_id')
            ->count();

        // Fetch Sick Off count (You need to define how "Sick Off" is identified in your data)
        $this->sickOff = $attendances->where('status', 'sick_off')->count();

        // Fetch Inactive Employees count
        $this->inactiveEmployees = Employee::where('organization_id', $orgId)->where('active', 0)->count();
    }

}; ?>

<div>


    <!-- Second Row: Additional Stats (Off Shift, Sick Off, Inactive Employees) -->
    <div class="row g-3 mt-2 mb-2">

        @php
            $additionalCards = [
                ['title'=>'Present', 'count'=>$present, 'icon'=>'mdi:account-check-outline', 'bg'=>'success-gradient'],
                ['title'=>'Absent', 'count'=>$absent, 'icon'=>'mdi:account-cancel-outline', 'bg'=>'danger-gradient'],
            ];
        @endphp

            <!-- Additional Stats Cards -->
        @foreach($additionalCards as $card)
            <div class="col-lg-6 col-6">
                <div class="card {{ $card['bg'] }}">
                    <div class="card-body text-center px-5 py-3">
                        <div
                            class="d-flex align-items-center justify-content-center round-48 rounded text-bg-primary flex-shrink-0 mb-3 mx-auto">
                            <iconify-icon icon="{{ $card['icon'] }}" class="fs-6 text-white"></iconify-icon>
                        </div>
                        <h6 class="fw-normal fs-6 mb-1">{{ $card['title'] }}</h6>
                        <h4 class="mb-2 d-flex align-items-center justify-content-center gap-1">{{ $card['count'] }}</h4>
                        <a href="javascript:void(0)" class="btn btn-white btn-sm fs-2 fw-semibold">View Details</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">

        @php
            $cards = [
                ['title'=>'On Leave', 'count'=>$onLeave, 'icon'=>'mdi:beach', 'bg'=>'warning-gradient'],
                ['title'=>'Off Shift', 'count'=>$offShift, 'icon'=>'mdi:briefcase-off-outline', 'bg'=>'danger-gradient'],
                ['title'=>'Sick Off', 'count'=>$sickOff, 'icon'=>'mdi:medical-bag', 'bg'=>'danger-gradient'],
                ['title'=>'Inactive', 'count'=>$inactiveEmployees, 'icon'=>'mdi:account-off', 'bg'=>'danger-gradient'],

            ];
        @endphp

            <!-- First Row: Important Stats -->
        @foreach($cards as $card)
            <div class="col-lg-3 col-6">
                <div class="card {{ $card['bg'] }}">
                    <div class="card-body text-center px-5 py-3">
                        <div
                            class="d-flex align-items-center justify-content-center round-48 rounded text-bg-primary flex-shrink-0 mb-3 mx-auto">
                            <iconify-icon icon="{{ $card['icon'] }}" class="fs-6 text-white"></iconify-icon>
                        </div>
                        <h6 class="fw-normal fs-6 mb-1">{{ $card['title'] }}</h6>
                        <h4 class="mb-2 d-flex align-items-center justify-content-center gap-1">{{ $card['count'] }}</h4>
                        <a href="javascript:void(0)" class="btn btn-white btn-sm fs-2 fw-semibold">View Details</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

</div>



