<?php

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrganizationSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function elevateHrPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event_id' => (string) Str::uuid(),
        'sequence' => 19,
        'event_type' => 'leave.approved',
        'occurred_at' => '2026-10-07T11:45:36Z',
        'leave' => [
            'leave_reference' => '611899b5-6e15-4d33-a4a0-68e268f7bc7c',
            'employee_number' => '5555555',
            'employee_name' => 'Nikita Patidar',
            'leave_type_code' => 'STDY',
            'leave_type_name' => 'Study Leave',
            'is_paid' => true,
            'start_date' => '2026-10-21',
            'end_date' => '2026-10-22',
            'total_days' => '0.50',
            'status' => 'APPROVED',
            'approved_at' => '2026-10-07T11:45:36Z',
            'days' => [
                ['date' => '2026-10-21', 'day_part' => 'HALF_DAY_AFTERNOON', 'hours' => '4.00', 'is_holiday' => false],
            ],
        ],
    ], $overrides);
}

beforeEach(function () {
    // The historical application migrations contain MySQL-only SQL. Build only
    // the pre-existing tables this endpoint needs, then run its real migration.
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    config(['services.elevatehr.webhook_allowed_ips' => ['127.0.0.1']]);
    DB::purge('sqlite');

    (require database_path('migrations/2025_07_29_075436_create_organizations_table.php'))->up();
    (require database_path('migrations/2025_08_07_103748_create_organization_settings_table.php'))->up();

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('department_id')->nullable();
        $table->boolean('is_system_user')->default(false);
        $table->softDeletes();
    });
    Schema::create('leaves', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('employee_id');
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->unsignedBigInteger('department_id')->nullable();
        $table->string('leave_type')->nullable();
        $table->string('status')->default('pending');
        $table->date('start_date');
        $table->date('end_date');
        $table->date('expected_resumption')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->timestamps();
    });

    DB::table('organizations')->insert([
        ['id' => 1, 'name' => 'Test', 'email' => 'one@example.test', 'phone_number' => '1'],
        ['id' => 2, 'name' => 'Other', 'email' => 'two@example.test', 'phone_number' => '2'],
    ]);
    DB::table('employees')->insert([
        ['id' => 5555555, 'organization_id' => 1, 'department_id' => 4],
        ['id' => 222, 'organization_id' => 2, 'department_id' => 5],
    ]);
    DB::table('leaves')->insert([
        'employee_id' => 5555555, 'organization_id' => 1, 'leave_type' => 'Annual Leave',
        'start_date' => '2026-09-01', 'end_date' => '2026-09-03',
    ]);

    (require database_path('migrations/2026_10_22_120000_add_elevatehr_leave_support.php'))->up();
    OrganizationSetting::create([
        'organization_id' => 1,
        'key' => 'elevatehr_leave_webhook_token_hash',
        'value' => hash('sha256', str_repeat('a', 64)),
    ]);
    $this->url = '/api/webhooks/elevatehr/leave/1?token='.str_repeat('a', 64);
});

test('records approved half-day leave using the existing employee and department', function () {
    $payload = elevateHrPayload();
    $this->postJson($this->url, $payload)->assertOk();

    $leave = Leave::whereNotNull('elevatehr_reference')->sole();
    expect($leave->employee_id)->toBe(5555555)
        ->and($leave->organization_id)->toBe(1)
        ->and($leave->department_id)->toBe(4)
        ->and($leave->status)->toBe('approved')
        ->and($leave->num_of_days)->toBe('0.50')
        ->and($leave->leave_days)->toBe($payload['leave']['days'])
        ->and($leave->expected_resumption->toDateString())->toBe('2026-10-22')
        ->and($leave->daysInRange('2026-10-21', '2026-10-22'))->toBe(0.5);
});

test('backfills whole day counts and keeps legacy range behaviour', function () {
    $leave = Leave::whereNull('elevatehr_reference')->sole();
    expect($leave->num_of_days)->toBe('3.00')
        ->and($leave->daysInRange('2026-09-02', '2026-09-03'))->toBe(2.0)
        ->and($leave->daysInRange('2026-10-01', '2026-10-31'))->toBe(0.0);
});

test('creates custom types once regardless of name case and exposes organisation scoped options', function () {
    $this->postJson($this->url, elevateHrPayload(['leave' => ['leave_type_name' => 'Wellness Leave']]))->assertOk();
    $this->postJson($this->url, elevateHrPayload([
        'sequence' => 20, 'leave' => ['leave_type_name' => 'WELLNESS LEAVE'],
    ]))->assertOk();

    expect(LeaveType::count())->toBe(1)
        ->and(LeaveType::sole()->name)->toBe('Wellness Leave')
        ->and(Leave::whereNotNull('elevatehr_reference')->sole()->leave_type)->toBe('Wellness Leave')
        ->and(collect(LeaveType::options(1))->pluck('name'))->toContain('Wellness Leave')
        ->and(collect(LeaveType::options(2))->pluck('name'))->not->toContain('Wellness Leave');
});

