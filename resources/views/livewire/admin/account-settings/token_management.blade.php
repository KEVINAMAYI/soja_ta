<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Firebase\JWT\JWT;

new class extends Component {

    public $activeTab = 'assign';
    public $token_id;
    public $employee_id;
    public $employees = [];
    public $bulk_count = 10; // Default number of bulk tokens

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
            $employee->update(['qr_code' => $this->token_id]);

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


    public function generateBulkTokens()
    {
        try {

            $tokens = [];

            $privateKeyPath = storage_path('app/keys/private.pem');

            if (!file_exists($privateKeyPath)) {
                throw new Exception('Private key not found on server.');
            }

            $privateKey = file_get_contents($privateKeyPath);
            $orgId = Auth::user()->employee->organization_id ?? null;

            if (!$orgId) {
                throw new Exception('Organization ID not found for the current user.');
            }

            for ($i = 0; $i < $this->bulk_count; $i++) {
                $payload = [
                    'o' => $orgId,
                    'n' => Str::random(5),
                ];

                // Sign the token
                $token = JWT::encode($payload, $privateKey, 'ES256');

                // Generate QR code PNG
                $qrPng = QrCode::format('png')
                    ->size(200)
                    ->margin(0)
                    ->generate($token);

                $tokens[] = [
                    'token' => $token,
                    'qr' => base64_encode($qrPng),
                ];
            }

            // Generate PDF with tokens
            $pdf = Pdf::loadView('pdf.bulk_tokens', compact('tokens'))
                ->setPaper('a4', 'portrait');

            $this->dispatch('stopGenerating');

            return response()->streamDownload(function () use ($pdf) {
                echo $pdf->output();
            }, 'bulk_qr_tokens.pdf');

        } catch (Exception $e) {
            // Log the full error for debugging
            Log::error('Bulk token generation failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            // Return a simple JSON error message (safe for production)
            return response()->json([
                'error' => 'An unexpected error occurred while generating tokens.',
                'details' => app()->environment('local') ? $e->getMessage() : null, // Show message only in local
            ], 500);
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
        <div
            x-data="{ generating: false }"
            x-on:generate-start.window="generating = true"
            x-on:generate-stop.window="generating = false"
        >
            <div class="alert alert-light border text-primary py-2 mb-4">
                <i class="ti ti-grid-dots me-1"></i>
                Generate multiple QR tokens at once for printing
            </div>

            <form wire:submit.prevent="generateBulkTokens" class="position-relative">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Number of Tokens to Generate</label>
                    <input type="number" wire:model.defer="bulk_count" class="form-control"/>
                    <small class="text-muted">
                        Tokens will be arranged in a printable grid format (3 per row, 4 per column)
                    </small>
                </div>

                <div class="p-3 mb-3 rounded border bg-light">
                    <h6 class="fw-bold text-dark mb-2">
                        <i class="ti ti-info-circle text-primary me-1"></i> What you will get:
                    </h6>
                    <ul class="mb-3 ps-3" style="line-height: 1.6;">
                        <li>Unique token IDs signed securely using ECDSA (ES256)</li>
                        <li>QR codes automatically generated for each token</li>
                        <li>Ready-to-print PDF file arranged in a 3×4 grid layout</li>
                        <li>Approximately 12 tokens per page for easy printing</li>
                    </ul>
                    <div class="alert alert-warning bg-light text-warning border-0 py-2 mb-0" style="font-size: 13px;">
                        ⚠ <strong>Note:</strong> These tokens are <strong>not assigned</strong> to employees.
                        Use <em>Assign Token</em> later to link them after printing.
                    </div>
                </div>

                <!-- ✅ Loader overlay (only when the generateBulkTokens action is running) -->
                <div wire:loading.flex wire:target="generateBulkTokens"
                     class="position-absolute top-0 start-0 w-100 h-100 justify-content-center align-items-center bg-white bg-opacity-75 rounded"
                     style="z-index: 1000;">
                    <div class="text-center">
                        <div class="spinner-border text-primary mb-2" role="status"
                             style="width: 3rem; height: 3rem;"></div>
                        <p class="fw-semibold text-dark mb-0">Generating QR Tokens...</p>
                        <small class="text-muted">Please wait while we prepare your PDF</small>
                    </div>
                </div>

                <button type="submit"
                        class="btn btn-primary w-100 py-2"
                        wire:loading.attr="disabled"
                        wire:target="generateBulkTokens">
        <span wire:loading.remove wire:target="generateBulkTokens">
            <i class="ti ti-download me-1"></i> Generate & Download Bulk Tokens
        </span>
                    <span wire:loading wire:target="generateBulkTokens">
            <i class="ti ti-loader-2 me-1 ti-spin"></i> Processing...
        </span>
                </button>
            </form>

        </div>
    @endif
</div>

@push('scripts')
    <script>
        document.addEventListener('livewire:load', () => {
            Livewire.on('startGenerating', () => {
                const loader = document.getElementById('qr-loader');
                if (loader) loader.style.display = 'flex';
            });

            Livewire.on('stopGenerating', () => {
                const loader = document.getElementById('qr-loader');
                if (loader) loader.style.display = 'none';
            });
        });
    </script>
@endpush


