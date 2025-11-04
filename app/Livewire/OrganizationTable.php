<?php

namespace App\Livewire;

use App\Exports\AttendanceDailyExcelExport;
use App\Exports\ClientsExcelExport;
use App\Exports\ClientsExport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\Organization;

class OrganizationTable extends DataTableComponent
{
    protected $model = Organization::class;

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function columns(): array
    {
        return [

            // 👤 Name (bold and prominent)
            Column::make("Name", "name")
                ->format(fn($value, $row) => "<span class='fw-semibold text-dark'>{$value}</span>")
                ->html()
                ->sortable(),

            // 📧 Email (subtle with icon)
            Column::make("Email", "email")
                ->format(fn($value, $row) => $value
                    ? "<div class='d-flex align-items-center gap-1'>
                        <iconify-icon icon='mdi:email-outline' class='text-info' width='16'></iconify-icon>
                        <a href='mailto:{$value}' class='text-primary text-decoration-none'>{$value}</a>
                   </div>"
                    : '<span class="text-muted">N/A</span>'
                )
                ->html()
                ->sortable(),

            // 📞 Phone (subtle with icon)
            Column::make("Phone", "phone_number")
                ->format(fn($value, $row) => $value
                    ? "<div class='d-flex align-items-center gap-1'>
                        <iconify-icon icon='mdi:phone-outline' class='text-success' width='16'></iconify-icon>
                        <span class='text-dark'>{$value}</span>
                   </div>"
                    : '<span class="text-muted">N/A</span>'
                )
                ->html()
                ->sortable(),

            // 🗓 Created At (formatted nicely)
            Column::make("Created at", "created_at")
                ->format(fn($value) => "<span class='text-muted'>" . $value->format('F d, Y h:i A') . "</span>")
                ->html()
                ->sortable(),

            // ⚙️ Actions (keep existing)
            Column::make('Actions')
                ->label(fn($row) => view('livewire.admin.organizations.actions', ['organization' => $row]))
                ->html(),
        ];
    }


    public function builder(): Builder
    {
        $query = Organization::query()->select('organizations.*');

        if ($this->search !== null && $this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%');
            });
        }

        return $query;
    }

    public function bulkActions(): array
    {
        return [
            'exportExcel' => 'Export Excel',
            'exportPdf' => 'Export PDF'
        ];
    }

    public function exportExcel()
    {
        return Excel::download(new ClientsExcelExport($this->getSelected()), 'clients.xlsx');
    }


    public function exportPdf()
    {
        $ids = $this->getSelected();

        $url = route('clients.export.pdf', ['ids' => $ids]);

        return redirect()->to($url);
    }
}