test('reuses built-in and historical type names case insensitively', function () {
    $this->postJson($this->url, elevateHrPayload(['leave' => ['leave_type_name' => 'STUDY LEAVE']]))->assertOk();
    expect(LeaveType::sole()->name)->toBe('Study Leave');

    DB::table('leaves')->whereNull('elevatehr_reference')->update(['leave_type' => 'Historic Leave']);
    $this->postJson($this->url, elevateHrPayload([
        'sequence' => 20, 'leave' => ['leave_type_name' => 'HISTORIC LEAVE'],
    ]))->assertOk();
    expect(Leave::whereNotNull('elevatehr_reference')->sole()->leave_type)->toBe('Historic Leave');
});

test('duplicate and out of order deliveries do not overwrite newer leave', function () {
    $payload = elevateHrPayload();
    $this->postJson($this->url, $payload)->assertOk();
    $this->postJson($this->url, $payload)->assertOk();
    $this->postJson($this->url, elevateHrPayload([
        'sequence' => 18, 'event_type' => 'leave.recalled',
    ]))->assertOk();

    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(1)
        ->and(Leave::whereNotNull('elevatehr_reference')->sole()->status)->toBe('approved')
        ->and(LeaveType::count())->toBe(1);
});

test('partial recalls replace the remaining days without filling recalled gaps', function () {
    $approved = elevateHrPayload(['leave' => ['end_date' => '2026-10-23', 'total_days' => '3.00']]);
    $approved['leave']['days'] = collect(['2026-10-21', '2026-10-22', '2026-10-23'])
        ->map(fn ($date) => ['date' => $date, 'day_part' => 'FULL_DAY', 'hours' => '8.00', 'is_holiday' => false])
        ->all();
    $this->postJson($this->url, $approved)->assertOk();
    $payload = elevateHrPayload([
        'sequence' => 20, 'event_type' => 'leave.partial_recall',
        'leave' => ['end_date' => '2026-10-23', 'total_days' => '1.50'],
    ]);
    $payload['leave']['days'] = [
        ['date' => '2026-10-21', 'day_part' => 'HALF_DAY_MORNING', 'hours' => '4.00', 'is_holiday' => false],
        ['date' => '2026-10-23', 'day_part' => 'FULL_DAY', 'hours' => '8.00', 'is_holiday' => false],
    ];
    $this->postJson($this->url, $payload)->assertOk();

    $leave = Leave::whereNotNull('elevatehr_reference')->sole();
    expect($leave->status)->toBe('approved')
        ->and($leave->num_of_days)->toBe('1.50')
        ->and($leave->daysInRange('2026-10-21', '2026-10-21'))->toBe(0.5)
        ->and($leave->daysInRange('2026-10-22', '2026-10-22'))->toBe(0.0)
        ->and($leave->daysInRange('2026-10-23', '2026-10-23'))->toBe(1.0)
        ->and($leave->expected_resumption->toDateString())->toBe('2026-10-24');
});

test('full recall cancels leave and a late approval cannot restore it', function () {
    $this->postJson($this->url, elevateHrPayload())->assertOk();
    $payload = elevateHrPayload(['sequence' => 20, 'event_type' => 'leave.recalled']);
    $payload['leave']['days'] = [];
    $payload['leave']['total_days'] = '0.00';
    $payload['leave']['status'] = 'RECALLED';
    $this->postJson($this->url, $payload)->assertOk();
    $this->postJson($this->url, elevateHrPayload())->assertOk();

    $leave = Leave::whereNotNull('elevatehr_reference')->sole();
    expect($leave->status)->toBe('cancelled')
        ->and($leave->num_of_days)->toBe('0.00')
        ->and($leave->leave_days)->toBe([])
        ->and($leave->expected_resumption)->toBeNull()
        ->and($leave->cancelled_at)->not->toBeNull();
});

test('recall received before approval still prevents stale approval', function () {
    $this->postJson($this->url, elevateHrPayload(['sequence' => 20, 'event_type' => 'leave.recalled']))->assertOk();
    $this->postJson($this->url, elevateHrPayload())->assertOk();
    expect(Leave::whereNotNull('elevatehr_reference')->sole()->status)->toBe('cancelled');
});

test('a partial recall can be the first received snapshot', function () {
    $this->postJson($this->url, elevateHrPayload(['event_type' => 'leave.partial_recall']))->assertOk();
    expect(Leave::whereNotNull('elevatehr_reference')->sole()->num_of_days)->toBe('0.50');
});

test('holiday entries are preserved without counting them as leave days', function () {
    $payload = elevateHrPayload();
    $payload['leave']['days'][] = [
        'date' => '2026-10-22', 'day_part' => 'FULL_DAY', 'hours' => '0.00', 'is_holiday' => true,
    ];
    $this->postJson($this->url, $payload)->assertOk();
    $leave = Leave::whereNotNull('elevatehr_reference')->sole();
    expect($leave->leave_days)->toHaveCount(2)
        ->and($leave->daysInRange('2026-10-21', '2026-10-22'))->toBe(0.5)
        ->and($leave->daysInRange('2026-10-22', '2026-10-22'))->toBe(0.0);
});

