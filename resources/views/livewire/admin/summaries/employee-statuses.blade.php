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

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;

        // Total employees in the organization
        $this->totalEmployees = Employee::where('organization_id', $orgId)->count();

        $today = Carbon::today();

        // Attendance statuses for today
        $attendances = Attendance::whereHas('employee', fn($q) => $q->where('organization_id', $orgId))
            ->whereDate('date', $today)
            ->get();

        $this->present = $attendances->where('status', 'Present')->count();
        $this->absent = $attendances->where('status', 'Absent')->count();
        $this->onLeave = $attendances->where('status', 'Leave')->count();
    }

}; ?>

<div class="row flex-nowrap">

    @php
        $cards = [
            ['title'=>'Total', 'count'=>$totalEmployees, 'icon'=>'mdi:account-group-outline', 'bg'=>'primary-gradient'],
            ['title'=>'Present', 'count'=>$present, 'icon'=>'mdi:account-check-outline', 'bg'=>'success-gradient'],
            ['title'=>'Absent', 'count'=>$absent, 'icon'=>'mdi:account-cancel-outline', 'bg'=>'danger-gradient'],
            ['title'=>'On Leave', 'count'=>$onLeave, 'icon'=>'mdi:beach', 'bg'=>'warning-gradient'],
        ];
    @endphp

    @foreach($cards as $card)
        <div class="col">
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




