<?php

use App\Jobs\SendElevateWebhookFailureAlert;
use App\Models\ElevateHrWebhook;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Notifications\ElevateWebhookFailureAlert;
use App\Services\ElevateHrWebhookMonitor;
use App\Services\ElevateHrWebhookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
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
            'employee_number' => 'AD-00555',
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
    $this->mysqlFixtures = false;
    if (env('ELEVATEHR_TEST_DB_CONNECTION') === 'mysql') {
        $database = env('ELEVATEHR_TEST_DB_DATABASE', '');
        if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+_(test|testing)$/', $database)
            || env('ELEVATEHR_TEST_DB_RESET') !== true) {
            throw new RuntimeException('MySQL webhook tests require a disposable *_test or *_testing database and ELEVATEHR_TEST_DB_RESET=true.');
        }
        config([
            'database.default' => 'elevatehr_testing',
            'database.connections.elevatehr_testing' => [
                'driver' => 'mysql',
                'host' => env('ELEVATEHR_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('ELEVATEHR_TEST_DB_PORT', '3306'),
                'database' => $database,
                'username' => env('ELEVATEHR_TEST_DB_USERNAME', ''),
                'password' => env('ELEVATEHR_TEST_DB_PASSWORD', ''),
                'unix_socket' => env('ELEVATEHR_TEST_DB_SOCKET', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
        ]);
        DB::purge('elevatehr_testing');
        if (Schema::getTables() !== []) {
            throw new RuntimeException('The MySQL webhook test database must be empty. No tables have been changed.');
        }
        $this->mysqlFixtures = true;
    } else {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }
    config(['services.elevatehr.webhook_allowed_ips' => ['127.0.0.1']]);

    (require database_path('migrations/2025_07_29_075436_create_organizations_table.php'))->up();
    (require database_path('migrations/2025_08_07_103748_create_organization_settings_table.php'))->up();
    (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    (require database_path('migrations/2025_08_12_111634_create_permission_tables.php'))->up();
    (require database_path('migrations/2025_08_29_035537_add_category_to_permissions_table.php'))->up();
    (require database_path('migrations/2026_10_28_090100_add_manage_elevate_callback_permission.php'))->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('department_id')->nullable();
        $table->string('ad_employee_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
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
        ['id' => 5555555, 'organization_id' => 1, 'department_id' => 4, 'ad_employee_id' => 'AD-00555'],
        ['id' => 222, 'organization_id' => 2, 'department_id' => 5, 'ad_employee_id' => '222'],
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
    (require database_path('migrations/2026_10_28_090000_create_elevate_hr_webhooks_table.php'))->up();
    (require database_path('migrations/2026_11_04_090000_create_elevate_webhook_monitoring_tables.php'))->up();
    (require database_path('migrations/2026_11_04_100000_add_metadata_to_failed_elevate_webhooks_table.php'))->up();
    $this->url = '/api/webhooks/elevatehr/leave/1?token='.str_repeat('a', 64);
});

afterEach(function () {
    if ($this->mysqlFixtures ?? false) {
        Schema::connection('elevatehr_testing')->dropAllTables();
        DB::purge('elevatehr_testing');
    }
});

function elevateWebhookManager(int $organizationId = 1, bool $grantPermission = true): User
{
    $user = User::factory()->create(['name' => 'Webhook Manager '.$organizationId]);
    DB::table('employees')->where('organization_id', $organizationId)->update(['user_id' => $user->id]);
    if ($grantPermission) {
        $user->givePermissionTo('manage-elevate-callback');
    }

    return $user;
}

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

test('matches string AD identifiers without converting leading zeros to internal IDs', function (string $adEmployeeId) {
    DB::table('employees')->where('id', 5555555)->update(['ad_employee_id' => $adEmployeeId]);
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => $adEmployeeId]]))->assertOk();

    expect(Leave::whereNotNull('elevatehr_reference')->sole()->employee_id)->toBe(5555555);
})->with(['000123', 'M1ALI748']);

test('does not count other organisations deleted employees or system users as AD matches', function () {
    DB::table('employees')->insert([
        ['id' => 333, 'organization_id' => 2, 'ad_employee_id' => 'AD-00555', 'deleted_at' => null, 'is_system_user' => false],
        ['id' => 334, 'organization_id' => 1, 'ad_employee_id' => 'AD-00555', 'deleted_at' => now(), 'is_system_user' => false],
        ['id' => 335, 'organization_id' => 1, 'ad_employee_id' => 'AD-00555', 'deleted_at' => null, 'is_system_user' => true],
    ]);

    $this->postJson($this->url, elevateHrPayload())->assertOk();
    expect(Leave::whereNotNull('elevatehr_reference')->sole()->employee_id)->toBe(5555555);
});

