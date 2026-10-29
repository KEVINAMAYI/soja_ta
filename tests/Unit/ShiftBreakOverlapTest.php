<?php

use App\Models\ShiftBreak;
use Tests\TestCase;

uses(TestCase::class);

test('break windows detect overlaps in either direction', function ($existingStart, $existingEnd, $start, $end, $overlaps) {
    $existing = new ShiftBreak([
        'window_start_time' => $existingStart,
        'window_end_time' => $existingEnd,
    ]);
    $candidate = new ShiftBreak([
        'window_start_time' => $start,
        'window_end_time' => $end,
    ]);

    expect($existing->overlapsWindow($start, $end))->toBe($overlaps)
        ->and($candidate->overlapsWindow($existingStart, $existingEnd))->toBe($overlaps);
})->with([
    'partial overlap at end' => ['12:00', '13:00', '12:30', '13:30', true],
    'partial overlap at start' => ['12:00', '13:00', '11:30', '12:30', true],
    'contained window' => ['12:00', '13:00', '12:15', '12:45', true],
    'containing window' => ['12:00', '13:00', '11:00', '14:00', true],
    'identical window' => ['12:00', '13:00', '12:00', '13:00', true],
    'adjacent after' => ['12:00', '13:00', '13:00', '14:00', false],
    'adjacent before' => ['12:00', '13:00', '11:00', '12:00', false],
    'separate windows' => ['12:00', '13:00', '14:00', '15:00', false],
    'overnight early-morning overlap' => ['23:00', '01:00', '00:30', '02:00', true],
    'overnight evening overlap' => ['23:00', '01:00', '22:30', '23:30', true],
    'both overnight' => ['23:00', '01:00', '23:30', '00:30', true],
    'overnight separate windows' => ['23:00', '01:00', '02:00', '03:00', false],
    'overnight adjacent after' => ['23:00', '01:00', '01:00', '02:00', false],
    'overnight adjacent before' => ['23:00', '01:00', '22:00', '23:00', false],
    'midnight boundary' => ['23:00', '00:00', '00:00', '01:00', false],
    'database time format' => ['12:00:00', '13:00:00', '12:30', '13:30', true],
]);

test('breaks without a complete time window overlap every other break', function ($start, $end) {
    $break = new ShiftBreak([
        'window_start_time' => $start,
        'window_end_time' => $end,
    ]);

    $scheduled = new ShiftBreak([
        'window_start_time' => '12:00',
        'window_end_time' => '13:00',
    ]);

    expect($break->overlapsWindow('12:00', '13:00'))->toBeTrue()
        ->and($scheduled->overlapsWindow($start, $end))->toBeTrue()
        ->and($break->overlapsWindow(null, null))->toBeTrue();
})->with([
    [null, null],
    ['12:00', null],
    [null, '13:00'],
]);

test('empty form times overlap an existing scheduled break', function () {
    $break = new ShiftBreak([
        'window_start_time' => '12:00',
        'window_end_time' => '13:00',
    ]);

    expect($break->overlapsWindow('', ''))->toBeTrue();
});