test('new custom types appear in the authenticated leave type API', function () {
    $this->postJson($this->url, elevateHrPayload(['leave' => ['leave_type_name' => 'Wellness Leave']]))->assertOk();
    $user = new User;
    $user->id = 1;
    $user->setRelation('employee', Employee::findOrFail(5555555));

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/leaves/types')->assertOk();
    expect(collect($response->json('data'))->pluck('name'))->toContain('Wellness Leave', 'Annual Leave');
});

test('database failure rolls back both the leave and any newly created type', function () {
    Event::listen('eloquent.creating: '.Leave::class, function () {
        throw new RuntimeException('Simulated database failure');
    });
    $this->postJson($this->url, elevateHrPayload(['leave' => ['leave_type_name' => 'Wellness Leave']]))
        ->assertStatus(500);
    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and(LeaveType::count())->toBe(0)
        ->and(Leave::whereNull('elevatehr_reference')->count())->toBe(1);
});

test('requires a configured secret URL and isolates organisations', function () {
    $this->postJson('/api/webhooks/elevatehr/leave/1', elevateHrPayload())->assertForbidden();
    $this->postJson('/api/webhooks/elevatehr/leave/1?token=wrong', elevateHrPayload())->assertForbidden();
    $this->postJson('/api/webhooks/elevatehr/leave/1?token[]=wrong', elevateHrPayload())->assertForbidden();
    $this->postJson('/api/webhooks/elevatehr/leave/2?token='.str_repeat('a', 64), elevateHrPayload())->assertForbidden();
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => '222']]))->assertUnprocessable();
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => '999']]))->assertUnprocessable();
    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and(LeaveType::count())->toBe(0);
});

test('rejects and logs webhook requests from IPs outside the allowlist', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('Rejected ElevateHR webhook request from untrusted IP.', [
            'ip' => '198.51.100.42',
            'organization_id' => '1',
        ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
        ->postJson($this->url, elevateHrPayload())
        ->assertForbidden();

    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0);
});

test('rejects webhook requests when the IP allowlist is empty', function () {
    config(['services.elevatehr.webhook_allowed_ips' => []]);

    $this->postJson($this->url, elevateHrPayload())->assertForbidden();
});

test('allows webhook requests from an IP inside an allowed CIDR range', function () {
    config(['services.elevatehr.webhook_allowed_ips' => ['198.51.100.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
        ->postJson($this->url, elevateHrPayload())
        ->assertOk();
});

test('cannot move an external leave reference to another employee', function () {
    $this->postJson($this->url, elevateHrPayload())->assertOk();
    DB::table('employees')->insert(['id' => 333, 'organization_id' => 1]);
    $this->postJson($this->url, elevateHrPayload([
        'sequence' => 20, 'leave' => ['employee_number' => '333'],
    ]))->assertUnprocessable();
    expect(Leave::whereNotNull('elevatehr_reference')->sole()->employee_id)->toBe(5555555);
});

test('rejects malformed or unsupported payloads without saving anything', function (array $overrides) {
    $this->postJson($this->url, elevateHrPayload($overrides))->assertUnprocessable();
    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and(LeaveType::count())->toBe(0);
})->with([
    [['event_id' => 'invalid']],
    [['sequence' => -1]],
    [['event_type' => 'leave.pending']],
    [['leave' => ['status' => 'PENDING']]],
    [['leave' => ['total_days' => '-0.50']]],
    [['leave' => ['total_days' => '0.001']]],
    [['leave' => ['end_date' => '2026-10-20']]],
    [['leave' => ['employee_number' => '5555555 OR 1=1']]],
    [['leave' => ['leave_type_name' => '   ']]],
    [['leave' => ['days' => [['day_part' => 'EVENING']]]]],
    [['leave' => ['days' => [['date' => '2026-10-25']]]]],
    [['leave' => ['days' => [['hours' => '25.00']]]]],
]);

test('requires JSON and limits payload size', function () {
    $this->post($this->url, elevateHrPayload())->assertStatus(415);
    $this->postJson($this->url, ['padding' => str_repeat('x', 262145)])->assertStatus(413);
});

test('rotates a hashed secret using the setup command', function () {
    $oldHash = OrganizationSetting::sole()->value;
    $this->artisan('elevatehr:leave-webhook', ['organization' => 1])->assertSuccessful();
    $newHash = OrganizationSetting::sole()->value;
    expect($newHash)->not->toBe($oldHash)->toHaveLength(64);
    $this->postJson($this->url, elevateHrPayload())->assertForbidden();
});

test('the new migration can be rolled back without dropping existing leave', function () {
    (require database_path('migrations/2026_10_22_120000_add_elevatehr_leave_support.php'))->down();
    expect(Schema::hasTable('leave_types'))->toBeFalse()
        ->and(Schema::hasColumn('leaves', 'num_of_days'))->toBeFalse()
        ->and(DB::table('leaves')->count())->toBe(1);
});