test('records missing AD matches without falling back to internal IDs or excluded employees', function (string $employeeNumber) {
    DB::table('employees')->insert([
        ['id' => 333, 'organization_id' => 1, 'ad_employee_id' => 'DELETED', 'deleted_at' => now(), 'is_system_user' => false],
        ['id' => 334, 'organization_id' => 1, 'ad_employee_id' => 'SYSTEM', 'deleted_at' => null, 'is_system_user' => true],
    ]);
    $reason = 'No employee found in this organisation for ad_employee_id ==> '.$employeeNumber;
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => $employeeNumber]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('leave.employee_number');

    $failure = DB::table('failed_elevate_webhooks')->sole();
    expect($failure->reason)->toBe($reason)
        ->and((int) $failure->status_code)->toBe(422)
        ->and(json_decode($failure->validation_errors, true))->toBe(['leave.employee_number' => [$reason]])
        ->and(json_decode($failure->metadata, true))->toBe([
            'ad_employee_id' => $employeeNumber,
            'employee_name' => 'Nikita Patidar',
            'matched_employees' => [],
        ])
        ->and(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and(LeaveType::count())->toBe(0)
        ->and((int) DB::table('elevate_webhook_daily_stats')->sole()->failed)->toBe(1);
})->with(['5555555', '222', '999', 'DELETED', 'SYSTEM']);

