<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Attendance;
use App\Models\AttendanceBreakLog;
use App\Models\Overtime;
use App\Models\ZKBioSyncLog;
use App\Services\ZKBioAttendanceSyncService;
use App\Services\ZKPunchClassifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncZKBioAttendance extends Command
{
    protected $signature = 'zkbio:sync
                            {--date=today   : Date to sync YYYY-MM-DD or "today"}
                            {--full         : Full day sync — ignores last sync checkpoint}
                            {--from=        : Custom start datetime YYYY-MM-DD HH:MM:SS}
                            {--to=          : Custom end datetime YYYY-MM-DD HH:MM:SS}';

    protected $description = 'Sync ZKBio punch transactions and write attendance records';

    /**
     * Status priority — we never downgrade a status once written.
     */
    const STATUS_PRIORITY = [
        'absent' => 0,
        'clocked_in' => 1,
        'on_break' => 2,
        'clocked_out' => 3,
    ];

    public function __construct(
        protected ZKBioAttendanceSyncService $zkbio,
        protected ZKPunchClassifier          $classifier
    )
    {
        parent::__construct();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Entry point
    // ──────────────────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $date = $this->option('date') === 'today'
            ? now()->toDateString()
            : $this->option('date');

        [$startDatetime, $endDatetime, $mode] = $this->resolveWindow($date);

        $this->info('=== ZKBio Sync ===');
        $this->info("Date   : {$date}");
        $this->info("Mode   : {$mode}");
        $this->info("Window : {$startDatetime}  →  {$endDatetime}");
        $this->line('');

        $transactions = $this->zkbio->pullForDateRange($startDatetime, $endDatetime);
        $this->info('Transactions pulled : ' . count($transactions));

        if (empty($transactions)) {
            $this->info('No new transactions. Nothing to process.');
            $this->saveCheckpoint($date, $endDatetime);
            return;
        }

        $grouped = $this->zkbio->groupByEmployee($transactions);
        $this->info('Employees with new punches : ' . count($grouped));
        $this->line('');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($grouped as $pin => $employeeData) {

            $employee = Employee::where('zkbio_pin', $pin)
                ->with('shift.breaks')
                ->first();

            if (!$employee) {
                $this->warn("  PIN {$pin} ({$employeeData['name']}) — not mapped to any employee, skipping.");
                Log::warning("ZKBio PIN {$pin} not mapped.", ['name' => $employeeData['name']]);
                $skipped++;
                continue;
            }

            // Always pull the full day's punches so the classifier has the
            // complete picture regardless of incremental vs full sync mode.
            $allPunches = $this->zkbio->getAllPunchesForEmployee($pin, $date);

            $classified = $this->classifier->classify($allPunches, $employee, $date);

            // ── Console output ────────────────────────────────────────────────
            $flags = [];
            if ($classified['incomplete']) $flags[] = '⚠  Incomplete';
            if ($classified['check_out_synthetic']) $flags[] = '🤖 Auto clock-out';
            if ($classified['late_checkin']) $flags[] = "🕐 Late +{$classified['minutes_late']}min";
            if ($classified['early_checkout']) $flags[] = "🏃 Early -{$classified['minutes_early']}min";

            $unscheduledCount = collect($classified['segments'])
                ->where('type', 'unscheduled_leave')
                ->count();
            if ($unscheduledCount > 0) {
                $flags[] = "🚶 {$unscheduledCount} unscheduled";
            }

            $breakCount = collect($classified['segments'])
                ->where('type', 'break')
                ->count();
            if ($breakCount > 0) {
                $flags[] = "☕ {$breakCount} break(s)";
            }

            $this->line(sprintf(
                '  [%s] %-20s | Punches: %d raw / %d filtered | Scenario: %-40s | %s',
                $pin,
                $employee->name,
                $classified['raw_count'],
                $classified['filtered_count'],
                $classified['scenario'],
                $flags ? implode('  ', $flags) : '✓ Complete'
            ));

            foreach ($classified['notes'] as $note) {
                $this->line("         → {$note}");
            }

            // ── Persist ───────────────────────────────────────────────────────
            DB::beginTransaction();
            try {
                $this->saveAttendance($employee, $date, $classified);
                DB::commit();
                $processed++;
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("ZKBio save failed for PIN {$pin}", ['error' => $e->getMessage()]);
                $this->error("  Failed PIN {$pin}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->saveCheckpoint($date, $endDatetime);

        $this->line('');
        $this->info("=== Done : {$processed} processed  |  {$skipped} skipped  |  {$failed} failed ===");
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // PASTE THIS into SyncZKBioAttendance — replaces the existing saveAttendance()
    // ══════════════════════════════════════════════════════════════════════════════
    private function saveAttendance(Employee $employee, string $date, array $c): void
    {
        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $isNew = !$attendance->exists;

        // Never downgrade a fully clocked-out record unless fresh checkout data arrived.
        if (!$isNew && $attendance->status === 'clocked_out' && !$c['check_out'] && $c['scenario'] !== 'checkin_only') {
            Log::info('Skipping update — already clocked out, no new checkout.', [
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
            return;
        }

        $shift = $employee->shift;

        // ── Check-in (written once, never overwritten) ────────────────────────────
        if ($c['check_in'] && !$attendance->check_in_time) {
            $attendance->check_in_time = $c['check_in'];
            $attendance->is_late_checkin = $c['late_checkin'];
            $attendance->minutes_late = $c['minutes_late'];
            $attendance->within_grace_period = !$c['late_checkin'];
            $attendance->status = 'clocked_in';

            if ($shift) {
                $shiftStart = Carbon::parse($date . ' ' . Carbon::parse($shift->start_time)->format('H:i:s'));
                $shiftEnd = Carbon::parse($date . ' ' . Carbon::parse($shift->end_time)->format('H:i:s'));
                if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay();

                $gracePeriod = $shift->grace_period_enabled ? ($shift->grace_period_minutes ?? 0) : 0;
                $earlyThreshold = $shift->early_checkout_threshold_minutes ?? 0;

                $attendance->expected_check_in_time = $shiftStart;
                $attendance->grace_period_end_time = $shiftStart->copy()->addMinutes($gracePeriod);
                $attendance->expected_check_out_time = $shiftEnd;
                $attendance->early_checkout_threshold_time = $shiftEnd->copy()->subMinutes($earlyThreshold);
            }
        }

        // ── Check-out ─────────────────────────────────────────────────────────────
        if ($c['check_out']) {
            $attendance->check_out_time = $c['check_out'];
            $attendance->auto_clocked_out = $c['check_out_synthetic'];
            $attendance->auto_clocked_out_reason = $c['check_out_synthetic']
                ? "No clock-out punch recorded — auto-closed at shift end [{$c['scenario']}]"
                : null;
            $attendance->is_early_checkout = $c['early_checkout'];
            $attendance->minutes_early = $c['minutes_early'];
            $attendance->worked_hours = $c['worked_hours'];
            $attendance->overtime_hours = $c['overtime_hours'];
            $attendance->is_late_checkout = $c['overtime_hours'] > 0;
            $attendance->late_checkout_hours = $c['overtime_hours'] > 0 ? $c['overtime_hours'] : 0.00;
            $attendance->status = 'clocked_out';
        }

        // ── Break summary ─────────────────────────────────────────────────────────
        $segments = collect($c['segments']);
        $breakSegments = $segments->where('type', 'break');

        $attendance->break_count = $breakSegments->count();
        $attendance->total_break_minutes = (int)$breakSegments->sum('duration_minutes');
        $attendance->paid_break_minutes = (int)$breakSegments->where('paid', true)->sum('duration_minutes');
        $attendance->excess_break_minutes = max(
            0,
            $attendance->total_break_minutes - $attendance->paid_break_minutes
        );

        $lastSeg = $segments->last();
        $attendance->is_break_checkout = $lastSeg
            && $lastSeg['type'] === 'break'
            && $lastSeg['in'] === null;

        // ── Scenario + incomplete ─────────────────────────────────────────────────
        $attendance->scenario = $c['scenario'];
        $attendance->incomplete = $c['incomplete'];

        // ── Status ───────────────────────────────────────────────────────────────
        $newStatus = $this->scenarioToStatus($c['scenario']);
        $currentPriority = self::STATUS_PRIORITY[$attendance->status ?? 'absent'] ?? 0;
        $newPriority = self::STATUS_PRIORITY[$newStatus] ?? 0;

        // Employee returned after an intermediate departure — clear stale checkout
        if ($c['scenario'] === 'checkin_only') {
            $attendance->status = 'clocked_in';
            $attendance->check_out_time = null;
            $attendance->auto_clocked_out = false;
            $attendance->auto_clocked_out_reason = null;
            $attendance->is_early_checkout = false;
            $attendance->minutes_early = 0;
        } elseif ($newPriority >= $currentPriority || $c['check_out']) {
            $attendance->status = $newStatus;
        }

        $attendance->save();

        // ── Segments → AttendanceBreakLog ─────────────────────────────────────────
        foreach ($c['segments'] as $seg) {

            if ($seg['type'] === 'checkout') continue;

            $isBreak = $seg['type'] === 'break';
            $isCompleted = $seg['in'] !== null;
            $shiftBreakId = null;
            $allowedDuration = null;

            if ($isBreak && $shift) {
                $today = now()->toDateString();
                foreach ($shift->breaks ?? [] as $shiftBreak) {
                    if (!$shiftBreak->is_active || !$shiftBreak->window_start_time) continue;

                    $windowStart = Carbon::parse(
                        $today . ' ' . Carbon::parse($shiftBreak->window_start_time)->format('H:i:s')
                    );

                    if ($seg['out']->between(
                        $windowStart->copy()->subMinutes(20),
                        $windowStart->copy()->addMinutes(20)
                    )) {
                        $shiftBreakId = $shiftBreak->id;
                        $allowedDuration = $shiftBreak->duration_minutes ?? null;
                        break;
                    }
                }
            }

            $excessMinutes = 0;
            $isCompliant = false;

            if ($isBreak && $isCompleted) {
                if ($allowedDuration !== null) {
                    $excessMinutes = max(0, $seg['duration_minutes'] - $allowedDuration);
                    $isCompliant = $excessMinutes === 0;
                } else {
                    $excessMinutes = 0;
                    $isCompliant = true;
                }
            }

            $notes = match (true) {
                !$isCompleted && $isBreak
                => 'Break started but employee did not return — checkout recorded without return punch.',
                !$isCompleted && !$isBreak
                => 'Employee left the premises and did not return.',
                !$isBreak
                => "Unscheduled absence of {$seg['duration_minutes']} min (left {$seg['out']->format('H:i')}, returned {$seg['in']->format('H:i')}).",
                $isCompliant
                => null,
                $excessMinutes > 0
                => "Break exceeded allowed duration by {$excessMinutes} min (took {$seg['duration_minutes']} min, allowed {$allowedDuration} min).",
                default => null,
            };

            AttendanceBreakLog::updateOrCreate(
                [
                    'attendance_id' => $attendance->id,
                    'break_start_time' => $seg['out'],
                ],
                [
                    'shift_break_id' => $shiftBreakId,
                    'type' => $seg['type'],
                    'is_auto_detected' => true,
                    'break_end_time' => $seg['in'],
                    'actual_duration_minutes' => $seg['duration_minutes'],
                    'excess_minutes' => $excessMinutes,
                    'is_compliant' => $isCompliant,
                    'is_taken' => true,
                    'status' => $isCompleted ? 'completed' : 'in_progress',
                    'notes' => $notes,
                ]
            );
        }

        // ── Overtime record ───────────────────────────────────────────────────────
        if ($c['overtime_hours'] > 0 && $c['check_out'] && !$c['check_out_synthetic'] && $shift) {
            Overtime::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance->id,
                    'date' => $date,
                ],
                [
                    'start_time' => Carbon::parse($date . ' ' . Carbon::parse($shift->end_time)->format('H:i:s')),
                    'end_time' => $c['check_out'],
                    'hours' => $c['overtime_hours'],
                    'reason' => 'Auto-calculated from ZKBio sync',
                ]
            );
        }

        // ── HR audit logs ─────────────────────────────────────────────────────────
        if ($c['check_out_synthetic']) {
            Log::warning('Synthetic clock-out applied.', [
                'employee_id' => $employee->id,
                'date' => $date,
                'auto_checkout_time' => $c['check_out']->format('H:i'),
                'scenario' => $c['scenario'],
            ]);
        }

        if ($c['late_checkin']) {
            Log::info('Late clock-in recorded.', [
                'employee_id' => $employee->id,
                'date' => $date,
                'minutes_late' => $c['minutes_late'],
                'checked_in' => $c['check_in']->format('H:i'),
            ]);
        }

        if ($c['early_checkout']) {
            Log::info('Early clock-out recorded.', [
                'employee_id' => $employee->id,
                'date' => $date,
                'minutes_early' => $c['minutes_early'],
                'checked_out' => $c['check_out']->format('H:i'),
            ]);
        }

        $unscheduled = $segments->where('type', 'unscheduled_leave');
        if ($unscheduled->isNotEmpty()) {
            Log::warning('Unscheduled absences detected.', [
                'employee_id' => $employee->id,
                'date' => $date,
                'segments' => $unscheduled->map(fn($s) => [
                    'out' => $s['out']->format('H:i'),
                    'in' => $s['in']?->format('H:i') ?? 'never returned',
                    'duration' => $s['duration_minutes'] ?? 'unknown',
                ])->values()->all(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveWindow(string $date): array
    {
        if ($this->option('from') && $this->option('to')) {
            return [$this->option('from'), $this->option('to'), 'custom'];
        }

        if ($this->option('full')) {
            return ["{$date} 00:00:00", "{$date} 23:59:59", 'full'];
        }

        $checkpoint = ZKBioSyncLog::where('sync_date', $date)
            ->orderBy('synced_until', 'desc')
            ->first();

        $start = $checkpoint
            ? Carbon::parse($checkpoint->synced_until)->subMinutes(2)->format('Y-m-d H:i:s')
            : "{$date} 00:00:00";

        return [$start, now()->format('Y-m-d H:i:s'), 'incremental'];
    }

    private function saveCheckpoint(string $date, string $until): void
    {
        ZKBioSyncLog::create([
            'sync_date' => $date,
            'synced_until' => $until,
            'synced_at' => now(),
        ]);
    }

    /**
     * Map a classifier scenario to an Attendance status value.
     *
     * Scenario naming convention (from ZKPunchClassifier):
     *   complete*            → clocked_out  (any complete variant)
     *   synthetic*           → clocked_out  (auto-closed; still counts as out)
     *   checkin_only         → clocked_in
     *   no_shift             → clocked_in   (punched in but no shift to verify against)
     *   no_checkin           → absent
     *   not_scheduled        → absent
     *   no_punches           → absent
     *   unknown              → clocked_in   (safe fallback)
     */
    private function scenarioToStatus(string $scenario): string
    {
        // All 'complete_*' and 'synthetic_*' variants → clocked_out
        if (str_starts_with($scenario, 'complete') || str_starts_with($scenario, 'synthetic')) {
            return 'clocked_out';
        }

        return match ($scenario) {
            'checkin_only',
            'no_shift' => 'clocked_in',

            'no_checkin',
            'not_scheduled',
            'no_punches' => 'absent',

            default => 'clocked_in',
        };
    }
}
