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

            Column::make("Start", "start_time")
                ->format(fn($value) => '<span class="text-primary fw-semibold"><i class="bi bi-clock"></i> ' . Carbon::parse($value)->format('g:i A') . '</span>')
                ->html()
                ->sortable(),

            Column::make("End", "end_time")
                ->format(fn($value) => '<span class="text-danger fw-semibold"><i class="bi bi-clock-fill"></i> ' . Carbon::parse($value)->format('g:i A') . '</span>')
                ->html()
                ->sortable(),

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