test('rejects duplicate AD matches and records names and emails without changing leave', function (string $eventType) {
    $this->postJson($this->url, elevateHrPayload())->assertOk();
    $original = Leave::whereNotNull('elevatehr_reference')->sole()->getAttributes();
    DB::table('employees')->where('id', 5555555)->update(['name' => 'Nikita Patidar', 'email' => 'nikita@example.test']);
    DB::table('employees')->insert([
        'id' => 333, 'organization_id' => 1, 'ad_employee_id' => 'AD-00555',
        'name' => 'Another Employee', 'email' => 'another@example.test',
    ]);

    $reason = 'Multiple employees found in this organisation for ad_employee_id ==> AD-00555';
    $this->postJson($this->url, elevateHrPayload(['sequence' => 20, 'event_type' => $eventType]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('leave.employee_number');
    $failure = DB::table('failed_elevate_webhooks')->sole();
    $metadata = json_decode($failure->metadata, true);
    expect($failure->reason)->toBe($reason)
        ->and((int) $failure->status_code)->toBe(422)
        ->and(json_decode($failure->validation_errors, true))->toBe(['leave.employee_number' => [$reason]])
        ->and($metadata['ad_employee_id'])->toBe('AD-00555')
        ->and($metadata['employee_name'])->toBe('Nikita Patidar')
        ->and(collect($metadata['matched_employees'])->sortBy('id')->values()->all())->toBe([
            ['id' => 333, 'name' => 'Another Employee', 'email' => 'another@example.test'],
            ['id' => 5555555, 'name' => 'Nikita Patidar', 'email' => 'nikita@example.test'],
        ])
        ->and(Leave::whereNotNull('elevatehr_reference')->sole()->getAttributes())->toBe($original)
        ->and((int) DB::table('elevate_webhook_daily_stats')->sole()->failed)->toBe(1);
})->with(['leave.approved', 'leave.partial_recall', 'leave.recalled']);

test('duplicate AD matches cannot create leave or custom leave types', function () {
    DB::table('employees')->insert(['id' => 333, 'organization_id' => 1, 'ad_employee_id' => 'AD-00555']);
    $this->postJson($this->url, elevateHrPayload())->assertUnprocessable();
    expect(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and(LeaveType::count())->toBe(0)
        ->and(DB::table('failed_elevate_webhooks')->count())->toBe(1);
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
    Log::shouldReceive('error')
        ->once()
        ->with('REJECTED ELEVATE-HR WEBHOOK... REQUEST FROM UNKNOWN IP.', [
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
    DB::table('employees')->insert(['id' => 333, 'organization_id' => 1, 'ad_employee_id' => '333']);
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
    [['leave' => ['employee_number' => str_repeat('x', 256)]]],
    [['leave' => ['employee_number' => 5555555]]],
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
    expect(ElevateHrWebhook::count())->toBe(2)
        ->and(ElevateHrWebhook::latest('id')->first()->creator_name)->toBe('Command line')
        ->and(ElevateHrWebhook::whereNull('revoked_at')->sole()->token_hash)->toBe($newHash);
});

test('imports the configured webhook as active legacy history without changing its credentials', function () {
    $webhook = ElevateHrWebhook::sole();
    expect($webhook->creator_name)->toBe('Legacy setup (creator unknown)')
        ->and($webhook->created_by)->toBeNull()
        ->and($webhook->revoked_at)->toBeNull()
        ->and($webhook->token_hash)->toBe(OrganizationSetting::sole()->value);
    $this->postJson($this->url, elevateHrPayload())->assertOk();
});

test('a permitted manager can generate and copy a replacement with an auditable creator', function () {
    $this->freezeTime();
    $user = elevateWebhookManager();
    $legacy = ElevateHrWebhook::sole();
    $component = Volt::actingAs($user)->test('admin.account-settings.elevate_webhook')
        ->assertSee('Replace webhook')
        ->call('generateWebhook')
        ->assertSee('Copy URL')
        ->assertSee('Webhook Manager 1');
    $url = $component->get('generatedUrl');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $active = ElevateHrWebhook::whereNull('revoked_at')->sole();

    expect($active->created_by)->toBe($user->id)
        ->and($active->creator_name)->toBe($user->name)
        ->and($active->token_hash)->toBe(hash('sha256', $query['token']))
        ->and($active->created_at->equalTo(now()))->toBeTrue()
        ->and($legacy->fresh()->revoked_at->equalTo(now()))->toBeTrue()
        ->and($active->toArray())->not->toHaveKey('token_hash')
        ->and(DB::table('elevate_hr_webhooks')->get()->toJson())->not->toContain($query['token']);
    $this->postJson($url, elevateHrPayload())->assertOk();
    $this->postJson($this->url, elevateHrPayload())->assertForbidden();

    $this->travel(1)->minute();
    $component->call('generateWebhook');
    expect(ElevateHrWebhook::whereNull('revoked_at')->count())->toBe(1)
        ->and($active->fresh()->revoked_at->equalTo(now()))->toBeTrue();
    $this->postJson($url, elevateHrPayload())->assertForbidden();
    $this->postJson($this->url, elevateHrPayload())->assertForbidden();
    $this->postJson($component->get('generatedUrl'), elevateHrPayload())->assertOk();
});

test('can generate the first webhook when none is configured', function () {
    OrganizationSetting::query()->delete();
    ElevateHrWebhook::query()->delete();
    Volt::actingAs(elevateWebhookManager())->test('admin.account-settings.elevate_webhook')
        ->assertSee('No webhook has been generated yet.')
        ->assertSee('Generate webhook')
        ->assertSee('No webhooks generated yet.')
        ->call('generateWebhook')
        ->assertSee('An active webhook is configured.')
        ->assertSee('Copy URL');
    expect(ElevateHrWebhook::whereNull('revoked_at')->count())->toBe(1)
        ->and(OrganizationSetting::sole()->value)->toBe(ElevateHrWebhook::sole()->token_hash);
});

test('webhook management requires permission even when accessed directly', function () {
    Volt::test('admin.account-settings.elevate_webhook')->assertForbidden();
    Volt::actingAs(elevateWebhookManager(grantPermission: false))
        ->test('admin.account-settings.elevate_webhook')->assertForbidden();
    expect(ElevateHrWebhook::count())->toBe(1);
});

test('generation rechecks permission on every action', function () {
    $user = elevateWebhookManager();
    $component = Volt::actingAs($user)->test('admin.account-settings.elevate_webhook');
    $user->revokePermissionTo('manage-elevate-callback');
    $component->call('generateWebhook')->assertForbidden();
    expect(ElevateHrWebhook::count())->toBe(1);
});

test('webhook management rejects users without an organisation', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-elevate-callback');
    Volt::actingAs($user)->test('admin.account-settings.elevate_webhook')->assertForbidden();
});

test('history returns exactly the latest ten records for the current organisation and retains older records', function () {
    $service = app(ElevateHrWebhookService::class);
    $manager = elevateWebhookManager();
    foreach (range(1, 12) as $generation) {
        $service->generate(1, $manager);
    }
    $service->generate(2, elevateWebhookManager(2));
    $expectedIds = ElevateHrWebhook::where('organization_id', 1)->latest('id')->limit(10)->pluck('id')->all();
    Volt::actingAs($manager)->test('admin.account-settings.elevate_webhook')
        ->assertViewHas('history', fn ($history) => $history->pluck('id')->all() === $expectedIds)
        ->assertSee('Webhook Manager 1')
        ->assertDontSee('Webhook Manager 2')
        ->assertDontSee('Legacy setup (creator unknown)')
        ->assertDontSee('Copy URL');
    expect(ElevateHrWebhook::where('organization_id', 1)->count())->toBe(13)
        ->and(ElevateHrWebhook::where('organization_id', 1)->whereNull('revoked_at')->count())->toBe(1)
        ->and(ElevateHrWebhook::where('organization_id', 2)->whereNull('revoked_at')->count())->toBe(1);
});

test('a manager cannot generate or revoke webhooks for another organisation', function () {
    $otherUrl = app(ElevateHrWebhookService::class)->generate(2);
    $otherWebhook = ElevateHrWebhook::where('organization_id', 2)->sole();
    Volt::actingAs(elevateWebhookManager())->test('admin.account-settings.elevate_webhook')
        ->call('generateWebhook');
    expect($otherWebhook->fresh()->revoked_at)->toBeNull()
        ->and(OrganizationSetting::where('organization_id', 2)->sole()->value)->toBe($otherWebhook->token_hash);
    $this->postJson($otherUrl, elevateHrPayload(['leave' => ['employee_number' => '222']]))->assertOk();
});

test('a failed replacement rolls back revocation and preserves the previous active URL', function () {
    $legacy = ElevateHrWebhook::sole();
    Event::listen('eloquent.creating: '.ElevateHrWebhook::class, function () {
        throw new RuntimeException('Simulated webhook history failure');
    });
    expect(fn () => app(ElevateHrWebhookService::class)->generate(1))
        ->toThrow(RuntimeException::class, 'Simulated webhook history failure');
    expect(ElevateHrWebhook::count())->toBe(1)
        ->and($legacy->fresh()->revoked_at)->toBeNull()
        ->and(OrganizationSetting::sole()->value)->toBe($legacy->token_hash);
    $this->postJson($this->url, elevateHrPayload())->assertOk();
});

test('a URL replaced from another session is no longer offered for copying', function () {
    $manager = elevateWebhookManager();
    $component = Volt::actingAs($manager)->test('admin.account-settings.elevate_webhook')
        ->call('generateWebhook')->assertSee('Copy URL');
    $url = $component->get('generatedUrl');
    $this->artisan('elevatehr:leave-webhook', ['organization' => 1])->assertSuccessful();
    $component->call('$refresh')->assertDontSee('Copy URL')
        ->assertSee('This URL has been replaced and can no longer be used.');
    $this->postJson($url, elevateHrPayload())->assertForbidden();
});

test('creator audit details remain available after the user is deleted', function () {
    $manager = elevateWebhookManager();
    app(ElevateHrWebhookService::class)->generate(1, $manager);
    $manager->delete();
    $webhook = ElevateHrWebhook::whereNull('revoked_at')->sole();
    expect($webhook->created_by)->toBeNull()
        ->and($webhook->creator_name)->toBe('Webhook Manager 1');
});

test('rolling back webhook history preserves the active endpoint credentials', function () {
    (require database_path('migrations/2026_10_28_090000_create_elevate_hr_webhooks_table.php'))->down();
    expect(Schema::hasTable('elevate_hr_webhooks'))->toBeFalse()
        ->and(OrganizationSetting::sole()->value)->toBe(hash('sha256', str_repeat('a', 64)));
    $this->postJson($this->url, elevateHrPayload())->assertOk();
});

test('the new migration can be rolled back without dropping existing leave', function () {
    (require database_path('migrations/2026_10_22_120000_add_elevatehr_leave_support.php'))->down();
    expect(Schema::hasTable('leave_types'))->toBeFalse()
        ->and(Schema::hasColumn('leaves', 'num_of_days'))->toBeFalse()
        ->and(DB::table('leaves')->count())->toBe(1);
});

function configureElevateAlerts(int $threshold = 2, string $frequency = 'once_daily'): void
{
    DB::table('organization_settings')->insert([
        'organization_id' => 1,
        'key' => ElevateHrWebhookMonitor::SETTINGS_KEY,
        'type' => 'json',
        'value' => json_encode([
            'threshold' => $threshold,
            'emails' => ['operations@example.test', 'hr@example.test'],
            'frequency' => $frequency,
        ]),
    ]);
}

test('counts successful duplicate deliveries without recording failures', function () {
    $payload = elevateHrPayload();
    $this->postJson($this->url, $payload)->assertOk();
    $this->postJson($this->url, $payload)->assertOk();

    $stats = DB::table('elevate_webhook_daily_stats')->sole();
    expect((int) $stats->received)->toBe(2)
        ->and((int) $stats->failed)->toBe(0)
        ->and($stats->date)->toBe(now()->toDateString())
        ->and(DB::table('failed_elevate_webhooks')->count())->toBe(0);
});

test('persists middleware failures with their specific cause and no credentials or payload', function () {
    $this->postJson('/api/webhooks/elevatehr/leave/1?token=wrong', elevateHrPayload())->assertForbidden();
    $failure = DB::table('failed_elevate_webhooks')->sole();

    expect($failure->reason)->toBe('Missing, invalid or revoked webhook token.')
        ->and((int) $failure->status_code)->toBe(403)
        ->and($failure->source_ip)->toBe('127.0.0.1')
        ->and($failure->metadata)->toBeNull()
        ->and(json_encode($failure))->not->toContain('Nikita', 'wrong', 'token=');
    expect((int) DB::table('elevate_webhook_daily_stats')->sole()->failed)->toBe(1);

    config(['services.elevatehr.webhook_allowed_ips' => []]);
    $this->postJson($this->url, elevateHrPayload())->assertForbidden();
    expect(DB::table('failed_elevate_webhooks')->latest('id')->first()->reason)
        ->toBe('Request source IP is not allowed.');
});

test('records content type and body size rejections', function () {
    $this->post($this->url, elevateHrPayload())->assertStatus(415);
    $this->postJson($this->url, ['padding' => str_repeat('x', 262145)])->assertStatus(413);
    expect(DB::table('failed_elevate_webhooks')->orderBy('id')->pluck('reason')->all())
        ->toBe(['A JSON payload is required.', 'Webhook payload is too large.']);
    $stats = DB::table('elevate_webhook_daily_stats')->sole();
    expect((int) $stats->received)->toBe(2)->and((int) $stats->failed)->toBe(2);
});

test('records controller validation and business rule failures exactly once', function () {
    $this->postJson($this->url, elevateHrPayload(['event_id' => 'invalid']))->assertUnprocessable();
    $this->postJson($this->url, elevateHrPayload(['leave' => ['status' => 'PENDING']]))->assertUnprocessable();
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => '999']]))->assertUnprocessable();
    $failures = DB::table('failed_elevate_webhooks')->orderBy('id')->get();

    expect($failures)->toHaveCount(3)
        ->and(json_decode($failures[0]->validation_errors, true))->toHaveKey('event_id')
        ->and($failures[1]->reason)->toBe('Active leave must be approved and contain remaining leave days.')
        ->and(json_decode($failures[2]->validation_errors, true)['leave.employee_number'])
        ->toBe(['No employee found in this organisation for ad_employee_id ==> 999']);
    $stats = DB::table('elevate_webhook_daily_stats')->sole();
    expect((int) $stats->received)->toBe(3)->and((int) $stats->failed)->toBe(3);
});

