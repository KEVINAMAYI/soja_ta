<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->organization = Organization::create([
        'name' => 'Test Organization',
        'email' => 'breaks@example.com',
        'phone_number' => '0700000000',
    ]);
    $this->shift = Shift::create([
        'organization_id' => $this->organization->id,
        'name' => 'Day Shift',
        'start_time' => '08:00',
        'end_time' => '17:00',
    ]);
    $department = Department::create([
        'organization_id' => $this->organization->id,
        'name' => 'Test Department',
    ]);
    $user = User::factory()->create();
    Employee::create([
        'organization_id' => $this->organization->id,
        'department_id' => $department->id,
        'shift_id' => $this->shift->id,
        'user_id' => $user->id,
        'name' => 'Test Employee',
    ]);
    $this->actingAs($user);

    $this->lunch = $this->shift->breaks()->create([
        'name' => 'Lunch Break',
        'type' => 'unpaid',
        'window_start_time' => '12:00',
        'window_end_time' => '13:00',
        'duration_minutes' => 60,
    ]);
});

test('creating a conflicting break reports every conflict and keeps the form open', function () {
    $this->shift->breaks()->create([
        'name' => 'Afternoon Break',
        'type' => 'paid',
        'window_start_time' => '14:00',
        'window_end_time' => '14:30',
        'duration_minutes' => 30,
    ]);
    $message = 'This break overlaps with: Lunch Break (12:00 PM - 01:00 PM); Afternoon Break (02:00 PM - 02:30 PM). Please choose a different time window.';

    LivewireAlert::shouldReceive('title')->once()->with('Overlapping break')->andReturnSelf();
    LivewireAlert::shouldReceive('text')->once()->with($message)->andReturnSelf();
    LivewireAlert::shouldReceive('error')->once()->andReturnSelf();
    LivewireAlert::shouldReceive('toast')->once()->andReturnSelf();
    LivewireAlert::shouldReceive('position')->once()->with('top-end')->andReturnSelf();
    LivewireAlert::shouldReceive('timer')->once()->with(5000)->andReturnSelf();
    LivewireAlert::shouldReceive('show')->once();

    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'Conflicting Break')
        ->set('currentBreak.window_start_time', '12:30')
        ->set('currentBreak.window_end_time', '14:15')
        ->call('saveBreak')
        ->assertHasErrors(['currentBreak.window_start_time'])
        ->assertSee($message)
        ->assertSet('showAddBreakModal', true)
        ->assertSet('currentBreak.name', 'Conflicting Break');

    $this->assertDatabaseCount('shift_breaks', 2);
    expect($this->organization->fresh()->break_tracking_enabled)->toBeFalse();
});

test('editing a break cannot overlap another break', function () {
    $other = $this->shift->breaks()->create([
        'name' => 'Morning Break',
        'type' => 'paid',
        'window_start_time' => '10:00',
        'window_end_time' => '10:15',
        'duration_minutes' => 15,
    ]);

    $component = Volt::test('admin.shifts.create');
    $index = array_search($other->id, array_column($component->get('breaks'), 'id'));
    $component->call('openEditBreakModal', $index)
        ->set('currentBreak.window_start_time', '12:30')
        ->set('currentBreak.window_end_time', '13:15')
        ->call('saveBreak')
        ->assertHasErrors(['currentBreak.window_start_time'])
        ->assertSet('showAddBreakModal', true);

    expect($other->fresh()->window_start_time->format('H:i'))->toBe('10:00');
    $this->assertDatabaseCount('shift_breaks', 2);
});

test('editing a break excludes its own window', function () {
    Volt::test('admin.shifts.create')
        ->call('openEditBreakModal', 0)
        ->set('currentBreak.name', 'Renamed Lunch')
        ->call('saveBreak')
        ->assertHasNoErrors()
        ->assertSet('showAddBreakModal', false);

    expect($this->lunch->fresh()->name)->toBe('Renamed Lunch');
    $this->assertDatabaseCount('shift_breaks', 1);
});

test('nonconflicting breaks are saved', function ($start, $end) {
    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'New Break')
        ->set('currentBreak.window_start_time', $start)
        ->set('currentBreak.window_end_time', $end)
        ->call('saveBreak')
        ->assertHasNoErrors()
        ->assertSet('showAddBreakModal', false);

    $this->assertDatabaseHas('shift_breaks', [
        'shift_id' => $this->shift->id,
        'name' => 'New Break',
    ]);
    expect($this->organization->fresh()->break_tracking_enabled)->toBeTrue();
})->with([
    'back-to-back' => ['13:00', '13:30'],
    'separate' => ['10:00', '10:30'],
    'overnight' => ['23:00', '01:00'],
]);

