<?php

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Shift;
use App\Services\AfricasTalkingSmsService;
use App\Services\CheckInApprovalService;
use App\Services\CheckInApprovalSettings;

beforeEach(function () {
    $this->organization = Organization::create([
        'name' => 'Approval Test Organization',
        'email' => 'approvals@example.com',
        'phone_number' => '0700000000',
    ]);
    $this->employee = new Employee([
        'organization_id' => $this->organization->id,
        'department_id' => 10,
    ]);
    $this->shift = new Shift([
        'track_late_checkin' => true,
        'grace_period_enabled' => true,
        'grace_period_minutes' => 5,
    ]);
    $this->employee->setRelation('shift', $this->shift);
    $this->service = new CheckInApprovalService(Mockery::mock(AfricasTalkingSmsService::class));
});

test('tracked late shift check-ins bypass only the tenant enable toggle', function (
    $enabled, $tracking, $trigger, $departments, $minutesLate, $requiresApproval
) {
    CheckInApprovalSettings::save($this->organization->id, [
        'enabled' => $enabled,
        'trigger_shift_clock_ins' => $trigger,
        'department_ids' => $departments,
    ]);
    $this->shift->track_late_checkin = $tracking;
    $settings = CheckInApprovalSettings::get($this->organization->id);

    expect($this->service->shouldRequireApproval($this->employee, $minutesLate))
        ->toBe($requiresApproval ? $settings : null);
})->with([
    'tenant disabled' => [false, true, true, [], 6, true],
    'tenant enabled' => [true, true, true, [], 6, true],
    'tracking and tenant disabled' => [false, false, true, [], 6, false],
    'existing enabled policy without tracking' => [true, false, true, [], 6, true],
    'shift trigger disabled' => [false, true, false, [], 6, false],
    'shift trigger disabled with tenant enabled' => [true, true, false, [], 6, false],
    'department included' => [false, true, true, [10], 6, true],
    'department excluded' => [false, true, true, [20], 6, false],
    'department excluded with tenant enabled' => [true, true, true, [20], 6, false],
    'on time' => [false, true, true, [], 0, false],
    'inside grace period' => [false, true, true, [], 4, false],
    'at grace boundary' => [false, true, true, [], 5, false],
    'inside grace with tenant enabled' => [true, true, true, [], 4, false],
]);

test('tracked late check-ins without a grace period bypass the tenant toggle', function () {
    CheckInApprovalSettings::save($this->organization->id, [
        'enabled' => false,
        'trigger_shift_clock_ins' => true,
    ]);
    $this->shift->grace_period_enabled = false;

    expect($this->service->shouldRequireApproval($this->employee, 1))->not->toBeNull()
        ->and($this->service->shouldRequireApproval($this->employee, 0))->toBeNull();
});

test('late tracking does not bypass the tenant toggle for break returns', function ($enabled, $trigger, $requiresApproval) {
    CheckInApprovalSettings::save($this->organization->id, [
        'enabled' => $enabled,
        'trigger_break_returns' => $trigger,
    ]);
    $settings = CheckInApprovalSettings::get($this->organization->id);

    expect($this->service->shouldRequireApproval($this->employee, 6, true))
        ->toBe($requiresApproval ? $settings : null);
})->with([
    'tenant disabled' => [false, true, false],
    'tenant enabled' => [true, true, true],
    'break trigger disabled' => [true, false, false],
]);

test('tracked late check-ins still require an explicitly configured shift trigger', function () {
    expect($this->service->shouldRequireApproval($this->employee, 6))->toBeNull();
});

test('zero or disabled shift grace requires positive lateness for approval', function (
    $enabled, $graceEnabled, $minutesLate, $requiresApproval
) {
    CheckInApprovalSettings::save($this->organization->id, [
        'enabled' => $enabled,
        'trigger_shift_clock_ins' => true,
    ]);
    $this->shift->grace_period_enabled = $graceEnabled;
    $this->shift->grace_period_minutes = 0;
    $settings = CheckInApprovalSettings::get($this->organization->id);

    expect($this->service->shouldRequireApproval($this->employee, $minutesLate))
        ->toBe($requiresApproval ? $settings : null);
})->with([false, true])->with([false, true])->with([
    'early' => [-1, false],
    'on time' => [0, false],
    'one minute late' => [1, true],
]);

test('break approval requires positive excess minutes regardless of shift grace', function (
    $graceMinutes, $minutesLate, $requiresApproval
) {
    CheckInApprovalSettings::save($this->organization->id, [
        'enabled' => true,
        'trigger_break_returns' => true,
    ]);
    $this->shift->grace_period_minutes = $graceMinutes;
    $settings = CheckInApprovalSettings::get($this->organization->id);

    expect($this->service->shouldRequireApproval($this->employee, $minutesLate, true))
        ->toBe($requiresApproval ? $settings : null);
})->with([0, 5])->with([
    'early' => [-1, false],
    'on time' => [0, false],
    'one minute overdue' => [1, true],
]);