test('retains failure records after the leave transaction rolls back without leaking server errors', function () {
    Event::listen('eloquent.saving: '.Leave::class, function () {
        throw new RuntimeException('Sensitive database detail');
    });

    $this->postJson($this->url, elevateHrPayload())->assertStatus(500);
    $failure = DB::table('failed_elevate_webhooks')->sole();
    expect((int) $failure->status_code)->toBe(500)
        ->and($failure->reason)->toStartWith('Unexpected webhook processing error')
        ->and(json_encode($failure))->not->toContain('Sensitive database detail')
        ->and(LeaveType::count())->toBe(0)
        ->and(Leave::whereNotNull('elevatehr_reference')->count())->toBe(0)
        ->and((int) DB::table('elevate_webhook_daily_stats')->sole()->failed)->toBe(1);
});

test('counts rate limited requests outside the validation middleware', function () {
    foreach (range(1, 120) as $delivery) {
        $this->postJson('/api/webhooks/elevatehr/leave/1?token=wrong', [])->assertForbidden();
    }
    $this->postJson($this->url, elevateHrPayload())->assertStatus(429);
    expect((int) DB::table('failed_elevate_webhooks')->latest('id')->first()->status_code)->toBe(429)
        ->and(DB::table('failed_elevate_webhooks')->count())->toBe(121);
    $stats = DB::table('elevate_webhook_daily_stats')->sole();
    expect((int) $stats->received)->toBe(121)->and((int) $stats->failed)->toBe(121);
});

