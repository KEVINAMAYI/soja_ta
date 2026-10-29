<?php

use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Services\BreakDetector;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('break checkout windows include the start and exclude the end', function ($start, $end, $checkout, $matches) {
    $break = new ShiftBreak([
        'window_start_time' => $start,
        'window_end_time' => $end,
    ]);
    $time = Carbon::parse($checkout, 'Africa/Nairobi');
    $original = $time->copy();

    expect($break->isWithinWindow($time))->toBe($matches)
        ->and($time->eq($original))->toBeTrue();
})->with([
    'before start' => ['09:00', '09:34', '2026-10-29 08:59:59', false],
    'exact start' => ['09:00', '09:34', '2026-10-29 09:00:00', true],
    'before end' => ['09:00', '09:34', '2026-10-29 09:33:59.999999', true],
    'exact end' => ['09:00', '09:34', '2026-10-29 09:34:00', false],
    'after end' => ['09:00', '09:34', '2026-10-29 09:34:01', false],
    'historical checkout' => ['09:00', '09:34', '2025-01-15 09:15:00', true],
    'overnight start' => ['23:00', '01:00', '2026-10-29 23:00:00', true],
    'overnight evening' => ['23:00', '01:00', '2026-10-29 23:30:00', true],
    'overnight midnight' => ['23:00', '01:00', '2026-10-30 00:00:00', true],
    'overnight morning' => ['23:00', '01:00', '2026-10-30 00:59:59.999999', true],
    'overnight exact end' => ['23:00', '01:00', '2026-10-30 01:00:00', false],
    'overnight after end' => ['23:00', '01:00', '2026-10-30 01:00:01', false],
    'overnight daytime' => ['23:00', '01:00', '2026-10-30 12:00:00', false],
    'midnight end' => ['23:00', '00:00', '2026-10-30 00:00:00', false],
    'midnight start' => ['00:00', '01:00', '2026-10-30 00:00:00', true],
    'equal endpoints' => ['09:34', '09:34', '2026-10-29 09:34:00', false],
    'unrestricted' => [null, null, '2026-10-29 09:34:00', true],
    'missing start' => [null, '09:34', '2026-10-29 09:34:00', true],
    'missing end' => ['09:00', null, '2026-10-29 09:34:00', true],
]);

test('a checkout at a shared boundary matches only the next break regardless of order', function ($reverse, $firstStart, $boundary, $secondEnd, $checkout) {
    $first = new ShiftBreak([
        'name' => 'First Break',
        'window_start_time' => $firstStart,
        'window_end_time' => $boundary,
    ]);
    $second = new ShiftBreak([
        'name' => 'Second Break',
        'window_start_time' => $boundary,
        'window_end_time' => $secondEnd,
    ]);
    $shift = new Shift;
    $shift->setRelation('activeBreaks', collect($reverse ? [$second, $first] : [$first, $second]));
    $time = Carbon::parse($checkout);

    expect($first->isWithinWindow($time))->toBeFalse()
        ->and($second->isWithinWindow($time))->toBeTrue()
        ->and(BreakDetector::matchBreakWindow($time, $shift))->toBe($second);
})->with([false, true])->with([
    'daytime' => ['09:00', '09:34', '10:00', '2026-10-29 09:34:00'],
    'overnight' => ['23:00', '01:00', '02:00', '2026-10-30 01:00:00'],
    'midnight' => ['23:00', '00:00', '01:00', '2026-10-30 00:00:00'],
]);
