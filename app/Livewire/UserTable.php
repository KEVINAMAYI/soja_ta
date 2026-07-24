<?php

namespace App\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

class UserTable extends DataTableComponent
{
    protected $model = Employee::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
        $this->setSearchEnabled();
    }

    public function builder(): Builder
    {
        $orgId = auth()->user()->employee->organization_id ?? null;

        $query = Employee::withSystemUsers()
            ->select('employees.*')
            ->with(['user.roles'])
            ->where('organization_id', $orgId)
            ->where('is_system_user', true);

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('User', 'name')
                ->sortable()
                ->format(function ($value, $row) {
                    $initials = collect(explode(' ', trim($value)))
                        ->filter()
                        ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                        ->take(2)
                        ->implode('');

                    return "
                        <div class='d-flex align-items-center gap-2'>
                            <span class='user-avatar-circle'>{$initials}</span>
                            <div class='d-flex flex-column'>
                                <span class='fw-semibold text-dark'>" . e($value) . "</span>
                                <small class='text-muted'>" . e($row->email) . "</small>
                            </div>
                        </div>";
                })
                ->html(),

            Column::make('Role')
                ->label(function ($row) {
                    $roleName = $row->user?->roles->first()?->name;
                    return $roleName
                        ? "<span class='badge bg-primary fw-semibold'>" . ucwords(str_replace(['-', '_'], ' ', $roleName)) . "</span>"
                        : "<span class='text-muted'>—</span>";
                })
                ->html(),

            Column::make('Status')
                ->label(fn($row) => $row->active
                    ? "<span class='badge bg-success'>Active</span>"
                    : "<span class='badge bg-secondary'>Inactive</span>")
                ->html(),

            Column::make('Created at', 'created_at')
                ->sortable()
                ->format(fn($value) => "<span class='text-muted'>" . $value->format('F d, Y h:i A') . "</span>")
                ->html(),

            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.users.actions', ['user' => $row]))
                ->html(),
        ];
    }
}