test('keeps daily totals separate and retains failures for unknown organisation IDs', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $this->postJson($this->url, elevateHrPayload())->assertOk();
    $this->travel(1)->day();
    $this->postJson($this->url, [])->assertUnprocessable();
    $this->postJson('/api/webhooks/elevatehr/leave/999?token=wrong', [])->assertForbidden();
    expect(DB::table('elevate_webhook_daily_stats')->count())->toBe(3)
        ->and((int) DB::table('elevate_webhook_daily_stats')->where('organization_id', '1')->sum('received'))->toBe(2)
        ->and((int) DB::table('elevate_webhook_daily_stats')->where('organization_id', '1')->sum('failed'))->toBe(1)
        ->and(DB::table('failed_elevate_webhooks')->where('organization_id', '999')->count())->toBe(1);
});

test('configures validated alerts on the generation page without rotating the token', function () {
    $user = elevateWebhookManager();
    $hash = OrganizationSetting::where('key', 'elevatehr_leave_webhook_token_hash')->value('value');
    Volt::actingAs($user)->test('admin.account-settings.elevate_webhook')
        ->set('failureThreshold', 3)
        ->set('notificationEmails', "HR@example.test, operations@example.test\nhr@example.test")
        ->set('alertFrequency', 'every_threshold')
        ->call('saveAlertSettings')
        ->assertHasNoErrors()
        ->assertSee('Alert settings saved.');

    expect(app(ElevateHrWebhookMonitor::class)->settings('1'))->toBe([
        'threshold' => 3,
        'emails' => ['hr@example.test', 'operations@example.test'],
        'frequency' => 'every_threshold',
    ])->and(OrganizationSetting::where('key', 'elevatehr_leave_webhook_token_hash')->value('value'))->toBe($hash)
        ->and(ElevateHrWebhook::count())->toBe(1)
        ->and(app(ElevateHrWebhookMonitor::class)->settings('2')['emails'])->toBe([]);
    Volt::actingAs($user)->test('admin.account-settings.elevate_webhook')
        ->assertSet('failureThreshold', 3)
        ->assertSet('notificationEmails', 'hr@example.test, operations@example.test')
        ->assertSet('alertFrequency', 'every_threshold');
});

