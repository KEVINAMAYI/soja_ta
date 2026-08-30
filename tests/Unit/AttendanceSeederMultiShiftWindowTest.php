<?php

use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceSeeder;
use Carbon\Carbon;

// Boots the Laravel app (needed for Eloquent's connection resolver) without
// RefreshDatabase — these tests use unsaved, in-memory models only and never
// touch the database.
uses(Tests\TestCase::class);

/**
 * These build unsaved Employee/Shift model instances (no DB writes, no
 * factories) and inject the shifts relation directly via setRelation(),
 * so they exercise the real resolveActiveOrNextShiftWindow() logic without
 * needing a migrated database.
 */
function stubShift(string $start, string $end, string $type): Shift
{
    return new Shift([
        'start_time' => $start,
        'end_time' => $end,
        'shift_type' => $type,
        // 'daily' so day-of-week never matters for these tests.
        'pattern_type' => 'daily',
    ]);
}

function stubEmployeeWithShifts(Shift $default, array $allShifts): Employee
{
    $employee = new Employee();
    $employee->shift_id = $default->id;
    $employee->setRelation('shifts', collect($allShifts));

    return $employee;
}

function resolveWindow(Employee $employee, Shift $defaultShift, string $today, Carbon $now): ?array
{
    $method = new ReflectionMethod(AttendanceSeeder::class, 'resolveActiveOrNextShiftWindow');
    $method->setAccessible(true);

    return $method->invoke(new AttendanceSeeder(), $employee, $defaultShift, $today, $now);
}

test('does not judge absence against Day once inside a different assigned Night window', function () {
    $day = stubShift('08:00:00', '17:00:00', 'day');
    $night = stubShift('20:00:00', '06:00:00', 'night');
    $employee = stubEmployeeWithShifts($day, [$day, $night]);

    $today = Carbon::now()->toDateString();
    $now = Carbon::parse("{$today} 22:00:00"); // well past Day's end, inside Night's window

    $window = resolveWindow($employee, $day, $today, $now);

    expect($window)->not->toBeNull();
    expect($now->between($window[0], $window[1]))->toBeTrue();
    expect($window[1]->format('H:i'))->toBe('06:00'); // Night's end, not Day's 17:00
});

test('marks absent once every assigned shift window has ended', function () {
    $day = stubShift('08:00:00', '17:00:00', 'day');
    $night = stubShift('20:00:00', '06:00:00', 'night');
    $employee = stubEmployeeWithShifts($day, [$day, $night]);

    $today = Carbon::now()->toDateString();
    $now = Carbon::parse($today)->addDay()->setTime(7, 0); // after Night's window has fully closed

    $window = resolveWindow($employee, $day, $today, $now);

    expect($window)->not->toBeNull();
    expect($now->greaterThan($window[1]))->toBeTrue();
    expect($window[1]->format('H:i'))->toBe('06:00'); // latest-ending window
});

test('returns null in the gap between two assigned shifts (too early to judge)', function () {
    $day = stubShift('08:00:00', '17:00:00', 'day');
    $night = stubShift('20:00:00', '06:00:00', 'night');
    $employee = stubEmployeeWithShifts($day, [$day, $night]);

    $today = Carbon::now()->toDateString();
    $now = Carbon::parse("{$today} 18:00:00"); // after Day ends, before Night starts

    $window = resolveWindow($employee, $day, $today, $now);

    expect($window)->toBeNull();
});

test('single-shift employees are judged exactly as before (regression control)', function () {
    $day = stubShift('08:00:00', '17:00:00', 'day');
    $employee = stubEmployeeWithShifts($day, [$day]);

    $today = Carbon::now()->toDateString();
    $now = Carbon::parse("{$today} 18:00:00"); // past Day's only window

    $window = resolveWindow($employee, $day, $today, $now);

    expect($window)->not->toBeNull();
    expect($now->greaterThan($window[1]))->toBeTrue();
    expect($window[1]->format('H:i'))->toBe('17:00');
});

test('still waits before the only assigned shift has started', function () {
    $day = stubShift('08:00:00', '17:00:00', 'day');
    $employee = stubEmployeeWithShifts($day, [$day]);

    $today = Carbon::now()->toDateString();
    $now = Carbon::parse("{$today} 06:00:00"); // before Day starts

    $window = resolveWindow($employee, $day, $today, $now);

    expect($window)->toBeNull();
});
