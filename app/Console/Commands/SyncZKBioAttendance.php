<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceBreakLog;
use App\Models\Employee;
use App\Models\Overtime;
use App\Models\ZKBioSyncLog;
use App\Services\InterpretationService;
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

    const STATUS_PRIORITY = [
        'absent'      => 0,
        'clocked_in'  => 1,
        'on_break'    => 2,
        'clocked_out' => 3,
    ];

    // Punches before this hour (06:00) belong to the PREVIOUS day's night shift
    const OVERNIGHT_BOUNDARY_HOUR = 6;

    public function __construct(
        protected ZKBioAttendanceSyncService $zkbio,
        protected ZKPunchClassifier          $classifier,
        protected InterpretationService      $interpretation
    ) {
        parent::__construct();
    }

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
        $skipped   = 0;
        $failed    = 0;

        foreach ($grouped as $pin => $employeeData) {

            $employee = Employee::where('zkbio_pin', $pin)
                ->with(['shifts.breaks', 'shift.breaks'])
                ->first();

            if (!$employee) {
                $this->warn("  PIN {$pin} ({$employeeData['name']}) — not mapped, skipping.");
                Log::warning("ZKBio PIN {$pin} not mapped.", ['name' => $employeeData['name']]);
                $skipped++;
                continue;
            }

            // Pull full day's punches from ZKBio
            $allPunches = $this->zkbio->getAllPunchesForEmployee($pin, $date);

            if (empty($allPunches)) {
                $skipped++;
                continue;
            }

            // =========================================================
            // OVERNIGHT SPLIT
            //
            // Night shifts span two calendar days, e.g. 17:30 → 05:00.
            // When syncing a given date, punches for that date may contain:
            //
            //   1. Early-morning punches (00:00–05:59) — these are
            //      clock-OUTs from the PREVIOUS day's night shift.
            //
            //   2. Evening/daytime punches (06:00–23:59) — these
            //      belong to TODAY's shift (day or night clock-IN).
            //
            // We split them and process each group against the correct
            // attendance date so they never collide.
            // =========================================================
            $overnightPunches = array_values(array_filter($allPunches, function ($p) {
                return (int) Carbon::parse($p)->format('H') < self::OVERNIGHT_BOUNDARY_HOUR;
            }));

            $todayPunches = array_values(array_filter($allPunches, function ($p) {
                return (int) Carbon::parse($p)->format('H') >= self::OVERNIGHT_BOUNDARY_HOUR;
            }));

            // ── OVERNIGHT CLOCK-OUT → save against YESTERDAY ─────────
            if (!empty($overnightPunches)) {
                $yesterday        = Carbon::parse($date)->subDay()->toDateString();
                $yesterdayPunches = $this->zkbio->getAllPunchesForEmployee($pin, $yesterday);

                // Only keep yesterday's evening punches (>= 06:00) so we
                // don't double-count any early-morning punches from yesterday
                $yesterdayEveningPunches = array_values(array_filter($yesterdayPunches, function ($p) {
                    return (int) Carbon::parse($p)->format('H') >= self::OVERNIGHT_BOUNDARY_HOUR;
                }));

                // Merge: yesterday's clock-in punches + today's early-morning clock-out
                $mergedPunches = array_values(array_unique(
                    array_merge($yesterdayEveningPunches, $overnightPunches)
                ));
                sort($mergedPunches);

                $this->line("  [{$pin}] {$employee->name} — overnight punch(es) redirected to {$yesterday}");

                $classified = $this->classifier->classify($mergedPunches, $employee, $yesterday);

                $this->printClassifiedLine($pin, $employee->name, $classified);

                DB::beginTransaction();
                try {
                    $this->saveAttendance($employee, $yesterday, $classified);
                    DB::commit();
                    $processed++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("ZKBio overnight save failed for PIN {$pin}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $this->error("  Failed overnight PIN {$pin}: " . $e->getMessage());
                    $failed++;
                }
            }

            // ── TODAY'S PUNCHES → save against TODAY ─────────────────
            if (empty($todayPunches)) {
                // All punches were overnight — nothing for today itself
                if (empty($overnightPunches)) $skipped++;
                continue;
            }

            $classified = $this->classifier->classify($todayPunches, $employee, $date);

            $this->printClassifiedLine($pin, $employee->name, $classified);

            DB::beginTransaction();
            try {
                $this->saveAttendance($employee, $date, $classified);
                DB::commit();
                $processed++;
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("ZKBio save failed for PIN {$pin}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("  Failed PIN {$pin}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->saveCheckpoint($date, $endDatetime);

        $this->line('');
        $this->info("=== Done : {$processed} processed  |  {$skipped} skipped  |  {$failed} failed ===");
    }

    // =========================================================================
    // Console output helper
    // =========================================================================
    private function printClassifiedLine(string $pin, string $name, array $classified): void
    {
        $flags = [];
        if ($classified['incomplete'])                        $flags[] = '⚠  Incomplete';
        if ($classified['check_out_synthetic'] ?? false)     $flags[] = '🤖 Auto clock-out';
        if ($classified['missed_checkin_punch'] ?? false)    $flags[] = '🔴 Missed clock-IN';
        if ($classified['missed_checkout_punch'] ?? false)   $flags[] = '🔴 Missed clock-OUT';
        if ($classified['late_checkin'])                     $flags[] = "🕐 Late +{$classified['minutes_late']}min";
        if ($classified['early_checkout'])                   $flags[] = "🚪 Early -{$classified['minutes_early']}min";
        if ($classified['missed_break_return'])              $flags[] = '❓ Missed break punch';
        if (($classified['lost_minutes'] ?? 0) > 0)         $flags[] = "⏳ Lost {$classified['lost_minutes']}min";
        if (($classified['ot1_hours'] ?? 0) > 0)            $flags[] = "💰 OT1 {$classified['ot1_hours']}h";
        if (($classified['ot2_hours'] ?? 0) > 0)            $flags[] = "💰 OT2 {$classified['ot2_hours']}h";
        if (($classified['overtime_hours'] ?? 0) > 0)       $flags[] = "💰 OT {$classified['overtime_hours']}h";

        $breakCount = collect($classified['segments'])->where('type', 'break')->count();
        if ($breakCount > 0) $flags[] = "☕ {$breakCount} break(s)";

        $this->line(sprintf(
            '  [%s] %-22s | %d raw / %d filtered | %-38s | %s',
            $pin,
            $name,
            $classified['raw_count'],
            $classified['filtered_count'],
            $classified['scenario'],
            $flags ? implode('  ', $flags) : '✅ Complete'
        ));

        foreach ($classified['notes'] as $note) {
            $this->line("         → {$note}");
        }
    }

    // =========================================================================
    // saveAttendance
    // =========================================================================
    private function saveAttendance(Employee $employee, string $date, array $c): void
    {
        $attendance = Attendance::firstOrNew([
            'employee_id' => $employee->id,
            'date'        => $date,
        ]);

        $isNew = !$attendance->exists;

        if (!$isNew
            && $attendance->status === 'clocked_out'
            && !$c['check_out']
            && $c['scenario'] !== 'checkin_only'
        ) {
            Log::info('Skipping update — already clocked out, no new checkout.', [
                'employee_id' => $employee->id,
                'date'        => $date,
            ]);
            return;
        }

        $firstPunch = $c['check_in'] ?? Carbon::parse($date . ' 00:00:00');
        $shift      = $this->classifier->resolveShift($employee, $firstPunch, $date);

        // Clock-in
        if ($c['check_in'] && !$attendance->check_in_time) {
            $attendance->check_in_time       = $c['check_in'];
            $attendance->is_late_checkin     = $c['late_checkin'];
            $attendance->minutes_late        = $c['minutes_late'];
            $attendance->within_grace_period = !$c['late_checkin'];
            $attendance->status              = 'clocked_in';
            $attendance->shift_id            = $shift?->id;

            if ($shift) {
                $shiftStart     = $shift->getEffectiveStartTime($date);
                $shiftEnd       = $shift->getEffectiveEndTime($date);
                $graceMins      = $shift->grace_period_enabled ? ($shift->grace_period_minutes ?? 0) : 0;
                $earlyThreshold = $shift->early_checkout_threshold_minutes ?? 0;

                $attendance->expected_check_in_time        = $shiftStart;
                $attendance->grace_period_end_time         = $shiftStart->copy()->addMinutes($graceMins);
                $attendance->expected_check_out_time       = $shiftEnd;
                $attendance->early_checkout_threshold_time = $shiftEnd->copy()->subMinutes($earlyThreshold);
            }
        }

        // Clock-out
        if ($c['check_out']) {
            $attendance->check_out_time          = $c['check_out'];
            $attendance->auto_clocked_out        = $c['check_out_synthetic'];
            $attendance->auto_clocked_out_reason = $c['check_out_synthetic']
                ? "No clock-out punch — auto-closed at shift end [{$c['scenario']}]"
                : null;
            $attendance->is_early_checkout   = $c['early_checkout'];
            $attendance->minutes_early       = $c['minutes_early'];
            $attendance->worked_hours        = $c['worked_hours'];
            $attendance->overtime_hours      = $c['overtime_hours'];
            $attendance->ot1_hours           = $c['ot1_hours'];
            $attendance->ot2_hours           = $c['ot2_hours'];
            $attendance->is_late_checkout    = $c['overtime_hours'] > 0;
            $attendance->late_checkout_hours = $c['overtime_hours'] > 0 ? $c['overtime_hours'] : 0.00;
            $attendance->status              = 'clocked_out';
        }

        $attendance->defined_hours = 9.0;

        // Break summary
        $segments      = collect($c['segments']);
        $breakSegments = $segments->where('type', 'break');

        $attendance->break_count          = $breakSegments->count();
        $attendance->total_break_minutes  = (int) $breakSegments->sum('duration_minutes');
        $attendance->paid_break_minutes   = (int) $breakSegments->where('paid', true)->sum('duration_minutes');
        $attendance->excess_break_minutes = max(0, $attendance->total_break_minutes - $attendance->paid_break_minutes);

        $lastSeg                       = $segments->last();
        $attendance->is_break_checkout = $lastSeg && $lastSeg['type'] === 'break' && $lastSeg['in'] === null;

        $attendance->lost_minutes              = $c['lost_minutes'];
        $attendance->late_checkin_lost_minutes = $c['late_checkin_lost_minutes'];
        $attendance->break_lost_minutes        = $c['break_lost_minutes'];
        $attendance->enforced_break_minutes    = $c['enforced_break_minutes'];
        $attendance->break_enforced            = $c['break_enforced'];
        $attendance->missed_break_return       = $c['missed_break_return'];
        $attendance->lost_hours_breakdown      = !empty($c['lost_hours_breakdown'])
            ? implode(' | ', $c['lost_hours_breakdown'])
            : null;

        $attendance->scenario   = $c['scenario'];
        $attendance->incomplete = $c['incomplete'];

        // Missed punch flags — flag only, never override with fake data
        if ($c['missed_checkin_punch'] ?? false) {
            $attendance->exception_note = trim(($attendance->exception_note ?? '') . ' | Missed clock-IN punch — HR review required.', ' |');
        }
        if ($c['missed_checkout_punch'] ?? false) {
            $attendance->exception_note = trim(($attendance->exception_note ?? '') . ' | Missed clock-OUT punch — HR review required.', ' |');
        }

        $newStatus       = $this->scenarioToStatus($c['scenario']);
        $currentPriority = self::STATUS_PRIORITY[$attendance->status ?? 'absent'] ?? 0;
        $newPriority     = self::STATUS_PRIORITY[$newStatus] ?? 0;

        if ($c['scenario'] === 'checkin_only') {
            $attendance->status                  = 'clocked_in';
            $attendance->check_out_time          = null;
            $attendance->auto_clocked_out        = false;
            $attendance->auto_clocked_out_reason = null;
            $attendance->is_early_checkout       = false;
            $attendance->minutes_early           = 0;
        } elseif ($newPriority >= $currentPriority || $c['check_out']) {
            $attendance->status = $newStatus;
        }

        $attendance->save();

        $attendance->interpretation = $this->interpretation->interpret($attendance);
        $attendance->save();

        // Break logs
        foreach ($c['segments'] as $seg) {
            if ($seg['type'] === 'checkout') continue;

            $isBreak      = $seg['type'] === 'break';
            $isCompleted  = $seg['in'] !== null;
            $isMissed     = $seg['missed_punch'] ?? false;
            $shiftBreakId = null;
            $allowedDur   = null;

            if ($isBreak && $shift) {
                foreach ($shift->breaks ?? [] as $shiftBreak) {
                    if (!$shiftBreak->is_active || !$shiftBreak->window_start_time) continue;
                    $windowStart = Carbon::parse($date . ' ' . Carbon::parse($shiftBreak->window_start_time)->format('H:i:s'));
                    if ($seg['out']->between($windowStart->copy()->subMinutes(20), $windowStart->copy()->addMinutes(20))) {
                        $shiftBreakId = $shiftBreak->id;
                        $allowedDur   = $shiftBreak->duration_minutes ?? null;
                        break;
                    }
                }
            }

            $excessMinutes = 0;
            $isCompliant   = false;

            if ($isBreak && $isCompleted) {
                if ($allowedDur !== null) {
                    $excessMinutes = max(0, $seg['duration_minutes'] - $allowedDur);
                    $isCompliant   = $excessMinutes === 0;
                } else {
                    $isCompliant = true;
                }
            }

            $notes = match (true) {
                $isMissed                  => 'Missed punch — went on break but no return punch recorded. NOT overridden.',
                !$isCompleted && $isBreak  => 'Break started but no return punch before clock-out.',
                !$isCompleted && !$isBreak => 'Employee left and did not return.',
                !$isBreak                  => "Unscheduled absence of {$seg['duration_minutes']} min.",
                $excessMinutes > 0         => "Break exceeded allowed by {$excessMinutes} min (took {$seg['duration_minutes']} min, allowed {$allowedDur} min).",
                default                    => null,
            };

            AttendanceBreakLog::updateOrCreate(
                ['attendance_id' => $attendance->id, 'break_start_time' => $seg['out']],
                [
                    'shift_break_id'          => $shiftBreakId,
                    'type'                    => $seg['type'],
                    'is_auto_detected'        => true,
                    'break_end_time'          => $seg['in'],
                    'actual_duration_minutes' => $seg['duration_minutes'],
                    'excess_minutes'          => $excessMinutes,
                    'is_compliant'            => $isCompliant,
                    'is_taken'                => true,
                    'status'                  => $isCompleted ? 'completed' : 'in_progress',
                    'notes'                   => $notes,
                ]
            );
        }

        // Overtime records
        if ($c['overtime_hours'] > 0 && $c['check_out'] && !$c['check_out_synthetic'] && $shift) {
            $shiftEnd = $shift->getEffectiveEndTime($date);
            Overtime::updateOrCreate(
                ['employee_id' => $employee->id, 'attendance_id' => $attendance->id, 'date' => $date, 'type' => 'weekday'],
                ['start_time' => $shiftEnd, 'end_time' => $c['check_out'], 'hours' => $c['overtime_hours'], 'reason' => 'Auto-calculated — weekday OT']
            );
        }

        if ($c['ot1_hours'] > 0 && $c['check_out'] && !$c['check_out_synthetic']) {
            Overtime::updateOrCreate(
                ['employee_id' => $employee->id, 'attendance_id' => $attendance->id, 'date' => $date, 'type' => 'saturday'],
                ['start_time' => $c['check_in'], 'end_time' => $c['check_out'], 'hours' => $c['ot1_hours'], 'reason' => 'OT1 — Saturday work']
            );
        }

        if ($c['ot2_hours'] > 0 && $c['check_out'] && !$c['check_out_synthetic']) {
            Overtime::updateOrCreate(
                ['employee_id' => $employee->id, 'attendance_id' => $attendance->id, 'date' => $date, 'type' => 'sunday'],
                ['start_time' => $c['check_in'], 'end_time' => $c['check_out'], 'hours' => $c['ot2_hours'], 'reason' => 'OT2 — Sunday work']
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================
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
            'sync_date'    => $date,
            'synced_until' => $until,
            'synced_at'    => now(),
        ]);
    }

    private function scenarioToStatus(string $scenario): string
    {
        if (str_starts_with($scenario, 'complete') || str_starts_with($scenario, 'synthetic')) {
            return 'clocked_out';
        }
        return match ($scenario) {
            'checkin_only', 'no_shift' => 'clocked_in',
            'no_checkin', 'not_scheduled', 'no_punches' => 'absent',
            default => 'clocked_in',
        };
    }
}