test('rejects invalid alert settings', function ($field, $value, $error) {
    Volt::actingAs(elevateWebhookManager())->test('admin.account-settings.elevate_webhook')
        ->set($field, $value)->call('saveAlertSettings')->assertHasErrors([$error]);
    expect(OrganizationSetting::where('key', ElevateHrWebhookMonitor::SETTINGS_KEY)->exists())->toBeFalse();
})->with([
    ['failureThreshold', 0, 'failureThreshold'],
    ['failureThreshold', 1.5, 'failureThreshold'],
    ['failureThreshold', 1000001, 'failureThreshold'],
    ['notificationEmails', 'not-an-email', 'notificationEmails.0'],
    ['notificationEmails', implode(',', array_map(fn ($n) => "person{$n}@example.test", range(1, 21))), 'notificationEmails'],
    ['alertFrequency', 'every_request', 'alertFrequency'],
]);

test('saving alerts rechecks permission', function () {
    $user = elevateWebhookManager();
    $component = Volt::actingAs($user)->test('admin.account-settings.elevate_webhook');
    $user->revokePermissionTo('manage-elevate-callback');
    $component->call('saveAlertSettings')->assertForbidden();
    expect(OrganizationSetting::where('key', ElevateHrWebhookMonitor::SETTINGS_KEY)->exists())->toBeFalse();
});

