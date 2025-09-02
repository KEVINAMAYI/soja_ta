<?php

namespace App\Livewire;

use App\Models\Shift;
use Carbon\Carbon;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;

class ShiftTable extends DataTableComponent
{
    protected $model = Shift::class;


    public function configure(): void
    {
        $this->setPrimaryKey('id')
            ->setDefaultSort('created_at', 'desc');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {

        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Shift::query()->select('shifts.*')
            ->with(['employees'])
            ->where('organization_id', $orgId);

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }


        return $query;
    }

    public function columns(): array
    {

        return [
            Column::make("Name", "name")
                ->searchable()
                ->sortable(),

            Column::make("Time","start_time")
                ->format(function ($value, $row) {
                    return '
            <div class="d-flex justify-content-between">
                <div>
                    <div><small class="text-muted">From</small> <span class="text-primary fw-semibold">' . Carbon::parse($row->start_time)->format('g:i A') . '</span></div>
                    <div><small class="text-muted">To</small> <span class="text-danger fw-semibold">' . Carbon::parse($row->end_time)->format('g:i A') . '</span></div>
                </div>
            </div>';
                })
                ->html()
                ->sortable(),


            Column::make("Employees","start_time")
                ->format(function ($value, $row) {
                    return '<span class="text-danger fw-bold">' . $row->employees->count() . '</span>';
                })
                ->html(),


            Column::make("Break (min)", "break_minutes")
                ->format(fn($value) => '<span class="badge bg-secondary">' . $value . ' min</span>')
                ->html(),

            Column::make("Overtime Rate", "overtime_rate")
                ->format(fn($value) => '<span class="text-success fw-bold">' . number_format($value, 1) . '</span>')
                ->html(),

            Column::make("Status", "status")
                ->format(fn($value) => match ($value) {
                    'active' => '<span class="badge bg-success">Active</span>',
                    'inactive' => '<span class="badge bg-danger">Inactive</span>',
                    default => '<span class="badge bg-secondary">' . ucfirst($value) . '</span>'
                })
                ->html(),

            Column::make("Action")
                ->label(fn($row) => view('livewire.admin.shifts.actions', ['shift' => $row]))
        ];

    }

}

