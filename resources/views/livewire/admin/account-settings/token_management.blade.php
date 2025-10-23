<?php


use App\Models\Employee;
use App\Helpers\QRCodeGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Volt\Component;

new class extends Component {

    public $activeTab = 'assign';
    public $token_id;
    public $employee_id;
    public $employees = [];

    public function mount()
    {
        $orgId = Auth::user()->employee->organization_id ?? null;

        if ($orgId) {
            $this->employees = Employee::where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name']);
        }
    }

    protected $rules = [
        'token_id' => 'required|string|max:255',
        'employee_id' => 'required|exists:employees,id',
    ];

    public function assignToken()
    {
        $this->validate();

        DB::beginTransaction();

        try {
            $employee = Employee::findOrFail($this->employee_id);

            $employee->update([
                'qr_code' => $this->token_id,
            ]);

            DB::commit();

            $this->reset(['token_id', 'employee_id']);

            LivewireAlert::title('Awesome!')
                ->text('Token assigned successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while assigning the token.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function generateToken()
    {
        $this->validate([
            'employee_id' => 'required|exists:employees,id',
        ]);

        DB::beginTransaction();

        try {
            $employee = Employee::findOrFail($this->employee_id);

            $employee->qr_code = QRCodeGenerator::generateEmployeeCode(
                $employee->organization_id,
                $employee->id ?? (Employee::max('id') + 1)
            );

            $employee->save();

            DB::commit();

            $this->reset('employee_id');

            LivewireAlert::title('Awesome!')
                ->text('New QR token generated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while generating the token.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

} ?>

@push('styles')

    <style>

        input::placeholder {
            color: #9ca3af !important;
            opacity: 0.7 !important;
        }


        /* Inner tabs underline style (matching your reference) */
        #qrTokenTabs .nav-link {
            border: none !important;
            border-bottom: 2px solid transparent !important;
            color: #6b7280 !important; /* neutral gray */
            font-weight: 500 !important;
            transition: all 0.2s ease-in-out !important;
            background-color: transparent !important;
            border-radius: 0 !important;
            padding-bottom: 0.75rem !important;
        }

        #qrTokenTabs .nav-link.active {
            border-bottom: 2px solid #e14326 !important; /* custom underline color */
            color: #e14326 !important;
            background-color: transparent !important;
        }

        #qrTokenTabs .nav-link:hover {
            color: #e14326 !important;
        }

        /* Remove gray divider completely */
        #qrTokenTabs {
            border: none !important;
        }
    </style>
@endpush


<div class="card border-0 shadow-sm p-4">
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="qrTokenTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'assign' ? 'active' : '' }}"
                    wire:click="$set('activeTab', 'assign')">
                <i class="ti ti-qrcode me-1"></i> Assign Token
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'generate' ? 'active' : '' }}"
                    wire:click="$set('activeTab', 'generate')">
                <i class="ti ti-plus me-1"></i> Generate New Token
            </button>
        </li>
    </ul>

    <!-- Flash message -->
    @if (session()->has('success'))
        <div class="alert alert-success border-0 py-2 mb-3">{{ session('success') }}</div>
    @endif

    <!-- Assign Token Tab -->
    @if ($activeTab === 'assign')
        <div class="alert alert-primary bg-light text-primary border-0 py-2 mb-4">
            <i class="ti ti-qrcode me-1"></i>
            Assign an existing physical QR token to an employee
        </div>

        <form wire:submit.prevent="assignToken" class="space-y-3">
            <div class="mb-3">
                <label class="form-label fw-semibold">Token ID</label>
                <input type="text"
                       wire:model.defer="token_id"
                       class="form-control"
                       placeholder="Scan or enter token">
                @error('token_id') <small class="text-danger">{{ $message }}</small> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Select Employee</label>
                <select wire:model.defer="employee_id" class="form-select">
                    <option value="">-- Select an employee --</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                    @endforeach
                </select>
                @error('employee_id') <small class="text-danger">{{ $message }}</small> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="ti ti-user-check me-1"></i> Assign Token to Employee
            </button>
        </form>

        <!-- Generate Token Tab -->
    @else
        <div class="alert alert-light border text-primary py-2 mb-4">
            <i class="ti ti-qrcode me-1"></i>
            Generate a new QR token for an existing employee
        </div>

        <form wire:submit.prevent="generateToken" class="space-y-3">
            <div class="mb-3">
                <label class="form-label fw-semibold">Select Employee</label>
                <select wire:model.defer="employee_id" class="form-select">
                    <option value="">-- Select an employee --</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                    @endforeach
                </select>
                @error('employee_id') <small class="text-danger">{{ $message }}</small> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="ti ti-qrcode me-1"></i> Generate New Token
            </button>
        </form>
    @endif
</div>