test('shows scoped daily overall statistics and exactly five latest failure causes', function () {
    foreach (range(1, 6) as $delivery) {
        $this->postJson($this->url, [])->assertUnprocessable();
    }
    $this->postJson('/api/webhooks/elevatehr/leave/2?token=wrong', [])->assertForbidden();
    $expectedIds = DB::table('failed_elevate_webhooks')->where('organization_id', '1')
        ->latest('id')->limit(5)->pluck('id')->all();
    Volt::actingAs(elevateWebhookManager())->test('admin.account-settings.elevate_webhook')
        ->assertViewHas('overallStats', fn ($stats) => (int) $stats->received === 6 && (int) $stats->failed === 6)
        ->assertViewHas('todayStats', fn ($stats) => (int) $stats->received === 6)
        ->assertViewHas('recentFailures', fn ($failures) => $failures->pluck('id')->all() === $expectedIds)
        ->assertSee('Invalid leave event.')
        ->assertDontSee('Missing, invalid or revoked webhook token.');
});

test('sends email to configured recipients exactly at threshold once daily and resets the next day', function () {
    Notification::fake();
    configureElevateAlerts();
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertNothingSent();
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertSentOnDemand(ElevateWebhookFailureAlert::class, function ($notification, $channels, $notifiable) {
        return $notification->failed === 2 && $notification->received === 2
            && $notifiable->routes['mail'] === ['operations@example.test', 'hr@example.test']
            && count($notification->reasons) === 2;
    });
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertCount(1);

    $this->travel(1)->day();
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertCount(1);
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertCount(2);
});

test('repeats email at threshold multiples and deduplicates queued jobs', function () {
    Notification::fake();
    configureElevateAlerts(frequency: 'every_threshold');
    foreach (range(1, 5) as $delivery) {
        $this->postJson($this->url, [])->assertUnprocessable();
    }
    Notification::assertCount(2);
    app()->call([new SendElevateWebhookFailureAlert('1', now()->toDateString()), 'handle']);
    Notification::assertCount(2);
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertCount(3);
});

test('queues alerts and leaves the notification count unchanged until delivery succeeds', function () {
    Queue::fake();
    configureElevateAlerts(threshold: 1);
    $this->postJson($this->url, [])->assertUnprocessable();
    Queue::assertPushed(SendElevateWebhookFailureAlert::class);
    expect((int) DB::table('elevate_webhook_daily_stats')->sole()->last_notified_failed)->toBe(0);
});

test('email failures preserve webhook results and remain retryable without losing failure logs', function () {
    configureElevateAlerts(threshold: 1);
    Notification::shouldReceive('route')->andThrow(new RuntimeException('Mail transport unavailable'));
    $this->postJson($this->url, [])->assertUnprocessable();
    expect(DB::table('failed_elevate_webhooks')->count())->toBe(1)
        ->and((int) DB::table('elevate_webhook_daily_stats')->sole()->failed)->toBe(1)
        ->and((int) DB::table('elevate_webhook_daily_stats')->sole()->last_notified_failed)->toBe(0);
    expect(fn () => app()->call([new SendElevateWebhookFailureAlert('1', now()->toDateString()), 'handle']))
        ->toThrow(RuntimeException::class, 'Mail transport unavailable');
});

test('empty recipient settings disable email without disabling monitoring', function () {
    Notification::fake();
    Volt::actingAs(elevateWebhookManager())->test('admin.account-settings.elevate_webhook')
        ->set('failureThreshold', 1)->set('notificationEmails', '')
        ->call('saveAlertSettings')->assertHasNoErrors();
    $this->postJson($this->url, [])->assertUnprocessable();
    Notification::assertNothingSent();
    expect(DB::table('failed_elevate_webhooks')->count())->toBe(1);
});

test('monitoring migration rolls back without deleting leave or webhook generation history', function () {
    (require database_path('migrations/2026_11_04_090000_create_elevate_webhook_monitoring_tables.php'))->down();
    expect(Schema::hasTable('failed_elevate_webhooks'))->toBeFalse()
        ->and(Schema::hasTable('elevate_webhook_daily_stats'))->toBeFalse()
        ->and(ElevateHrWebhook::count())->toBe(1)
        ->and(Leave::count())->toBe(1);
});

test('failure metadata migration rolls back without deleting existing failure records', function () {
    $this->postJson($this->url, elevateHrPayload(['leave' => ['employee_number' => '999']]))->assertUnprocessable();
    (require database_path('migrations/2026_11_04_100000_add_metadata_to_failed_elevate_webhooks_table.php'))->down();

    expect(Schema::hasColumn('failed_elevate_webhooks', 'metadata'))->toBeFalse()
        ->and(DB::table('failed_elevate_webhooks')->count())->toBe(1);
});
