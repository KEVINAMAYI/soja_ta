<?php

use App\Models\Employee;
use App\Models\Shift;
use App\Services\AfricasTalkingSmsService;
use App\Services\CheckInApprovalService;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('non-positive lateness never requires approval or queries tenant settings', function ($isBreakReturn, $minutesLate) {
    $this->expectsDatabaseQueryCount(0);
    $service = new CheckInApprovalService(Mockery::mock(AfricasTalkingSmsService::class));
    $employee = new Employee(['organization_id' => 1]);

    expect($service->shouldRequireApproval($employee, $minutesLate, $isBreakReturn))->toBeNull();
})->with([false, true])->with([-1, 0]);

test('zero grace measures lateness in whole minutes from shift start', function ($checkIn, $isLate, $minutesLate) {
    $this->travelTo(Carbon::parse('2026-11-04 08:00:00'));
    $shift = new Shift([
        'start_time' => '08:00:00',
        'track_late_checkin' => true,
        'grace_period_enabled' => true,
        'grace_period_minutes' => 0,
    ]);
    $time = Carbon::parse($checkIn);

    expect($shift->getGracePeriodEndTime()->format('H:i:s'))->toBe('08:00:00')
        ->and($shift->isWithinGracePeriod($time))->toBeFalse()
        ->and($shift->isLateCheckIn($time))->toBe($isLate)
        ->and($shift->getMinutesLate($time))->toBe($minutesLate);
})->with([
    'early' => ['2026-11-04 07:59:00', false, 0],
    'on time' => ['2026-11-04 08:00:00', false, 0],
    'less than one minute late' => ['2026-11-04 08:00:59', true, 0],
    'one minute late' => ['2026-11-04 08:01:00', true, 1],
]);
