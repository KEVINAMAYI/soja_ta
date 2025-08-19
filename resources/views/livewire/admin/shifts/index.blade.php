<?php

use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;

new class extends Component {

    public $name, $start_time, $end_time, $break_minutes = 0, $overtime_rate = 0, $status = 'active', $notes;
    public $editId;

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_minutes' => 'nullable|integer|min:0',
            'overtime_rate' => 'nullable|numeric|min:0',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
        ];
    }

    public function createShift()
    {
        $this->validate();

        DB::beginTransaction();

        try {
            $orgId = auth()->user()->employee->organization_id ?? null;

            Shift::create([
                'organization_id' => $orgId,
                'name' => $this->name,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'break_minutes' => $this->break_minutes,
                'overtime_rate' => $this->overtime_rate,
                'status' => $this->status,
                'notes' => $this->notes,
            ]);

            DB::commit();

            $this->dispatch('hide-shift-modal');

            LivewireAlert::title('Awesome!')
                ->text('Shift created successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');


        } catch (\Throwable $e) {
            DB::rollBack();

            // Log error for debugging
            Log::error('Shift creation failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);


            LivewireAlert::title('Error!')
                ->text('Something went wrong while creating the shift.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();

        }
    }

    public function editShift($id)
    {
        $shift = Shift::findOrFail($id);
        $this->editId = $id;
        $this->fill($shift->toArray());
        $this->dispatch('show-shift-modal');
    }

    public function updateShift()
    {
        $this->validate();

        DB::beginTransaction();

        try {
            $shift = Shift::findOrFail($this->editId);

            $shift->update(
                $this->only([
                    'name',
                    'start_time',
                    'end_time',
                    'break_minutes',
                    'overtime_rate',
                    'status',
                    'notes'
                ])
            );

            DB::commit();

            $this->dispatch('hide-shift-modal');

            LivewireAlert::title('Awesome!')
                ->text('Shift updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->resetForm();
            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Shift update failed', [
                'error' => $e->getMessage(),
                'shiftId' => $this->editId,
                'user_id' => auth()->id(),
            ]);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while updating the shift.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function deleteShift($id)
    {
        DB::beginTransaction();

        try {
            $shift = Shift::findOrFail($id);
            $shift->delete();

            DB::commit();


            LivewireAlert::title('Awesome!')
                ->text('Shift updated successfully.')
                ->success()
                ->toast()
                ->position('top-end')
                ->show();

            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Shift deletion failed', [
                'error' => $e->getMessage(),
                'shiftId' => $id,
                'user_id' => auth()->id(),
            ]);

            LivewireAlert::title('Error!')
                ->text('Something went wrong while deleting the shift.')
                ->error()
                ->toast()
                ->position('top-end')
                ->show();
        }
    }

    public function resetForm()
    {
        $this->reset(['name', 'start_time', 'end_time', 'break_minutes', 'overtime_rate', 'status', 'notes', 'editId']);
        $this->status = 'active';
    }
}; ?>


<div class="row">
    <div class="col-12">
        <div class="card card-body">
            {{-- Top Bar: Search + Create Button --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                {{-- Left side: Optional Search (if added) --}}
                <div class="mb-2">
                    {{-- Placeholder for filters/search --}}
                </div>

                {{-- Right side: Create Employee button --}}
                <a href="javascript:void(0)" class="btn btn-primary" data-bs-toggle="modal"
                   data-bs-target="#shiftModal">
                    <i class="ti ti-calendar-time fs-5"></i> Add Shift
                </a>

            </div>


            {{-- Livewire Table --}}
            <livewire:shift-table theme="bootstrap-4"/>

        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="shiftModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit.prevent="{{ $editId ? 'updateShift' : 'createShift' }}">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editId ? 'Edit Shift' : 'New Shift' }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">
                        <!-- Shift Name -->
                        <div class="col-md-12">
                            <label class="form-label">Shift Name</label>
                            <input type="text" wire:model="name" class="form-control" placeholder="Shift Name">
                            @error('name') <small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <!-- Start Time -->
                        <div class="col-md-6">
                            <label class="form-label">Start Time</label>
                            <input type="time" wire:model="start_time" class="form-control">
                        </div>

                        <!-- End Time -->
                        <div class="col-md-6">
                            <label class="form-label">End Time</label>
                            <input type="time" wire:model="end_time" class="form-control">
                        </div>

                        <!-- Break Minutes -->
                        <div class="col-md-6">
                            <label class="form-label">Break (Minutes)</label>
                            <input type="number" wire:model="break_minutes" class="form-control" placeholder="e.g. 30">
                        </div>

                        <!-- Overtime Rate -->
                        <div class="col-md-6">
                            <label class="form-label">Overtime Rate</label>
                            <input type="number" wire:model="overtime_rate" class="form-control" placeholder="e.g. 1.5">
                        </div>

                        <!-- Status -->
                        <div class="col-md-12">
                            <label class="form-label">Status</label>
                            <select wire:model="status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- Notes (full width) -->
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea wire:model="notes" class="form-control" placeholder="Additional notes"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">{{ $editId ? 'Update' : 'Add' }}</button>
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal"
                                wire:click="resetForm">Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

@push('scripts')
    <script>
        window.addEventListener('show-shift-modal', () => {
            new bootstrap.Modal(document.getElementById('shiftModal')).show();
        });

        window.addEventListener('hide-shift-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('shiftModal'))?.hide();
        });
    </script>
@endpush

