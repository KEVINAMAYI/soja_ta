<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\On;
use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\AttendanceBreakLog;
use Illuminate\Support\Facades\DB;

class AttendanceBreakTable extends DataTableComponent
{
    protected $model = AttendanceBreakLog::class;

    public $startDate;
    public $endDate;

    public function mount()
    {
        $this->startDate = now()->toDateString();
        $this->endDate   = now()->toDateString();
    }

    #[On('break-date-range-updated')]
    public function filterByDateRange($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
        $this->dispatch('refreshDatatable');
    }

    public function configure(): void
    {
        $this->setPrimaryKey('id');
    }

    public function builder(): \Illuminate\Database\Eloquent\Builder
    {
        $orgId     = auth()->user()->employee->organization_id ?? null;
        $startDate = $this->startDate ?: now()->toDateString();
        $endDate   = $this->endDate   ?: $startDate;
        $search    = $this->search;

        $query = AttendanceBreakLog::query()
            ->select('attendance_break_logs.*')
            ->with([
                'attendance',
                'attendance.employee',
                'attendance.employee.department',
                'shiftBreak',
            ])
            ->whereHas('attendance.employee', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)->where('active', 1);
            })
            ->whereHas('attendance', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate]);
            });

        if ($search) {
            $query->whereHas('attendance.employee', fn($q) =>
            $q->where('name', 'like', "%$search%")
            );
        }

        $query->orderByDesc(
            DB::raw('(SELECT date FROM attendances WHERE attendances.id = attendance_break_logs.attendance_id)')
        )
            ->orderByDesc('break_start_time');

        return $query;
    }

    public function formatMinutes(?int $minutes): string
    {
        if (!$minutes || $minutes <= 0) return '0m';
        if ($minutes < 60) return $minutes . 'm';

        $hours = floor($minutes / 60);
        $mins  = $minutes % 60;

        return $mins === 0 ? "{$hours}h" : "{$hours}h {$mins}m";
    }

    public function columns(): array
    {
        return [

            // Date
            Column::make('Date')
                ->label(function ($row) {
                    $date = $row->attendance?->date;
                    if (!$date) return '<span class="text-muted">—</span>';

                    $parsed = Carbon::parse($date);
                    return "<span class='fw-semibold' style='font-size:0.85rem;'>{$parsed->format('d M Y')}</span>"
                        . "<br><small class='text-muted'>{$parsed->format('l')}</small>";
                })
                ->html(),

            // Employee
            Column::make('Employee')
                ->label(fn($row) => view('livewire.admin.attendance.employee', [
                    'attendance' => $row->attendance,
                ])),

            // Break Type — name, allowed duration, and the permitted window
            Column::make('Break Type')
                ->label(function ($row) {
                    $shiftBreak = $row->shiftBreak;

                    $icon = "<i class='ti ti-coffee me-1'></i>";

                    if (!$shiftBreak) {
                        return "{$icon}<span class='fw-semibold'>Manual Break</span>"
                            . "<br><small class='text-muted'>No limit set</small>";
                    }

                    $allowed = $this->formatMinutes($shiftBreak->duration_minutes ?? null);

                    $windowStart = $shiftBreak->window_start_time
                        ? Carbon::parse($shiftBreak->window_start_time)->format('g:i A')
                        : null;
                    $windowEnd = $shiftBreak->window_end_time
                        ? Carbon::parse($shiftBreak->window_end_time)->format('g:i A')
                        : null;

                    $html = "{$icon}<span class='fw-semibold'>{$shiftBreak->name}</span>"
                        . "<br><small class='text-muted'>Limit: {$allowed}</small>";

                    if ($windowStart && $windowEnd) {
                        $html .= "<br><small class='text-muted'>"
                            . "<i class='ti ti-clock-hour-4 me-1'></i>"
                            . "Window: {$windowStart} – {$windowEnd}</small>";
                    }

                    return $html;
                })
                ->html(),

            // Break Window — the actual time range the employee was on break
            Column::make('Break Window')
                ->label(function ($row) {
                    $start = $row->break_start_time
                        ? Carbon::parse($row->break_start_time)->format('g:i A')
                        : '—';

                    if ($row->status === 'in_progress') {
                        return "<i class='ti ti-clock-play me-1 text-warning'></i>"
                            . "<span class='fw-semibold'>{$start}</span>"
                            . " <span class='text-muted' style='font-size:0.8rem;'>→</span> "
                            . "<span class='text-muted fst-italic' style='font-size:0.82rem;'>ongoing</span>"
                            . "<br><span style='background:#fff3cd; color:#92400e; padding:2px 8px;"
                            . " border-radius:20px; font-size:0.68rem; font-weight:700;"
                            . " margin-top:4px; display:inline-block;'>☕ Still on break</span>";
                    }

                    $end = $row->break_end_time
                        ? Carbon::parse($row->break_end_time)->format('g:i A')
                        : '—';

                    return "<i class='ti ti-clock me-1 text-muted'></i>"
                        . "<span class='fw-semibold'>{$start}</span>"
                        . " <span class='text-muted' style='font-size:0.8rem;'>→</span> "
                        . "<span class='fw-semibold'>{$end}</span>";
                })
                ->html(),

            // Duration — actual taken + limit + overage
            Column::make('Duration')
                ->label(function ($row) {
                    if ($row->status === 'in_progress') {
                        $elapsed = (int) Carbon::parse($row->break_start_time)->diffInMinutes(now());
                        return "<span class='fw-semibold text-warning'>{$this->formatMinutes($elapsed)}</span>"
                            . "<br><small class='text-muted'>ongoing</small>";
                    }

                    $actual  = $row->actual_duration_minutes;
                    $excess  = $row->excess_minutes;
                    $allowed = $row->shiftBreak?->duration_minutes;

                    $html = "<span class='fw-semibold'>{$this->formatMinutes($actual)}</span>";

                    if ($allowed) {
                        $html .= "<br><small class='text-muted'>Limit: {$this->formatMinutes($allowed)}</small>";
                    }

                    if ($excess && $excess > 0) {
                        $html .= "<br><small style='color:#ef4444; font-weight:600;'>"
                            . "<i class='ti ti-alert-triangle me-1'></i>+{$this->formatMinutes($excess)} over</small>";
                    }

                    return $html;
                })
                ->html(),

            // Status
            Column::make('Status')
                ->label(function ($row) {
                    if ($row->status === 'in_progress') {
                        return "<span style='background:#fff3cd; color:#92400e; padding:5px 12px;"
                            . " border-radius:20px; font-size:0.72rem; font-weight:700;"
                            . " white-space:nowrap;'>"
                            . "<i class='ti ti-coffee me-1'></i>ON BREAK</span>";
                    }

                    if ($row->is_compliant) {
                        return "<span style='background:#d1fae5; color:#065f46; padding:5px 12px;"
                            . " border-radius:20px; font-size:0.72rem; font-weight:700;"
                            . " white-space:nowrap;'>"
                            . "<i class='ti ti-circle-check me-1'></i>COMPLIANT</span>";
                    }

                    return "<span style='background:#fee2e2; color:#991b1b; padding:5px 12px;"
                        . " border-radius:20px; font-size:0.72rem; font-weight:700;"
                        . " white-space:nowrap;'>"
                        . "<i class='ti ti-alert-circle me-1'></i>OVER LIMIT</span>";
                })
                ->html(),

        ];
    }
}
