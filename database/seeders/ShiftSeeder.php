<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * STEP 3 — ShiftSeeder
 *
 * Copy to: database/seeders/ShiftSeeder.php
 *
 * Run:
 *   php artisan db:seed --class=ShiftSeeder
 *
 * Or add to DatabaseSeeder.php:
 *   $this->call(ShiftSeeder::class);
 *
 * IMPORTANT: Set $orgId to your organization's ID before running.
 *
 * Seeds all 6 client-specified shifts:
 *   1. Admin Shift              Mon–Fri  08:00–17:30
 *   2. General Day Shift        Mon–Thu  08:00–17:30 | Fri 08:00–16:30
 *   3. General Night Shift      Mon–Thu  17:30–05:00 (overnight)
 *   4. General Extended Shift   Mon–Fri  08:00–17:30 (early in/late out allowed)
 *   5. Engineering Day Shift    Mon–Fri  07:00–16:30
 *   6. Engineering Night Shift  Mon–Fri  17:30–05:00 (overnight)
 *
 * All defined hours = 9h as per client requirement.
 */
class ShiftSeeder extends Seeder
{


    private int $orgId = 2;  // ← change this

    /**
     * !! SET THIS to your organization's ID before running !!
     */

    public function run(): void
    {
        foreach ($this->shifts() as $data) {
            Shift::updateOrCreate(
                [
                    'organization_id' => $this->orgId,
                    'department_type' => $data['department_type'],
                    'shift_type'      => $data['shift_type'],
                    'name'            => $data['name'],
                ],
                $data
            );
        }

        $this->command->info('');
        $this->command->info('✅ Shifts seeded successfully:');
        $this->command->table(
            ['#', 'Name', 'Dept', 'Type', 'Start', 'End', 'Fri End', 'Overnight', 'Days'],
            collect($this->shifts())->values()->map(fn($s, $i) => [
                $i + 1,
                $s['name'],
                $s['department_type'],
                $s['shift_type'],
                $s['start_time'],
                $s['end_time'],
                $s['friday_end_time'] ?? '—',
                $s['is_overnight'] ? '🌙 Yes' : 'No',
                implode(', ', $s['pattern_days']),
            ])->toArray()
        );
    }

    private function shifts(): array
    {
        return [

            // ─────────────────────────────────────────────────────────────────
            // 1. ADMIN SHIFT
            //    Mon–Fri | 08:00 – 17:30
            //    Friday same end time (no variation for Admin)
            //    OT: Fri past 17:30 = Weekday OT | Sat = OT1 | Sun = OT2
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'Admin Shift',
                'department_type'               => 'admin',
                'shift_type'                    => 'admin',
                'start_time'                    => '08:00:00',
                'end_time'                      => '17:30:00',
                'friday_end_time'               => '17:30:00',  // same on Friday
                'is_overnight'                  => false,
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'weekdays',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu','Fri'],
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 3.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => true,
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

            // ─────────────────────────────────────────────────────────────────
            // 2. GENERAL DAY SHIFT
            //    Mon–Thu: 08:00 – 17:30
            //    Friday:  08:00 – 16:30  (1h earlier)
            //    OT: Fri past 16:30 = Weekday OT | Sat = OT1 | Sun = OT2
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'General Day Shift',
                'department_type'               => 'general',
                'shift_type'                    => 'day',
                'start_time'                    => '08:00:00',
                'end_time'                      => '17:30:00',
                'friday_end_time'               => '16:30:00',  // Friday ends 1h earlier
                'is_overnight'                  => false,
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'weekdays',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu','Fri'],
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 4.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => true,
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

            // ─────────────────────────────────────────────────────────────────
            // 3. GENERAL NIGHT SHIFT
            //    Mon–Thu: 17:30 – 05:00 (next day — overnight)
            //    Friday night is covered by General Day Shift OT window
            //    OT: Sat = OT1 | Sun = OT2
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'General Night Shift',
                'department_type'               => 'general',
                'shift_type'                    => 'night',
                'start_time'                    => '17:30:00',
                'end_time'                      => '05:00:00',  // next calendar day
                'friday_end_time'               => null,
                'is_overnight'                  => true,        // addDay() applied in classifier
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'custom',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu'], // Mon–Thu only
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 3.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => true,
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

            // ─────────────────────────────────────────────────────────────────
            // 4. GENERAL EXTENDED SHIFT
            //    Mon–Fri: 08:00 – 17:30 anchor times
            //    Early clock-in / late clock-out allowed — no penalty
            //    System records extra time; OT rules still apply
            //    auto_clock_out = false (employee manages their own out)
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'General Extended Shift',
                'department_type'               => 'general',
                'shift_type'                    => 'extended',
                'start_time'                    => '08:00:00',   // anchor only
                'end_time'                      => '17:30:00',   // anchor only
                'friday_end_time'               => '16:30:00',
                'is_overnight'                  => false,
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'weekdays',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu','Fri'],
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 4.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => false,        // extended = employee-managed
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

            // ─────────────────────────────────────────────────────────────────
            // 5. ENGINEERING DAY SHIFT
            //    Mon–Fri: 07:00 – 16:30 (starts 1h earlier than General)
            //    Friday same end time (no variation for Engineering Day)
            //    OT: Fri past 16:30 = Weekday OT | Sat = OT1 | Sun = OT2
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'Engineering Day Shift',
                'department_type'               => 'engineering',
                'shift_type'                    => 'day',
                'start_time'                    => '07:00:00',
                'end_time'                      => '16:30:00',
                'friday_end_time'               => '16:30:00',  // same on Friday
                'is_overnight'                  => false,
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'weekdays',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu','Fri'],
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 4.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => true,
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

            // ─────────────────────────────────────────────────────────────────
            // 6. ENGINEERING NIGHT SHIFT
            //    Mon–Fri: 17:30 – 05:00 (next day — overnight)
            //    OT: Sat = OT1 | Sun = OT2
            // ─────────────────────────────────────────────────────────────────
            [
                'organization_id'               => $this->orgId,
                'name'                          => 'Engineering Night Shift',
                'department_type'               => 'engineering',
                'shift_type'                    => 'night',
                'start_time'                    => '17:30:00',
                'end_time'                      => '05:00:00',  // next calendar day
                'friday_end_time'               => null,
                'is_overnight'                  => true,        // addDay() applied in classifier
                'duration_hours'                => 9.0,
                'break_minutes'                 => 60,
                'pattern_type'                  => 'weekdays',
                'pattern_days'                  => ['Mon','Tue','Wed','Thu','Fri'],
                'grace_period_enabled'          => true,
                'grace_period_minutes'          => 15,
                'overtime_enabled'              => true,
                'max_overtime_hours'            => 3.0,
                'overtime_saturday'             => 'ot1',
                'overtime_sunday'               => 'ot2',
                'auto_clock_out'                => true,
                'warning_time_minutes'          => 30,
                'track_late_checkin'            => true,
                'notify_on_late_checkin'        => false,
                'track_early_checkout'          => true,
                'early_checkout_threshold_minutes' => 15,
                'notify_managers_overtime'      => false,
                'employee_mobile_notifications' => true,
                'email_summaries'               => false,
                'status'                        => 'active',
            ],

        ];
    }
}