test('an existing unrestricted break blocks another break', function ($existingStart, $existingEnd, $start, $end) {
    $this->lunch->update([
        'window_start_time' => $existingStart,
        'window_end_time' => $existingEnd,
    ]);

    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'Another Break')
        ->set('currentBreak.window_start_time', $start)
        ->set('currentBreak.window_end_time', $end)
        ->call('saveBreak')
        ->assertHasErrors(['currentBreak.window_start_time'])
        ->assertSee('Lunch Break (Unrestricted - no complete time window)')
        ->assertSet('showAddBreakModal', true);

    $this->assertDatabaseCount('shift_breaks', 1);
})->with([
    'no times' => [null, null, '10:00', '10:30'],
    'missing start' => [null, '13:00', '10:00', '10:30'],
    'missing end' => ['12:00', null, '10:00', '10:30'],
    'both unrestricted' => [null, null, null, null],
]);

test('an unrestricted break cannot be added alongside a scheduled break', function () {
    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'Unrestricted Break')
        ->call('saveBreak')
        ->assertHasErrors(['currentBreak.window_start_time'])
        ->assertSee('Lunch Break (12:00 PM - 01:00 PM)')
        ->assertSet('showAddBreakModal', true);

    $this->assertDatabaseCount('shift_breaks', 1);
});

test('an unrestricted break can be created as the only break', function () {
    $this->lunch->delete();

    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'Unrestricted Break')
        ->call('saveBreak')
        ->assertHasNoErrors()
        ->assertSet('showAddBreakModal', false);

    $this->assertDatabaseCount('shift_breaks', 1);
    $this->assertDatabaseHas('shift_breaks', [
        'name' => 'Unrestricted Break',
        'window_start_time' => null,
        'window_end_time' => null,
    ]);
});

test('editing the only unrestricted break excludes itself', function () {
    $this->lunch->update(['window_start_time' => null, 'window_end_time' => null]);

    Volt::test('admin.shifts.create')
        ->call('openEditBreakModal', 0)
        ->set('currentBreak.name', 'Renamed Unrestricted Break')
        ->call('saveBreak')
        ->assertHasNoErrors()
        ->assertSet('showAddBreakModal', false);

    expect($this->lunch->fresh()->name)->toBe('Renamed Unrestricted Break');
    $this->assertDatabaseCount('shift_breaks', 1);
});

test('editing a scheduled break to unrestricted is rejected when another break exists', function () {
    $this->shift->breaks()->create([
        'name' => 'Morning Break',
        'type' => 'paid',
        'window_start_time' => '10:00',
        'window_end_time' => '10:15',
        'duration_minutes' => 15,
    ]);

    $component = Volt::test('admin.shifts.create');
    $index = array_search($this->lunch->id, array_column($component->get('breaks'), 'id'));
    $component->call('openEditBreakModal', $index)
        ->set('currentBreak.window_start_time', null)
        ->set('currentBreak.window_end_time', null)
        ->call('saveBreak')
        ->assertHasErrors(['currentBreak.window_start_time'])
        ->assertSet('showAddBreakModal', true);

    expect($this->lunch->fresh()->window_start_time->format('H:i'))->toBe('12:00');
    $this->assertDatabaseCount('shift_breaks', 2);
});

test('breaks in another shift do not conflict', function () {
    $this->lunch->update(['shift_id' => Shift::create([
        'organization_id' => $this->organization->id,
        'name' => 'Other Shift',
        'start_time' => '08:00',
        'end_time' => '17:00',
    ])->id]);

    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'New Lunch')
        ->set('currentBreak.window_start_time', '12:00')
        ->set('currentBreak.window_end_time', '13:00')
        ->call('saveBreak')
        ->assertHasNoErrors()
        ->assertSet('showAddBreakModal', false);

    $this->assertDatabaseCount('shift_breaks', 2);
});

test('invalid or incomplete windows are rejected before saving', function ($start, $end, $field) {
    Volt::test('admin.shifts.create')
        ->call('openAddBreakModal')
        ->set('currentBreak.name', 'Invalid Break')
        ->set('currentBreak.window_start_time', $start)
        ->set('currentBreak.window_end_time', $end)
        ->call('saveBreak')
        ->assertHasErrors([$field]);

    $this->assertDatabaseCount('shift_breaks', 1);
})->with([
    'missing end' => ['12:00', null, 'currentBreak.window_end_time'],
    'missing start' => [null, '13:00', 'currentBreak.window_start_time'],
    'invalid start' => ['25:00', '13:00', 'currentBreak.window_start_time'],
    'invalid end' => ['12:00', 'invalid', 'currentBreak.window_end_time'],
    'zero-length window' => ['12:00', '12:00', 'currentBreak.window_end_time'],
]);
