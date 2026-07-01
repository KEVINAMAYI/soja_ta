<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use App\Models\ZkbioArea;
use App\Models\WorkLocation;
use App\Models\EmployeeAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use App\Helpers\PhoneSanitizer;
use App\Services\ZKBioPersonService;
use Spatie\Permission\Models\Role as SpatieRole;
use Livewire\WithFileUploads;
use App\Services\MicrosoftAdService;

new class extends Component {

    use WithFileUploads;

    public array $grades = [
        'Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5', 'Year 6', 'Year 7', 'Year 8'
    ];

    public ?string $grade = null;
    public $name, $email, $phone, $employee_type_id, $department_id, $id_number, $active = true;
    public $editId, $employeeTypes, $departments;
    public $roleId;
    public $shifts;
    public $shift_id;
    public $role;
    public $employeeId;
    public $search = '';
    public $workLocations = [];
    public $selectedLocation = null;
    public $start_date;
    public $end_date;
    public $roleName = 'employee';
    public $roles = [];
    public $employee_title;
    public $editEmployee = null;
    public $employeeName;
    public $start_off_shift_date;
    public $end_off_shift_date;
    public $shiftStatus;
    public bool $isStudentOrg = false;

    public $presentCount = 0;
    public $leftSchoolCount = 0;
    public $notReportedCount = 0;

    #[Url(as: 'type')]
    public string $personType = 'student';

    public string $userType = '';

    public $totalEmployees = 0;
    public $activeEmployees = 0;
    public $inactiveEmployees = 0;

    public array $empTypeTotals = [];  // ['COSMOS' => 120, 'Outsourced' => 45]
    public array $empTypeActive = [];
    public array $empTypeInactive = [];

    public $totalStudents = 0;
    public $totalStaff = 0;

    public $staffPresentCount = 0;
    public $staffLeftCount = 0;
    public $staffNotReportedCount = 0;

    // ── BULK IMPORT STATE ────────────────────────────────────────────────────
    public bool $showImportPanel = false;
    public bool $importParsed = false;
    public bool $importProcessed = false;
    public array $importPreview = [];   // rows shown before commit
    public array $importResults = [];   // per-row result after commit
    public int $importSuccessCount = 0;
    public int $importErrorCount = 0;
    public ?string $importError = null;
    public $importFile;

    // ── AD SYNC STATE ─────────────────────────────────────────────────────────
    public bool $showAdSyncPanel = false;
    public bool $adSyncPreviewed = false;
    public bool $adSyncProcessed = false;
    public array $adPreview = [];
    public array $adResults = [];
    public int $adImportedCount = 0;
    public int $adUpdatedCount = 0;
    public int $adErrorCount = 0;
    public ?string $adSyncError = null;
    public bool $adSyncing = false;
    public ?string $adLastSynced = null;
    public array $selectedAdUsers = [];

    //MANAGING AREAS
    public ?Employee $employee = null;
    public array $selectedAreas = [];
    public array $availableAreas = [];
    public bool $syncing = false;

    // Add to the AD SYNC STATE section:
    public array $availableZkbioAreas = [];
    public array $defaultAdSyncAreas = [];

    public string $empTypeFilter = '';
    public string $activeFilter = '';
    public int $adDeactivatedCount = 0;


    public function mount($roleId = null): void
    {

        $this->userType = $this->personType;
        $this->roleId = $roleId;
        $this->editEmployee = auth()->user()->employee;

        if ($roleId) {
            $this->role = SpatieRole::find($roleId);
        }

        $org = auth()->user()->employee->organization;
        $this->isStudentOrg = (bool)($org->is_student_record ?? false);

        if ($this->isStudentOrg && !in_array($this->personType, ['student', 'staff'])) {
            $this->personType = 'student';
        }

        $this->departments = $org->departments;
        $this->shifts = $org->shifts;

        $orgId = $org->id;

        if ($this->isStudentOrg) {
            $this->roles = SpatieRole::where('organization_id', $orgId)
                ->whereIn('name', ['staff', 'school-admin', 'supervisor'])
                ->pluck('name', 'id');
        } else {
            $this->roles = SpatieRole::where('name', '!=', 'super-admin')
                ->where('organization_id', $orgId)
                ->pluck('name', 'id');
        }

        $this->loadSummaryStats();

        if ($this->isStudentOrg && $this->personType === 'student') {
            $this->loadStudentAttendanceStats();
        }

        if ($this->isStudentOrg) {
            $this->dispatch('filter-by-type', type: $this->personType);
        }


        $this->shift_id = $this->shifts->firstWhere('name', 'Day Shift')?->id
            ?? $this->shifts->firstWhere('name', 'Day')?->id
            ?? $this->shifts->first()?->id
            ?? null;

    }


    #[On('sync-to-zkbio')]
    public function syncToZkbio(int $employeeId): void
    {

        $org = auth()->user()->employee->organization;
        $emp = Employee::with('department')->find($employeeId);
        $token = $org->zkbio_access_token;
        $base = $org->zkbio_base_url;

        $parts = explode(' ', trim($emp->name), 2);
        $phone = !empty($emp->phone)
            ? $emp->phone
            : '254' . str_pad($emp->zkbio_pin, 9, '0', STR_PAD_LEFT);

        $ch = curl_init("{$base}/api/person/add?access_token={$token}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'pin' => (string)$emp->zkbio_pin,
            'name' => $parts[0],
            'lastName' => $parts[1] ?? '',
            'mobilePhone' => $phone,
            'ssn' => $emp->id_number ?? '',
            'cardNo' => '',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (($res['code'] ?? -1) !== 0) {
            LivewireAlert::title('Sync Failed')
                ->text($res['message'] ?? 'Unknown error')
                ->error()->toast()->position('top-end')->show();
            return;
        }

        LivewireAlert::title('Synced!')
            ->text("{$emp->name} synced to ZKBio successfully.")
            ->success()->toast()->position('top-end')->show();
    }


    public function exportFilteredExcel(): mixed
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\EmployeesExcelExport(
                selectedIds: [],
                empTypeFilter: $this->empTypeFilter,
                activeFilter: $this->activeFilter,
            ),
            'employees_' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function exportFilteredPdf(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('employees.export.pdf', [
            'emp_type' => $this->empTypeFilter,
            'active' => $this->activeFilter,
        ]);
    }


    public function toggleAdSyncPanel(): void
    {
        $this->showAdSyncPanel = !$this->showAdSyncPanel;

        if ($this->showAdSyncPanel) {
            $this->loadZkbioAreas();
        } else {
            $this->resetAdSync();
        }
    }


    public function loadZkbioAreas(): void
    {
        $org = auth()->user()->employee->organization;
        $service = app(\App\Services\ZKBioPersonService::class, ['organization' => $org]);

        // Use cached, sync if empty
        $areas = $service->getCachedAreas(4);
        if ($areas->isEmpty()) {
            $service->syncAreas();
            $areas = $service->getCachedAreas(4);
        }

        $this->availableZkbioAreas = $areas->map(fn($a) => [
            'area_code' => $a->area_code,
            'area_name' => $a->area_name,
        ])->toArray();
    }

    public function resetAdSync(): void
    {
        $this->reset([
            'adPreview', 'adResults', 'adSyncPreviewed',
            'adSyncProcessed', 'adSyncError',
            'adImportedCount', 'adUpdatedCount', 'adErrorCount', 'selectedAdUsers', 'defaultAdSyncAreas'
        ]);
        $this->adSyncing = false;
    }

    public function previewAdSync(): void
    {
        $this->adSyncing = true;
        $this->adSyncError = null;

        try {
            $ad = app(MicrosoftAdService::class);
            $users = $ad->filterValidUsers($ad->getAllUsers());
            $org = auth()->user()->employee->organization;

            $existingByAdId = Employee::where('organization_id', $org->id)
                ->whereNotNull('ad_object_id')
                ->pluck('ad_object_id')->flip();

            $existingByEmail = Employee::where('organization_id', $org->id)
                ->pluck('email')->filter()->flip();

            $defaultShift = $org->shifts->firstWhere('name', 'Day Shift')
                ?? $org->shifts->firstWhere('name', 'Day')
                ?? $org->shifts->first();

            $this->adPreview = [];

            foreach ($users as $user) {
                $phone = $user['mobilePhone'] ?? ($user['businessPhones'][0] ?? null);
                if (in_array($phone, ['0000000000', '-', ''])) $phone = null;

                $adEmail = $user['mail'] ?? $user['userPrincipalName'] ?? '';

                $isNew = !isset($existingByAdId[$user['id']])
                    && ($adEmail === '' || !isset($existingByEmail[$adEmail]));

                // Parse section & division from DN
                $ous = [];
                if (!empty($user['onPremisesDistinguishedName'])) {
                    $ous = $ad->parseDnOUs($user['onPremisesDistinguishedName']);
                }

                $this->adPreview[] = [
                    'ad_id' => $user['id'],
                    'name' => $user['displayName'],
                    'email' => $user['mail'] ?? $user['userPrincipalName'],
                    'phone' => $phone ?? '?',
                    'job_title' => $user['jobTitle'] ?? '?',
                    'upn' => $user['userPrincipalName'],
                    'shift' => $defaultShift?->name ?? '?',
                    'department' => $user['department'] ?? null,   // from AD
                    'employee_id' => $user['employeeId'] ?? null,   // e.g. M1ALI748
                    'section' => $ous['section'] ?? null,
                    'division' => $ous['division'] ?? null,
                    'isNew' => $isNew,
                ];
            }


            // ── Find locally linked employees disabled or removed from AD ──
            $activeAdIds = collect($this->adPreview)->pluck('ad_id')->toArray();

            $disabledAdIds = collect($users)
                ->filter(fn($u) => ($u['accountEnabled'] ?? true) === false)
                ->pluck('id')
                ->toArray();

            $toDeactivate = Employee::where('organization_id', $org->id)
                ->whereNotNull('ad_object_id')
                ->whereNull('deleted_at')
                ->get()
                ->filter(fn($emp) => in_array($emp->ad_object_id, $disabledAdIds) ||
                    !in_array($emp->ad_object_id, $activeAdIds)
                );

            foreach ($toDeactivate as $emp) {
                $this->adPreview[] = [
                    'ad_id' => $emp->ad_object_id,
                    'name' => $emp->name,
                    'email' => $emp->email,
                    'phone' => $emp->phone ?? '—',
                    'job_title' => $emp->employee_title ?? '—',
                    'upn' => $emp->ad_upn ?? '—',
                    'shift' => '—',
                    'department' => $emp->department?->name ?? '—',
                    'employee_id' => $emp->ad_employee_id ?? null,
                    'section' => $emp->section ?? null,
                    'division' => $emp->division ?? null,
                    'isNew' => false,
                    'action' => in_array($emp->ad_object_id, $disabledAdIds) ? 'disabled' : 'removed',
                ];
            }

            $this->adSyncPreviewed = true;

        } catch (\Throwable $e) {
            $this->adSyncError = 'Failed to fetch from AD: ' . $e->getMessage();
        }

        $this->adSyncing = false;
    }

    public function commitAdSync(): void
    {
        if (empty($this->adPreview)) return;

        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $org = auth()->user()->employee->organization;
        $defaultShift = $org->shifts->firstWhere('name', 'Day Shift')
            ?? $org->shifts->firstWhere('name', 'Day')
            ?? $org->shifts->first();
        $defaultDept = $org->departments->first();
        $defaultLocation = WorkLocation::where('organization_id', $org->id)->where('is_default', true)->first()
            ?? WorkLocation::where('organization_id', $org->id)->first();

        $rows = empty($this->selectedAdUsers)
            ? $this->adPreview
            : array_filter($this->adPreview, fn($r) => in_array($r['ad_id'], $this->selectedAdUsers));

        $results = [];
        $imported = 0;
        $updated = 0;
        $errors = 0;

        foreach ($rows as $row) {
            try {
                DB::beginTransaction();
                $phone = $row['phone'] === '—' ? null : $row['phone'];

                $existing = Employee::where('organization_id', $org->id)
                    ->where(function ($q) use ($row) {
                        $q->where('ad_object_id', $row['ad_id'])
                            ->orWhere('email', $row['email'])
                            ->orWhere('id_number', 'AD-' . substr($row['ad_id'], 0, 8));
                        if (!empty($row['employee_id'])) {
                            $q->orWhere('ad_employee_id', $row['employee_id']);
                        }
                    })->first();

                if ($existing) {
                    $existing->update([
                        'name' => $row['name'],
                        'email' => $row['email'] ?: $existing->email,
                        'phone' => $phone ?? $existing->phone,
                        'ad_object_id' => $row['ad_id'],
                        'ad_upn' => $row['upn'],
                        'ad_synced_at' => now(),
                        'ad_employee_id' => $row['employee_id'],
                        'section' => $row['section'],
                        'division' => $row['division'],
                        'department_id' => $this->resolveDepartment($org, $row['department']) ?? $existing->department_id,
                    ]);

                    if ($existing->user) {
                        $existing->user->update(['name' => $row['name']]);
                    }

                    DB::commit();

                    if (!$existing->zkbio_pin && $org->zkbio_enabled) {
                        $existing->update(['zkbio_pin' => Employee::generateZKBioPin($org->id)]);
                    }

                    $zkStatus = 'skipped';
                    if (!$existing->zkbio_pin) {
                        $zkStatus = 'no_pin';
                    } elseif ($org->zkbio_sync_enabled && $org->zkbio_base_url && $org->zkbio_access_token) {
                        try {
                            $synced = app(ZKBioPersonService::class, ['organization' => $org])->syncPerson($existing->fresh());
                            $zkStatus = $synced ? 'synced' : 'zk_failed: API returned false';
                        } catch (\Throwable $zkErr) {
                            $zkStatus = 'zk_failed: ' . $zkErr->getMessage();
                            Log::warning("ZKBio re-sync failed for {$row['name']}", ['error' => $zkErr->getMessage()]);
                        }
                    }

                    if (!empty($this->defaultAdSyncAreas) && $existing->zkbio_pin) {
                        app(ZKBioPersonService::class, ['organization' => $org])
                            ->syncEmployeeAreas($existing, $this->defaultAdSyncAreas);
                    }

                    $results[] = ['name' => $row['name'], 'email' => $row['email'], 'status' => 'updated', 'zk' => $zkStatus, 'message' => 'Details updated from AD'];
                    $updated++;

                } else {
                    $email = $row['email'] ?: "ad_{$row['ad_id']}@{$org->id}.local";

                    $user = User::create(['name' => $row['name'], 'email' => $email, 'password' => Hash::make('password')]);

                    $employee = Employee::create([
                        'name' => $row['name'],
                        'email' => $email,
                        'phone' => $phone,
                        'shift_id' => $defaultShift?->id,
                        'shift_status' => 'on_shift',
                        'organization_id' => $org->id,
                        'id_number' => 'AD-' . substr($row['ad_id'], 0, 8),
                        'active' => true,
                        'user_id' => $user->id,
                        'ad_object_id' => $row['ad_id'],
                        'ad_upn' => $row['upn'],
                        'ad_synced_at' => now(),
                        'is_student' => false,
                        'employee_title' => $row['job_title'] !== '—' ? $row['job_title'] : null,
                        'ad_employee_id' => $row['employee_id'],
                        'section' => $row['section'],
                        'division' => $row['division'],
                        'department_id' => $this->resolveDepartment($org, $row['department']) ?? $defaultDept?->id,
                    ]);

                    $user->assignRole('employee');
                    $user->createToken('Api Token')->plainTextToken;

                    if ($defaultLocation) {
                        EmployeeAssignment::updateOrCreate(
                            ['employee_id' => $employee->id],
                            ['work_location_id' => $defaultLocation->id, 'is_current' => true]
                        );
                    }

                    DB::commit();

                    $zkStatus = 'skipped';
                    if ($org->zkbio_sync_enabled && $org->zkbio_base_url && $org->zkbio_access_token) {
                        try {
                            app(ZKBioPersonService::class, ['organization' => $org])->syncPerson($employee->fresh());
                            $zkStatus = 'synced';
                        } catch (\Throwable $zkErr) {
                            $zkStatus = 'zk_failed: ' . $zkErr->getMessage();
                            Log::warning("ZKBio sync failed for AD employee {$row['name']}", ['error' => $zkErr->getMessage()]);
                        }

                        if (!empty($this->defaultAdSyncAreas) && $employee->zkbio_pin) {
                            app(ZKBioPersonService::class, ['organization' => $org])
                                ->assignPersonToAreas($employee, $this->defaultAdSyncAreas);
                            $areaIds = ZkbioArea::where('organization_id', $org->id)
                                ->whereIn('area_code', $this->defaultAdSyncAreas)->pluck('id')->toArray();
                            $employee->zkbioAreas()->sync($areaIds);
                        }
                    } else {
                        $zkStatus = 'skipped (ZKBio not enabled for this org)';
                    }

                    $results[] = ['name' => $row['name'], 'email' => $email, 'status' => 'imported', 'zk' => $zkStatus, 'message' => 'Created from Active Directory'];
                    $imported++;
                }

            } catch (\Throwable $e) {
                DB::rollBack();
                $results[] = ['name' => $row['name'], 'email' => $row['email'], 'status' => 'error', 'zk' => '—', 'message' => $e->getMessage()];
                $errors++;
            }
        }

        $this->adResults = $results;
        $this->adImportedCount = $imported;
        $this->adUpdatedCount = $updated;
        $this->adErrorCount = $errors;
        $this->adDeactivatedCount = 0;
        $this->adSyncProcessed = true;
        $this->adLastSynced = now()->toDateTimeString();

        $this->dispatch('refreshDatatable');
        $this->loadSummaryStats();

        LivewireAlert::title('AD Sync Complete!')
            ->text("{$imported} imported, {$updated} updated, {$errors} failed.")
            ->success()->toast()->position('top-end')->show();
    }


    public function deactivateRemovedAdUsers(): void
    {
        $org = auth()->user()->employee->organization;
        $excludedNames = ['Intern', 'windows', 'N. Tesla Meeting Room', 'Techsupport Identigate', 'Test Role', 'Test User'];

        try {
            $ad = app(MicrosoftAdService::class);
            $liveUsers = $ad->filterValidUsers($ad->getAllUsers());

            $liveAdIds = collect($liveUsers)->pluck('id')->toArray();
            $disabledAdIds = collect($liveUsers)->filter(fn($u) => ($u['accountEnabled'] ?? true) === false)->pluck('id')->toArray();
            $liveEmails = collect($liveUsers)->map(fn($u) => strtolower($u['mail'] ?? $u['userPrincipalName'] ?? ''))->filter()->values();
            $liveNames = collect($liveUsers)->pluck('displayName')->map(fn($n) => strtolower(trim($n)));

            $linked = Employee::where('organization_id', $org->id)
                ->whereNotNull('ad_object_id')->whereNull('deleted_at')->get()
                ->filter(fn($e) => in_array($e->ad_object_id, $disabledAdIds) || !in_array($e->ad_object_id, $liveAdIds))
                ->mapWithKeys(fn($e) => [$e->id => in_array($e->ad_object_id, $disabledAdIds) ? 'disabled' : 'removed']);

            $unlinked = Employee::where('organization_id', $org->id)
                ->whereNull('ad_object_id')->whereNull('deleted_at')
                ->where('employee_type', 'COSMOS')->get()
                ->filter(function ($e) use ($liveEmails, $liveNames, $excludedNames) {
                    if (in_array($e->name, $excludedNames)) return false;
                    return !$liveEmails->contains(strtolower($e->email ?? ''))
                        && !$liveNames->contains(strtolower(trim($e->name ?? '')));
                })
                ->mapWithKeys(fn($e) => [$e->id => 'removed']);

            $toProcess = $linked->union($unlinked);

            if ($toProcess->isEmpty()) {
                LivewireAlert::title('Nothing to do')->text('No employees need deactivation right now.')->info()->toast()->position('top-end')->show();
                return;
            }

            $deactivated = 0;
            $failed = [];

            foreach (Employee::whereIn('id', $toProcess->keys())->get() as $emp) {
                if ($emp->zkbio_pin && $org->zkbio_sync_enabled) {
                    try {
                        app(ZKBioPersonService::class, ['organization' => $org])->deletePerson($emp->zkbio_pin);
                    } catch (\Throwable $zkErr) {
                        Log::warning("ZKBio removal failed for {$emp->id}", ['error' => $zkErr->getMessage()]);
                    }
                }
                try {
                    $emp->zkbioAreas()->detach();
                    $emp->delete();
                    $deactivated++;
                } catch (\Throwable $e) {
                    $failed[] = $emp->name;
                    Log::warning("Soft-delete failed for {$emp->id}", ['error' => $e->getMessage()]);
                }
            }

            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();

            $msg = "{$deactivated} employee(s) deactivated.";
            if ($failed) $msg .= ' Failed: ' . implode(', ', $failed);

            LivewireAlert::title('AD Cleanup Complete')->text($msg)->success()->toast()->position('top-end')->show();

        } catch (\Throwable $e) {
            LivewireAlert::title('Aborted')->text('AD fetch failed, no changes made: ' . $e->getMessage())->error()->toast()->position('top-end')->show();
            Log::error('deactivateRemovedAdUsers failed', ['error' => $e->getMessage()]);
        }
    }


    private function resolveDepartment($org, ?string $deptName): ?int
    {
        if (!$deptName) return null;

        $dept = $org->departments->firstWhere('name', $deptName);

        if (!$dept) {
            $dept = $org->departments()->create([
                'name' => $deptName,
                'organization_id' => $org->id,
            ]);
            $org->unsetRelation('departments');
            $org->load('departments');
        }

        return $dept->id;
    }

    public function toggleImportPanel(): void
    {
        $this->showImportPanel = !$this->showImportPanel;
        if (!$this->showImportPanel) {
            $this->resetImport();
        }
    }

    public function resetImport(): void
    {
        $this->reset([
            'importFile', 'importPreview', 'importResults',
            'importParsed', 'importProcessed', 'importError',
            'importSuccessCount', 'importErrorCount',
        ]);
    }

    public function parseImportFile(): void
    {
        $this->validate(['importFile' => 'required|file|mimes:xlsx,csv,xls|max:5120']);
        $this->importError = null;
        $this->importPreview = [];

        try {
            $path = $this->importFile->getRealPath();
            $ext = strtolower($this->importFile->getClientOriginalExtension());

            if ($ext === 'csv') {
                $rows = array_map('str_getcsv', file($path));
                $headers = array_map('trim', array_shift($rows));
                $data = array_map(fn($r) => array_combine($headers, array_pad($r, count($headers), '')), $rows);
            } else {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
                $sheet = $spreadsheet->getActiveSheet();
                $data = $sheet->toArray(null, true, true, false);
                $headers = array_map('trim', array_shift($data));
                $data = array_filter(array_map(
                    fn($r) => array_combine($headers, array_pad($r, count($headers), '')),
                    $data
                ), fn($r) => !empty(array_filter($r)));
            }

            // Normalise header keys to snake_case
            $normalised = [];
            foreach ($data as $row) {
                $clean = [];
                foreach ($row as $k => $v) {
                    $clean[strtolower(str_replace([' ', '-'], '_', trim($k)))] = trim((string)$v);
                }
                if (!empty(array_filter($clean))) {
                    $normalised[] = $clean;
                }
            }

            if (empty($normalised)) {
                $this->importError = 'The file appears to be empty or has no valid rows.';
                return;
            }

            $this->importPreview = array_slice($normalised, 0, 500); // cap at 500 rows
            $this->importParsed = true;

        } catch (\Throwable $e) {
            $this->importError = 'Could not read file: ' . $e->getMessage();
        }
    }

    public function downloadTemplate(string $format = 'csv'): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response
    {
        $isStudent = $this->isStudentOrg && $this->personType === 'student';

        $headers = $isStudent
            ? ['name', 'id_number', 'grade', 'stream']  // ← removed 'department'
            : ['name', 'email', 'phone', 'id_number', 'department', 'role', 'title'];

        if ($format === 'xlsx') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Friendly display names for headers
            $displayHeaders = $isStudent
                ? ['Full Name', 'ID / Admission No.', 'Year Group', 'Stream']
                : ['Full Name', 'Email Address', 'Phone Number', 'ID Number', 'Department', 'Role', 'Title'];

            $sheet->fromArray([$displayHeaders], null, 'A1');

            // Style the header row
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($displayHeaders));
            $headerRange = "A1:{$lastCol}1";

            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 12,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E14326'],
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'F0DDD8'],
                    ],
                ],
            ]);

            // Row height for header
            $sheet->getRowDimension(1)->setRowHeight(22);

            foreach (range(0, count($headers) - 1) as $col) {
                $sheet->getColumnDimensionByColumn($col + 1)->setAutoSize(true);
            }


            // Add a sample row so users know the format
            $sampleRow = $isStudent
                ? ['Jane Kamau', 'STU-0041', 'Grade 4', 'Green']
                : ['James Odhiambo', 'james@school.ac.ke', '254712345678', '12345678', 'Science', 'staff', 'Teacher'];

            $sheet->fromArray([$sampleRow], null, 'A2');

            $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '94A3B8']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFF5F2'],
                ],
            ]);

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            $filename = match (true) {
                $isStudent => 'student_import_template',
                $this->isStudentOrg => 'staff_import_template',
                default => 'employee_import_template',
            };

            return response()->streamDownload(function () use ($writer) {
                $writer->save('php://output');
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        // Default: CSV
        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fclose($handle);
        }, 'import_template.csv', ['Content-Type' => 'text/csv']);
    }

    public function commitImport(): void
    {
        if (empty($this->importPreview)) return;


        // Map any friendly/alternate header names → canonical keys
        $headerMap = [
            'full_name' => 'name',
            'full name' => 'name',
            'student_name' => 'name',
            'staff_name' => 'name',
            'id_/_admission_no.' => 'id_number',
            'id_/_admission_no' => 'id_number',
            'admission_no' => 'id_number',
            'admission_no.' => 'id_number',
            'student_id' => 'id_number',
            'adm_no' => 'id_number',
            'email_address' => 'email',
            'phone_number' => 'phone',
            'mobile' => 'phone',
            'class' => 'grade',
            'year' => 'grade',
            'year_group' => 'grade',   // ← add this
            'year group' => 'grade',
        ];

        $this->importPreview = array_map(function ($row) use ($headerMap) {
            $normalised = [];
            foreach ($row as $key => $value) {
                $cleanKey = strtolower(str_replace([' ', '-'], '_', trim($key)));
                $normalised[$headerMap[$cleanKey] ?? $cleanKey] = $value;
            }
            return $normalised;
        }, $this->importPreview);

        $org = auth()->user()->employee->organization;
        $isStudent = $this->isStudentOrg && $this->personType === 'student';
        $results = [];
        $success = 0;
        $errors = 0;

        foreach ($this->importPreview as $index => $row) {
            $rowNum = $index + 1;
            try {

                DB::beginTransaction();

                $name = $row['name'] ?? '';
                $idNumber = $row['id_number'] ?? $row['admission_no'] ?? $row['student_id'] ?? '';

                if (empty($name) || empty($idNumber)) {
                    throw new \Exception('Name and ID Number are required.');
                }

                if (\App\Models\Employee::where('id_number', $idNumber)
                    ->where('organization_id', $org->id)->exists()) {
                    throw new \Exception("ID '{$idNumber}' already exists.");
                }

                // Resolve shift
                $shiftId = $org->shifts->firstWhere('name', 'Day Shift')?->id
                    ?? $org->shifts->first()?->id;

                // Resolve department — create on the fly if name given but not found
                $deptName = trim($row['department'] ?? '');
                if ($deptName !== '') {
                    $dept = $org->departments->firstWhere('name', $deptName);
                    if (!$dept) {
                        $dept = $org->departments()->create([
                            'name' => $deptName,
                            'organization_id' => $org->id,
                        ]);
                        // Refresh the cached collection so subsequent rows can find it
                        $org->unsetRelation('departments');
                        $org->load('departments');
                    }
                } else {
                    $dept = $org->departments->first();
                }
                $deptId = $dept?->id;

                $roleName = $isStudent ? 'student' : ($row['role'] ?? 'employee');
                $email = $isStudent
                    ? "student_{$idNumber}@{$org->id}.local"
                    : ($row['email'] ?? '');
                $phone = $isStudent
                    ? ($org->phone_number ?? '')
                    : PhoneSanitizer::sanitize($row['phone'] ?? '');

                $user = User::create([
                    'name' => $name,
                    'email' => $email ?: "auto_{$idNumber}_{$org->id}@internal.local",
                    'password' => Hash::make('password'),
                ]);

                $employee = Employee::create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'shift_id' => $shiftId,
                    'organization_id' => $org->id,
                    'id_number' => $idNumber,
                    'active' => true,
                    'user_id' => $user->id,
                    'department_id' => $deptId,
                    'grade' => $isStudent ? ($row['grade'] ?? null) : null,
                    'employee_title' => $row['stream'] ?? $row['title'] ?? null,
                    'is_student' => $isStudent ? 1 : 0,
                ]);

                $user->assignRole($roleName);
                $user->createToken('Api Token')->plainTextToken;

                // Default work location
                $defaultLocation = WorkLocation::where('organization_id', $org->id)
                    ->where('is_default', true)->first()
                    ?? WorkLocation::where('organization_id', $org->id)->first();

                if ($defaultLocation) {
                    EmployeeAssignment::updateOrCreate(
                        ['employee_id' => $employee->id],
                        ['work_location_id' => $defaultLocation->id, 'start_date' => null, 'end_date' => null, 'is_current' => true]
                    );
                }

                DB::commit();

                // ── ZKBio Sync ──────────────────────────────────────────────
                $zkStatus = 'skipped';
                if ($org->zkbio_sync_enabled && $org->zkbio_base_url && $org->zkbio_access_token) {
                    try {
                        app(\App\Services\ZKBioPersonService::class, ['organization' => $org])
                            ->syncPerson($employee->fresh());
                        $zkStatus = 'synced';
                    } catch (\Throwable $zkErr) {
                        $zkStatus = 'zk_failed: ' . $zkErr->getMessage();
                    }
                }

                $results[] = [
                    'row' => $rowNum,
                    'name' => $name,
                    'id' => $idNumber,
                    'status' => 'success',
                    'zk' => $zkStatus,
                    'message' => 'Created successfully',
                ];
                $success++;

            } catch (\Throwable $e) {
                DB::rollBack();
                $results[] = [
                    'row' => $rowNum,
                    'name' => $row['name'] ?? '—',
                    'id' => $row['id_number'] ?? '—',
                    'status' => 'error',
                    'zk' => '—',
                    'message' => $e->getMessage(),
                ];
                $errors++;
            }
        }

        $this->importResults = $results;
        $this->importSuccessCount = $success;
        $this->importErrorCount = $errors;
        $this->importProcessed = true;

        $this->dispatch('refreshDatatable');
        $this->loadSummaryStats();

        if ($this->isStudentOrg && $this->personType === 'student') {
            $this->loadStudentAttendanceStats();
        }
    }


    public function loadStudentAttendanceStats(): void
    {
        $today = now()->toDateString();
        $orgId = auth()->user()->employee->organization_id;

        $students = Employee::where('organization_id', $orgId)
            ->where('active', 1)
            ->where('is_student', 1)
            ->get();

        $studentIds = $students->pluck('id');

        $attendances = \App\Models\Attendance::whereIn('employee_id', $studentIds)
            ->whereDate('date', $today)
            ->get();

        $this->presentCount = $attendances->where('status', 'clocked_in')->pluck('employee_id')->unique()->count();
        $this->leftSchoolCount = $attendances->where('status', 'clocked_out')->pluck('employee_id')->unique()->count();
        $reportedIds = $attendances->whereIn('status', ['clocked_in', 'clocked_out'])->pluck('employee_id')->unique();
        $this->notReportedCount = max(0, $students->count() - $reportedIds->count());
    }


    public function loadSummaryStats(): void
    {
        $orgId = auth()->user()->employee->organization_id;
        $today = now()->toDateString();

        if ($this->isStudentOrg) {
            $allPeople = Employee::where('organization_id', $orgId)->where('active', 1)->get();

            // ── Student Stats ──
            $this->totalStudents = $allPeople->where('is_student', 1)->count();
            $studentIds = $allPeople->where('is_student', 1)->pluck('id');
            $studentAttendance = \App\Models\Attendance::whereIn('employee_id', $studentIds)
                ->whereDate('date', $today)->get();

            $this->presentCount = $studentAttendance->where('status', 'clocked_in')->pluck('employee_id')->unique()->count();
            $this->leftSchoolCount = $studentAttendance->where('status', 'clocked_out')->pluck('employee_id')->unique()->count();
            $this->notReportedCount = max(0, $this->totalStudents - $studentAttendance
                    ->whereIn('status', ['clocked_in', 'clocked_out'])->pluck('employee_id')->unique()->count());

            // ── Staff Stats ──
            $this->totalStaff = $allPeople->where('is_student', 0)->count();
            $staffIds = $allPeople->where('is_student', 0)->pluck('id');
            $staffAttendance = \App\Models\Attendance::whereIn('employee_id', $staffIds)
                ->whereDate('date', $today)->get();

            $this->staffPresentCount = $staffAttendance->where('status', 'clocked_in')->pluck('employee_id')->unique()->count();
            $this->staffLeftCount = $staffAttendance->where('status', 'clocked_out')->pluck('employee_id')->unique()->count();
            $this->staffNotReportedCount = max(0, $this->totalStaff - $staffAttendance
                    ->whereIn('status', ['clocked_in', 'clocked_out'])->pluck('employee_id')->unique()->count());

        } else {
            // ── Regular Org Stats ──
            $this->totalEmployees = Employee::where('organization_id', $orgId)->count();
            $this->activeEmployees = Employee::where('organization_id', $orgId)->where('active', 1)->count();
            $this->inactiveEmployees = Employee::where('organization_id', $orgId)->where('active', 0)->count();

            // ── Employee type breakdown ──
            $typeRows = Employee::where('organization_id', $orgId)
                ->selectRaw("COALESCE(NULLIF(employee_type,''), 'Unassigned') as employee_type, active, COUNT(*) as count")
                ->groupBy('employee_type', 'active')
                ->get();

            $this->empTypeTotals = $this->empTypeActive = $this->empTypeInactive = [];
            foreach ($typeRows as $row) {
                $t = $row->employee_type;
                $this->empTypeTotals[$t] = ($this->empTypeTotals[$t] ?? 0) + $row->count;
                if ($row->active) $this->empTypeActive[$t] = ($this->empTypeActive[$t] ?? 0) + $row->count;
                else              $this->empTypeInactive[$t] = ($this->empTypeInactive[$t] ?? 0) + $row->count;
            }

            $this->totalStudents = $this->totalStaff = 0;
            $this->presentCount = $this->leftSchoolCount = $this->notReportedCount = 0;
            $this->staffPresentCount = $this->staffLeftCount = $this->staffNotReportedCount = 0;
        }
    }


    public function switchType(string $type): void
    {
        if (!in_array($type, ['student', 'staff'])) {
            $type = 'student';
        }

        $this->personType = $type;
        $this->userType = $type;  // ← this change triggers the :key remount

        if ($type === 'student') {
            $this->loadStudentAttendanceStats();
        }

    }

    protected function isCreatingStudent(): bool
    {
        return $this->isStudentOrg && $this->personType === 'student';
    }

    protected function resolveRoleName(): string
    {
        if ($this->isCreatingStudent()) {
            return 'student';
        }
        if ($this->isStudentOrg) {
            return $this->roleName ?: 'staff';
        }
        return $this->roleName;
    }

    #[On('assign-work-location')]
    public function setEmployee($id): void
    {
        $this->employeeId = $id;
        $this->reset(['search', 'workLocations', 'selectedLocation']);
        $this->dispatch('show-work-location-modal');
    }

    #[On('search-work-location')]
    public function searchLocation(): void
    {
        if (strlen($this->search) > 1) {
            $this->workLocations = WorkLocation::query()
                ->where('organization_id', auth()->user()->employee->organization_id)
                ->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('address', 'like', "%{$this->search}%");
                })
                ->limit(10)
                ->get();
        } else {
            $this->workLocations = [];
        }
    }

    public function selectWorkLocation($id): void
    {
        $this->selectedLocation = WorkLocation::find($id);
        $this->search = $this->selectedLocation->name;
        $this->workLocations = [];
    }

    public function assignWorkLocation(): void
    {
        $this->validate([
            'employeeId' => 'required|exists:employees,id',
            'selectedLocation.id' => 'required|exists:work_locations,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        EmployeeAssignment::where('employee_id', $this->employeeId)
            ->where('work_location_id', $this->selectedLocation->id)
            ->delete();

        EmployeeAssignment::create([
            'employee_id' => $this->employeeId,
            'work_location_id' => $this->selectedLocation->id,
            'start_date' => $this->start_date ?? null,
            'end_date' => $this->end_date ?? null,
            'is_current' => true,
        ]);

        $this->dispatch('hide-work-location-modal');
        LivewireAlert::title('Awesome!')->text('Location assigned successfully.')->success()->toast()->position('top-end')->show();
        $this->reset(['search', 'workLocations', 'selectedLocation']);
    }

    public function rules(): array
    {
        if ($this->isCreatingStudent()) {
            return [
                'name' => 'required|string|max:255',
                'shift_id' => 'required|exists:shifts,id',
                'id_number' => 'required|string|unique:employees,id_number,' . $this->editId,
                'active' => 'boolean',
                'employee_title' => 'nullable|string|max:255',
                'department_id' => 'nullable|exists:departments,id',
                'grade' => 'required|string'
            ];
        }
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:employees,email,' . $this->editId,
            'phone' => 'required|string|max:20',
            'shift_id' => 'required|exists:shifts,id',
            'department_id' => 'required|exists:departments,id',
            'id_number' => 'required|string|unique:employees,id_number,' . $this->editId,
            'active' => 'boolean',
            'roleName' => 'required|string',
            'employee_title' => 'nullable|string|max:255',
        ];
    }

    public function createEmployee(): void
    {

        $this->validate();

        try {
            DB::beginTransaction();

            $org = auth()->user()->employee->organization;
            $isStudent = $this->isCreatingStudent();
            $roleName = $this->resolveRoleName();

            $email = $isStudent ? "student_{$this->id_number}@{$org->id}.local" : $this->email;
            $phone = $isStudent ? ($org->phone_number ?? '') : PhoneSanitizer::sanitize($this->phone);
            $deptId = $isStudent ? ($this->department_id ?? $org->departments->first()?->id) : $this->department_id;

            $user = User::create(['name' => $this->name, 'email' => $email, 'password' => Hash::make('password')]);

            $employee = Employee::create([
                'name' => $this->name,
                'email' => $email,
                'phone' => $phone,
                'shift_id' => $this->shift_id,
                'organization_id' => $org->id,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'user_id' => $user->id,
                'department_id' => $deptId,
                'grade' => $isStudent ? $this->grade : null,
                'employee_title' => $this->employee_title,
                'is_student' => $isStudent ? 1 : 0,
            ]);

            $user->assignRole($roleName);
            $user->createToken('Api Token')->plainTextToken;

            $defaultLocation = WorkLocation::where('organization_id', $org->id)->where('is_default', true)->first();
            if ($defaultLocation) {
                EmployeeAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    ['work_location_id' => $defaultLocation->id, 'start_date' => null, 'end_date' => null, 'is_current' => true]
                );
            }

            DB::commit();

            // ZKBio sync
            if ($org->zkbio_sync_enabled && $org->zkbio_base_url && $org->zkbio_access_token) {
                try {
                    $fresh = $employee->fresh();
                    app(ZKBioPersonService::class, ['organization' => $org])->syncPerson($fresh);

                    // Assign to ALL areas
                    $allAreas = \App\Models\ZkbioArea::where('organization_id', $org->id)
                        ->where('area_code', '>', 5)
                        ->get();

                    if ($allAreas->isNotEmpty()) {
                        $zkService = app(\App\Services\ZKBioPersonService::class, ['organization' => $org]);
                        $areaCodes = $allAreas->pluck('area_code')->toArray();
                        $zkService->syncEmployeeAreas($fresh, $areaCodes);
                        $fresh->zkbioAreas()->sync($allAreas->pluck('id')->toArray());
                    }

                } catch (\Throwable $zkErr) {
                    \Log::warning("ZKBio sync failed for new employee {$employee->name}", [
                        'error' => $zkErr->getMessage()
                    ]);
                }
            }

            $this->dispatch('hide-employee-modal');

            LivewireAlert::title('Awesome!')->text($isStudent ? 'Student created successfully.' : 'Staff member created successfully.')->success()->toast()->position('top-end')->show();
            $this->resetForm();
            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();
        } catch (\Exception $e) {
            DB::rollBack();


            report($e);
            LivewireAlert::title('Error!')->text($e->getMessage())->error()->toast()->position('top-end')->show();
        }
    }

    #[On('edit-employee')]
    public function editEmployee($id): void
    {
        $employee = Employee::findOrFail($id);
        $this->editId = $id;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->phone = $employee->phone;
        $this->shift_id = $employee->shift_id;
        $this->department_id = $employee->department_id;
        $this->id_number = $employee->id_number;
        $this->active = $employee->active;
        $this->roleName = $employee->user?->roles->first()?->name ?? '';
        $this->employee_title = $employee->employee_title;
        $this->grade = $employee->grade;
        $this->personType = $employee->is_student ? 'student' : 'staff';
        $this->dispatch('refresh-status', employee: $employee);
        $this->dispatch('show-employee-modal');
    }

    public function updateEmployee(): void
    {
        $this->validate();
        try {
            DB::beginTransaction();

            $employee = Employee::with('user.roles')->findOrFail($this->editId);
            $org = auth()->user()->employee->organization;
            $isStudent = $this->isCreatingStudent();
            $roleName = $this->resolveRoleName();

            $email = $isStudent ? $employee->email : $this->email;
            $phone = $isStudent ? $employee->phone : PhoneSanitizer::sanitize($this->phone);
            $deptId = $isStudent ? ($this->department_id ?? $employee->department_id) : $this->department_id;

            $employee->update([
                'name' => $this->name,
                'email' => $email,
                'phone' => $phone,
                'shift_id' => $this->shift_id,
                'department_id' => $deptId,
                'grade' => $isStudent ? $this->grade : null,
                'id_number' => $this->id_number,
                'active' => $this->active,
                'employee_title' => $this->employee_title,
                'is_student' => $isStudent ? 1 : 0,
            ]);

            $employee->user->syncRoles([$roleName]);
            if ($employee->user) {
                $employee->user->update(['name' => $this->name, 'email' => $email]);
            }

            DB::commit();
            app(ZKBioPersonService::class, ['organization' => $org])->syncPerson($employee->fresh());
            $this->dispatch('hide-employee-modal');
            LivewireAlert::title('Awesome!')->text($isStudent ? 'Student updated successfully.' : 'Staff member updated successfully.')->success()->toast()->position('top-end')->show();
            $this->resetForm();
            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();
        } catch (\Exception $e) {
            DB::rollBack();
            dd($e->getMessage());
            LivewireAlert::title('Error!')->text('Something went wrong.')->error()->toast()->position('top-end')->show();
        }
    }

    #[On('activate-employee')]
    public function activateEmployee($id): void
    {
        try {
            DB::beginTransaction();
            $employee = Employee::findOrFail($id);
            $employee->active = true;
            $employee->save();
            DB::commit();
            LivewireAlert::title('Success!')->text($employee->personTypeLabel() . ' activated successfully.')->success()->toast()->position('top-end')->show();
            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();
        } catch (\Exception $e) {
            DB::rollBack();
            LivewireAlert::title('Error!')->text('Something went wrong.')->error()->toast()->position('top-end')->show();
        }
    }

    #[On('deactivate-employee')]
    public function deactivateEmployee($id): void
    {
        try {
            DB::beginTransaction();
            $employee = Employee::findOrFail($id);
            $employee->active = false;
            $employee->save();
            DB::commit();
            LivewireAlert::title('Success!')->text($employee->personTypeLabel() . ' deactivated successfully.')->success()->toast()->position('top-end')->show();
            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();
        } catch (\Exception $e) {
            DB::rollBack();
            LivewireAlert::title('Error!')->text('Something went wrong.')->error()->toast()->position('top-end')->show();
        }
    }

    #[On('delete-employee')]
    public function deleteEmployee($id): void
    {
        try {
            $employee = Employee::findOrFail($id);
            $org = auth()->user()->employee->organization;
            $label = $employee->personTypeLabel();

            // Remove from ZKBio device (also removes area assignments on device)
            if ($employee->zkbio_pin) {
                app(ZKBioPersonService::class, ['organization' => $org])
                    ->deletePerson($employee->zkbio_pin);
            }

            // Clean up local DB area assignments
            $employee->zkbioAreas()->detach();

            // Soft delete
            $employee->delete();

            LivewireAlert::title('Success!')
                ->text("{$label} deleted successfully.")
                ->success()->toast()->position('top-end')->show();

            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();

        } catch (\Exception $e) {
            LivewireAlert::title('Error!')
                ->text('Something went wrong.')
                ->error()->toast()->position('top-end')->show();
        }
    }

    #[On('discard-employee-modal')]
    public function discardEmployeeModal(): void
    {
        $this->dispatch('hide-employee-modal');
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset(['name', 'email', 'phone', 'employee_type_id', 'department_id',
            'id_number', 'editId', 'shift_id', 'employee_title', 'roleName', 'grade']);
        $this->active = true;
        $this->roleName = 'employee';
    }

    #[On('set-off-shift')]
    public function openModal($id, $name): void
    {
        $this->employeeId = $id;
        $this->employeeName = $name ?? '';
        $employee = Employee::find($id);
        if ($employee) {
            $this->shiftStatus = $employee->shift_status ?? 'on_shift';
            $this->start_off_shift_date = $employee->start_off_shift_date;
            $this->end_off_shift_date = $employee->end_off_shift_date;
        }
        $this->dispatch('show-off-shift-modal');
    }

    public function saveOffShiftDates(): void
    {
        $this->validate([
            'start_off_shift_date' => 'required|date',
            'end_off_shift_date' => 'required|date|after_or_equal:start_off_shift_date',
        ]);
        $employee = Employee::find($this->employeeId);
        if ($employee) {
            $employee->update([
                'shift_status' => 'off_shift',
                'start_off_shift_date' => $this->start_off_shift_date,
                'end_off_shift_date' => $this->end_off_shift_date,
            ]);
        }
        $this->dispatch('hide-off-shift-modal');
        LivewireAlert::title('Awesome!')->text($employee->personTypeLabel() . ' off-shift updated successfully.')->success()->toast()->position('top-end')->show();
    }

    public function getBreadcrumbItemsProperty(): array
    {
        $label = $this->isStudentOrg
            ? ($this->personType === 'student' ? 'Students' : 'Staff')
            : 'Employees';

        return [
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => '<iconify-icon icon="solar:home-2-line-duotone" class="fs-5"></iconify-icon>'],
            ['label' => $label, 'url' => route('employees.index', ['type' => $this->personType]), 'icon' => '<iconify-icon icon="tabler:users" class="fs-5"></iconify-icon>'],
            [
                'label' => $this->isStudentOrg ? $label : (ucfirst($this->role?->name) ?? 'All'),
                'icon' => match (ucfirst($this->role?->name)) {
                    'Admin' => '<iconify-icon icon="mdi:shield-account" class="fs-5"></iconify-icon>',
                    'Supervisor' => '<iconify-icon icon="mdi:account-tie" class="fs-5"></iconify-icon>',
                    'HR' => '<iconify-icon icon="mdi:account-group" class="fs-5"></iconify-icon>',
                    default => '<iconify-icon icon="tabler:user" class="fs-5"></iconify-icon>',
                },
            ],
        ];
    }


    //FOR MANAGING AREAS
    #[On('manage-employee-areas')]
    public function open(int $employeeId): void
    {
        $this->employeeId = $employeeId;
        $this->employee = Employee::with('zkbioAreas')->findOrFail($employeeId);

        $org = auth()->user()->employee->organization;

        // Load cached areas — sync from ZKBio if none cached yet
        $cached = ZkbioArea::where('organization_id', $org->id)
            ->where('area_code', '>', 5)
            ->get();
        if ($cached->isEmpty()) {
            $service = app(ZKBioPersonService::class, ['organization' => $org]);
            $service->syncAreas();
            $cached = ZkbioArea::where('organization_id', $org->id)
                ->where('area_code', '>', 5)
                ->get();
        }

        $this->availableAreas = $cached->map(fn($a) => [
            'id' => $a->id,
            'area_code' => $a->area_code,
            'area_name' => $a->area_name,
        ])->toArray();

        // Pre-select current areas
        $this->selectedAreas = $this->employee->zkbioAreas->pluck('area_code')->toArray();

        $this->dispatch('show-area-modal');
    }

    public function saveAreas(): void
    {
        if (!$this->employee) return;

        $this->syncing = true;

        $org = auth()->user()->employee->organization;
        $service = app(ZKBioPersonService::class, ['organization' => $org]);

        try {
            $service->syncEmployeeAreas($this->employee, $this->selectedAreas);

            LivewireAlert::title('Areas Updated!')
                ->text("{$this->employee->name} has been assigned to " . count($this->selectedAreas) . " area(s).")
                ->success()->toast()->position('top-end')->show();

            $this->dispatch('hide-area-modal');
            $this->dispatch('refreshDatatable');

        } catch (\Throwable $e) {
            LivewireAlert::title('Error!')
                ->text('Failed to sync areas: ' . $e->getMessage())
                ->error()->toast()->position('top-end')->show();
        }

        $this->syncing = false;
    }

    public function refreshAreas(): void
    {
        $org = auth()->user()->employee->organization;
        $service = app(ZKBioPersonService::class, ['organization' => $org]);
        $service->syncAreas();

        $cached = ZkbioArea::where('organization_id', $org->id)
            ->where('area_code', '>', 5)
            ->get();

        $this->availableAreas = $cached->map(fn($a) => [
            'id' => $a->id,
            'area_code' => $a->area_code,
            'area_name' => $a->area_name,
        ])->toArray();

        LivewireAlert::title('Areas Refreshed!')
            ->text('Fetched latest areas from ZKBio.')
            ->success()->toast()->position('top-end')->show();
    }


    public function setEmpTypeFilter(string $val): void
    {
        $this->empTypeFilter = $val;
        $this->dispatch('filter-by-type',
            type: $this->personType,
            empType: $this->empTypeFilter,
            active: $this->activeFilter,
        );
    }

    public function setActiveFilter(string $val): void
    {
        $this->activeFilter = $val;
        $this->dispatch('filter-by-type',
            type: $this->personType,
            empType: $this->empTypeFilter,
            active: $this->activeFilter,
        );
    }


}; ?>

@push('styles')
    <style>

        /* Loading overlay */
        .sync-loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
        }

        .sync-loading-card {
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem 3rem;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            min-width: 320px;
        }

        .sync-loading-spinner {
            width: 56px;
            height: 56px;
            border: 5px solid #f1f5f9;
            border-top-color: #0078d4;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1.2rem;
        }

        .sync-loading-spinner.orange {
            border-top-color: #e14326;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .sync-loading-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.4rem;
        }

        .sync-loading-sub {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }

        .sync-loading-progress {
            margin-top: 1.2rem;
            height: 4px;
            background: #f1f5f9;
            border-radius: 99px;
            overflow: hidden;
        }

        .sync-loading-progress-bar {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #0078d4, #00b4d8);
            animation: progress-indeterminate 1.5s ease-in-out infinite;
        }

        .sync-loading-progress-bar.orange {
            background: linear-gradient(90deg, #e14326, #f97316);
        }

        @keyframes progress-indeterminate {
            0% {
                width: 0%;
                margin-left: 0;
            }
            50% {
                width: 60%;
                margin-left: 20%;
            }
            100% {
                width: 0%;
                margin-left: 100%;
            }
        }

        .import-accordion-wrap {
            border-radius: 14px;
            border: 1.5px dashed #e14326;
            background: #fffaf8;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: box-shadow 0.25s;
        }

        .import-accordion-wrap:focus-within {
            box-shadow: 0 0 0 3px rgba(225, 67, 38, 0.12);
        }

        .import-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.4rem;
            cursor: pointer;
            user-select: none;
            gap: 12px;
        }

        .import-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .import-header-icon {
            width: 36px;
            height: 36px;
            background: #fde8e3;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #e14326;
            font-size: 18px;
            flex-shrink: 0;
        }

        .import-header-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .import-header-sub {
            font-size: 0.75rem;
            color: #94a3b8;
            margin: 0;
        }

        .import-chevron {
            color: #e14326;
            font-size: 20px;
            transition: transform 0.3s ease;
            flex-shrink: 0;
        }

        .import-chevron.open {
            transform: rotate(180deg);
        }

        .import-body {
            border-top: 1.5px dashed #f0ddd8;
            padding: 1.5rem;
            animation: slideDown 0.25s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Steps */
        .import-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .import-steps {
                grid-template-columns: 1fr;
            }
        }

        .import-step-card {
            background: #fff;
            border: 1px solid #f1e8e5;
            border-radius: 12px;
            padding: 1.1rem 1.2rem;
        }

        .import-step-num {
            width: 28px;
            height: 28px;
            background: #e14326;
            border-radius: 50%;
            color: #fff;
            font-weight: 700;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.65rem;
        }

        .import-step-title {
            font-weight: 700;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .import-step-desc {
            font-size: 0.76rem;
            color: #64748b;
            margin: 0;
        }

        .import-step-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 0.5rem;
        }

        .import-step-tag {
            font-size: 0.68rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            border-radius: 5px;
            padding: 2px 8px;
        }

        /* Drop zone */
        .import-dropzone {
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
        }

        .import-dropzone:hover,
        .import-dropzone.dragover {
            border-color: #e14326;
            background: #fff5f2;
        }

        .import-dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .import-dropzone-icon {
            font-size: 2.2rem;
            color: #cbd5e1;
            margin-bottom: 0.6rem;
        }

        .import-dropzone-text {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0;
        }

        .import-dropzone-text a {
            color: #e14326;
            font-weight: 600;
            text-decoration: none;
        }

        .import-dropzone-hint {
            font-size: 0.72rem;
            color: #94a3b8;
            margin: 0.2rem 0 0;
        }

        .import-file-chosen {
            margin-top: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fde8e3;
            border-radius: 7px;
            padding: 5px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #c0341b;
        }

        /* Preview table */
        .import-preview-wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #f0e4e0;
        }

        .import-preview-wrap table {
            margin: 0;
            font-size: 0.78rem;
        }

        .import-preview-wrap thead th {
            background: #fff5f2;
            color: #7c3022;
            font-weight: 700;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1.5px solid #f0ddd8;
            padding: 8px 12px;
        }

        .import-preview-wrap tbody td {
            padding: 7px 12px;
            vertical-align: middle;
        }

        .import-preview-wrap tbody tr:hover {
            background: #fffaf8;
        }

        /* Results */
        .import-result-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 99px;
        }

        .import-result-badge.success {
            background: #dcfce7;
            color: #15803d;
        }

        .import-result-badge.error {
            background: #fee2e2;
            color: #dc2626;
        }

        .import-result-badge.zk-ok {
            background: #e0f2fe;
            color: #0369a1;
        }

        .import-result-badge.zk-skip {
            background: #f1f5f9;
            color: #64748b;
        }

        .import-result-badge.zk-err {
            background: #fef9c3;
            color: #a16207;
        }

        /* Summary bar */
        .import-summary-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: #fff;
            border-radius: 10px;
            border: 1px solid #f0ddd8;
            padding: 0.85rem 1.2rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .import-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .summary-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 1.4rem 1.5rem 1.2rem;
            height: 100%;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
        }

        .summary-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 0.9rem;
        }

        .summary-card-title {
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.35rem;
        }

        .summary-card-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }

        .summary-card-subtitle {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }

        .summary-card-bar {
            height: 4px;
            border-radius: 99px;
            background: #f1f5f9;
            margin-top: 0.85rem;
            overflow: hidden;
        }

        .summary-card-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.6s ease;
        }

        .summary-stats-row {
            margin-bottom: 2rem;
        }

        .person-type-toggle {
            display: flex;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
        }

        .person-type-toggle input[type="radio"] {
            display: none;
        }

        .person-type-toggle label {
            flex: 1;
            text-align: center;
            padding: 9px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            background: #f8fafc;
            color: #64748b;
            margin: 0;
            transition: all 0.2s;
        }

        .person-type-toggle input[type="radio"]:checked + label {
            background: var(--primary-color) !important;;
            color: white;
        }

        .type-tab {
            display: inline-flex;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
        }

        .type-tab button {
            padding: 7px 20px;
            font-size: 0.875rem;
            font-weight: 600;
            border: none;
            background: #f8fafc;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }

        .type-tab button.active {
            background: var(--primary-color) !important;
            color: white;
        }

        .type-tab button:not(:last-child) {
            border-right: 1px solid #d1d5db;
        }

        .form-control {
            display: block !important;
            font-size: 0.875rem !important;
            line-height: 1.5 !important;
            color: #1e293b !important;
            background-color: #fff !important;
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            transition: all 0.2s ease-in-out !important;
        }

        table.dataTable td {
            vertical-align: middle !important;
        }

        iconify-icon {
            vertical-align: middle !important;
        }

        .btn-group > div > button.dropdown-toggle {
            background-color: #f4f4f5;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 5px;
        }

        .btn-group > div > button.dropdown-toggle:hover {
            background-color: #e2e8f0;
        }

        .btn-group > .dropdown-menu {
            position: fixed !important;
            top: 100px !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            width: 600px !important;
            max-width: 90vw !important;
            padding: 24px !important;
            border-radius: 16px;
            background-color: #ffffff;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #e5e7eb;
            z-index: 1050;
            overflow-y: auto;
            max-height: 70vh;
        }

        .filter-close-button {
            position: absolute;
            top: 12px;
            right: 25px;
            background: transparent;
            border: none;
            font-size: 1.7rem;
            color: #6b7280;
            cursor: pointer;
            z-index: 1100;
        }

        .filter-close-button:hover {
            color: #ef4444;
        }

        #table-bulkActionsDropdown {
            background-color: var(--primary-color) !important;
            border: none;
            color: #fff;
            font-weight: 600;
        }

        #table-bulkActionsDropdown:hover {
            background-color: var(--primary-color) !important;
        }
    </style>
@endpush

@php
    $isStudent  = $isStudentOrg && $personType === 'student';
    $pageTitle  = $isStudentOrg ? ($personType === 'student' ? 'Students' : 'Staff') : 'Employees';
    $modalTitle = $editId
        ? 'Edit '  . ($isStudent ? 'Student' : ($isStudentOrg ? 'Staff Member' : 'Employee'))
        : 'New '   . ($isStudent ? 'Student' : ($isStudentOrg ? 'Staff Member' : 'Employee'));
@endphp

<div class="row">

    {{-- AD Sync Loading Overlay --}}
    <div style="padding-top:20px;" wire:loading wire:target="commitAdSync" class="sync-loading-overlay">
        <div class="sync-loading-card">
            <div class="sync-loading-spinner"></div>
            <p class="sync-loading-title">Syncing from Active Directory</p>
            <p class="sync-loading-sub">Importing employees & syncing to ZKBio...<br>Please do not close this page.</p>
            <div class="sync-loading-progress">
                <div class="sync-loading-progress-bar"></div>
            </div>
        </div>
    </div>

    {{-- AD Preview Loading Overlay --}}
    <div style="padding-top:20px;" wire:loading wire:target="previewAdSync" class="sync-loading-overlay">
        <div class="sync-loading-card">
            <div class="sync-loading-spinner"></div>
            <p class="sync-loading-title">Fetching from Active Directory</p>
            <p class="sync-loading-sub">Pulling all users from Microsoft Entra...<br>This may take a moment.</p>
            <div class="sync-loading-progress">
                <div class="sync-loading-progress-bar"></div>
            </div>
        </div>
    </div>

    {{-- Deactivate Removed AD Users Loading Overlay --}}
    <div style="padding-top:20px;" wire:loading wire:target="deactivateRemovedAdUsers" class="sync-loading-overlay">
        <div class="sync-loading-card">
            <div class="sync-loading-spinner" style="border-top-color:#dc2626;"></div>
            <p class="sync-loading-title">Checking Active Directory</p>
            <p class="sync-loading-sub">Comparing employees against AD & ZKBio...<br>Please do not close this page.</p>
            <div class="sync-loading-progress">
                <div class="sync-loading-progress-bar"
                     style="background:linear-gradient(90deg, #dc2626, #f87171);"></div>
            </div>
        </div>
    </div>

    {{-- Import Loading Overlay --}}
    <div style="padding-top:20px;" wire:loading wire:target="commitImport" class="sync-loading-overlay">
        <div class="sync-loading-card">
            <div class="sync-loading-spinner orange"></div>
            <p class="sync-loading-title">Importing Employees</p>
            <p class="sync-loading-sub">Creating records & syncing to ZKBio...<br>Please do not close this page.</p>
            <div class="sync-loading-progress">
                <div class="sync-loading-progress-bar orange"></div>
            </div>
        </div>
    </div>

    <div class="col-12">

        <livewire:admin.system-settings.bread-crumb
            title="{{ $pageTitle }}"
            :items="$this->breadcrumbItems"
        />

        {{-- Summary Stats --}}
        {{-- Summary Stats --}}
        <div class="row g-3 mb-4 summary-stats-row">

            @if($isStudentOrg)
                @php
                    $isShowingStudent = ($personType === 'student');
                    $total   = $isShowingStudent ? $totalStudents    : $totalStaff;
                    $present = $isShowingStudent ? $presentCount     : $staffPresentCount;
                    $left    = $isShowingStudent ? $leftSchoolCount  : $staffLeftCount;
                    $missing = $isShowingStudent ? $notReportedCount : $staffNotReportedCount;
                    $label   = $isShowingStudent ? 'Students'        : 'Staff';
                @endphp

                {{-- Total --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#ede9fe; color:#7c3aed;">
                            <iconify-icon icon="mdi:account-group"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Total {{ $label }}</p>
                        <div class="summary-card-value">{{ $total }}</div>
                        <p class="summary-card-subtitle">Active enrolled</p>
                    </div>
                </div>

                {{-- On Campus / Clocked In --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#dcfce7; color:#16a34a;">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                        <p class="summary-card-title">{{ $isShowingStudent ? 'On Campus' : 'On Campus' }}</p>
                        <div class="summary-card-value">{{ $present }}</div>
                        <p class="summary-card-subtitle">Currently present</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $total > 0 ? ($present/$total)*100 : 0 }}%; background:#22c55e;"></div>
                        </div>
                    </div>
                </div>

                {{-- Off Campus / Left --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#e0f2fe; color:#0284c7;">
                            <iconify-icon icon="mdi:exit-run"></iconify-icon>
                        </div>
                        <p class="summary-card-title">{{ $isShowingStudent ? 'Left School' : 'Left School' }}</p>
                        <div class="summary-card-value">{{ $left }}</div>
                        <p class="summary-card-subtitle">{{ $isShowingStudent ? 'Signed out today' : 'Clocked out today' }}</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $total > 0 ? ($left/$total)*100 : 0 }}%; background:#0ea5e9;"></div>
                        </div>
                    </div>
                </div>

                {{-- Unscanned --}}
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#fee2e2; color:#dc2626;">
                            <iconify-icon icon="mdi:account-alert"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Not Enrolled</p>
                        <div class="summary-card-value">{{ $missing }}</div>
                        <p class="summary-card-subtitle">No logs today</p>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $total > 0 ? ($missing/$total)*100 : 0 }}%; background:#ef4444;"></div>
                        </div>
                    </div>
                </div>

            @else
                {{-- ── REGULAR (non-school) ORG ── --}}

                @php
                    $typeConfig = [
                        'COSMOS'     => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'icon' => 'mdi:office-building'],
                        'Outsourced' => ['bg' => '#fde8e3', 'color' => '#c0341b', 'icon' => 'mdi:account-switch'],
                        'Unassigned' => ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'mdi:account-question'],
                    ];
                @endphp

                {{-- Total Employees --}}
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#ede9fe; color:#7c3aed;">
                            <iconify-icon icon="mdi:account-group"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Total Employees</p>
                        <div class="summary-card-value">{{ $totalEmployees }}</div>
                        <p class="summary-card-subtitle">All registered employees</p>
                        {{-- Type breakdown --}}
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach($empTypeTotals as $type => $count)
                                @php $cfg = $typeConfig[$type] ?? $typeConfig['Unassigned']; @endphp
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.7rem;font-weight:700;
                                 background:{{ $cfg['bg'] }};color:{{ $cfg['color'] }};
                                 padding:2px 9px;border-radius:99px;white-space:nowrap;">
                        <iconify-icon icon="{{ $cfg['icon'] }}" style="font-size:11px;"></iconify-icon>
                        {{ $type }} · {{ $count }}
                    </span>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Active Employees --}}
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#dcfce7; color:#16a34a;">
                            <iconify-icon icon="mdi:account-check"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Active Employees</p>
                        <div class="summary-card-value">{{ $activeEmployees }}</div>
                        <p class="summary-card-subtitle">Currently active</p>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach($empTypeActive as $type => $count)
                                @php $cfg = $typeConfig[$type] ?? $typeConfig['Unassigned']; @endphp
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.7rem;font-weight:700;
                                 background:{{ $cfg['bg'] }};color:{{ $cfg['color'] }};
                                 padding:2px 9px;border-radius:99px;white-space:nowrap;">
                        <iconify-icon icon="{{ $cfg['icon'] }}" style="font-size:11px;"></iconify-icon>
                        {{ $type }} · {{ $count }}
                    </span>
                            @endforeach
                        </div>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $totalEmployees > 0 ? ($activeEmployees/$totalEmployees)*100 : 0 }}%; background:#22c55e;"></div>
                        </div>
                    </div>
                </div>

                {{-- Inactive Employees --}}
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#fee2e2; color:#dc2626;">
                            <iconify-icon icon="mdi:account-cancel"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Inactive Employees</p>
                        <div class="summary-card-value">{{ $inactiveEmployees }}</div>
                        <p class="summary-card-subtitle">Deactivated accounts</p>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @foreach($empTypeInactive as $type => $count)
                                @php $cfg = $typeConfig[$type] ?? $typeConfig['Unassigned']; @endphp
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.7rem;font-weight:700;
                                 background:{{ $cfg['bg'] }};color:{{ $cfg['color'] }};
                                 padding:2px 9px;border-radius:99px;white-space:nowrap;">
                        <iconify-icon icon="{{ $cfg['icon'] }}" style="font-size:11px;"></iconify-icon>
                        {{ $type }} · {{ $count }}
                    </span>
                            @endforeach
                        </div>
                        <div class="summary-card-bar">
                            <div class="summary-card-bar-fill"
                                 style="width:{{ $totalEmployees > 0 ? ($inactiveEmployees/$totalEmployees)*100 : 0 }}%; background:#ef4444;"></div>
                        </div>
                    </div>
                </div>

            @endif

        </div>
        {{-- End Summary Stats --}}

        {{-- Table card --}}
        <div class="card card-body">
            {{-- ── TOP BAR (replaces the existing d-flex justify-content-between in the card) ── --}}
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">

                {{-- Person type toggle (existing) --}}
                @if($isStudentOrg)
                    <div class="type-tab">
                        <button wire:click="switchType('student')"
                                class="{{ $personType === 'student' ? 'active' : '' }}">
                            <iconify-icon icon="mdi:school" style="font-size:15px;margin-right:4px;"></iconify-icon>
                            Students
                        </button>
                        <button wire:click="switchType('staff')" class="{{ $personType === 'staff' ? 'active' : '' }}">
                            <iconify-icon icon="mdi:account-tie"
                                          style="font-size:15px;margin-right:4px;"></iconify-icon>
                            Staff
                        </button>
                    </div>
                @else
                    <div></div>
                @endif

                {{-- Action buttons --}}
                <div class="d-flex gap-2">
                    {{-- ★ AD SYNC BUTTON ★ --}}
                    @if(!$isStudentOrg)
                        <button
                            wire:click="toggleAdSyncPanel"
                            type="button"
                            class="btn d-flex align-items-center gap-2"
                            style="background:#fff; border:1.5px solid #0078d4 !important; color:#0078d4 !important; font-weight:600; border-radius:8px; font-size:0.875rem; padding:8px 14px;"
                        >
                            <iconify-icon icon="{{ $showAdSyncPanel ? 'mdi:close' : 'mdi:microsoft-azure' }}"
                                          style="font-size:17px;"></iconify-icon>
                            {{ $showAdSyncPanel ? 'Close AD Sync' : 'Sync from AD' }}
                        </button>
                    @endif


                    {{-- ★ IMPORT TOGGLE BUTTON ★ --}}
                    <button
                        wire:click="toggleImportPanel"
                        type="button"
                        class="btn d-flex align-items-center gap-2"
                        style="background:#fff; border:1.5px solid var(--primary-color) !important; color:var(--primary-color) !important; font-weight:600; border-radius:8px; font-size:0.875rem; padding:8px 14px;"
                    >
                        <iconify-icon icon="{{ $showImportPanel ? 'mdi:close' : 'mdi:upload' }}"
                                      style="font-size:17px;"></iconify-icon>
                        {{ $showImportPanel ? 'Close Import' : 'Import ' . ($isStudentOrg ? ($personType === 'student' ? 'Students' : 'Staff') : 'Employees') }}
                    </button>

                    @if(!$isStudentOrg)
                        <button wire:click="deactivateRemovedAdUsers" type="button"
                                wire:confirm="This will soft-delete employees no longer in AD or disabled. Continue?"
                                wire:loading.attr="disabled"
                                wire:target="deactivateRemovedAdUsers"
                                class="btn d-flex align-items-center gap-2"
                                style="background:#fff; border:1.5px solid #dc2626 !important; color:#dc2626 !important; font-weight:600; border-radius:8px; font-size:0.875rem; padding:8px 14px;">
    <span wire:loading wire:target="deactivateRemovedAdUsers">
        <span class="spinner-border spinner-border-sm"></span>
    </span>
                            <iconify-icon icon="mdi:account-remove" wire:loading.remove
                                          wire:target="deactivateRemovedAdUsers" style="font-size:17px;"></iconify-icon>
                            <span wire:loading.remove wire:target="deactivateRemovedAdUsers">Delete Removed</span>
                            <span wire:loading wire:target="deactivateRemovedAdUsers">Checking AD...</span>
                        </button>
                    @endif

                    {{-- Single create (existing) --}}
                    <a href="javascript:void(0)"
                       class="btn btn-primary d-flex align-items-center gap-2"
                       data-bs-toggle="modal" data-bs-target="#employeeModal">
                        <i class="ti ti-user-plus fs-5"></i>
                        Add {{ $isStudent ? 'Student' : ($isStudentOrg ? 'Staff' : 'Employee') }}
                    </a>
                </div>
            </div>


            {{-- ── Filter bar (non-student orgs) ──────────────────────────────────── --}}
            @if(!$isStudentOrg)
                <div class="d-flex flex-wrap gap-3 align-items-end mb-3 p-3 rounded-3"
                     style="background:#f8fafc; border:1px solid #e2e8f0;">

                    {{-- Employee Type --}}
                    <div>
                        <label class="d-block mb-1"
                               style="font-size:0.72rem;font-weight:600;color:#64748b;
                          text-transform:uppercase;letter-spacing:0.4px;">
                            Employee Type
                        </label>
                        <div class="d-flex gap-1 flex-wrap">
                            @foreach(['' => 'All', 'COSMOS' => 'COSMOS', 'Outsourced' => 'Outsourced'] as $val => $label)
                                @php
                                    $active = ($empTypeFilter ?? '') === $val;
                                    [$bg, $color] = match($val) {
                                        'COSMOS'     => ['#e0f2fe', '#0369a1'],
                                        'Outsourced' => ['#fde8e3', '#c0341b'],
                                        default      => ['#f1f5f9', '#475569'],
                                    };
                                @endphp
                                <button
                                    type="button"
                                    wire:click="setEmpTypeFilter('{{ $val }}')"
                                    style="font-size:0.75rem; font-weight:600; border-radius:99px;
                               padding:4px 14px; border:1.5px solid {{ $active ? $color : '#e2e8f0' }};
                               background:{{ $active ? $bg : '#fff' }};
                               color:{{ $active ? $color : '#64748b' }};
                               cursor:pointer; transition:all 0.15s;">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Active Status --}}
                    <div>
                        <label class="d-block mb-1"
                               style="font-size:0.72rem;font-weight:600;color:#64748b;
                          text-transform:uppercase;letter-spacing:0.4px;">
                            Status
                        </label>
                        <div class="d-flex gap-1 flex-wrap">
                            @foreach(['' => 'All', '1' => 'Active', '0' => 'Inactive'] as $val => $label)
                                @php
                                    $val    = (string) $val;
                                    $active = $activeFilter === $val;
                                    [$bg, $color] = match($val) {
                                        '1'     => ['#dcfce7', '#15803d'],
                                        '0'     => ['#fee2e2', '#dc2626'],
                                        default => ['#f1f5f9', '#475569'],
                                    };
                                @endphp
                                <button
                                    type="button"
                                    wire:click="setActiveFilter('{{ $val }}')"
                                    style="font-size:0.75rem; font-weight:600; border-radius:99px;
                               padding:4px 14px; border:1.5px solid {{ $active ? $color : '#e2e8f0' }};
                               background:{{ $active ? $bg : '#fff' }};
                               color:{{ $active ? $color : '#64748b' }};
                               cursor:pointer; transition:all 0.15s;">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                </div>
            @endif


            {{-- ══════════════════════════════════════════════════════════════
                 IMPORT ACCORDION (shown/hidden by $showImportPanel)
            ══════════════════════════════════════════════════════════════ --}}
            @if($showImportPanel)
                <div class="import-accordion-wrap mb-3">

                    {{-- Clickable header --}}
                    <div class="import-header" wire:click="toggleImportPanel">
                        <div class="import-header-left">
                            <div class="import-header-icon">
                                <iconify-icon icon="mdi:table-arrow-up"></iconify-icon>
                            </div>
                            <div>
                                <p class="import-header-title">
                                    Bulk Import
                                    {{ $isStudentOrg ? ($personType === 'student' ? 'Students' : 'Staff Members') : 'Employees' }}
                                </p>
                                <p class="import-header-sub">Download the template, fill in data, upload to create
                                    multiple records + ZKBio sync</p>
                            </div>
                        </div>
                        <iconify-icon icon="mdi:chevron-up" class="import-chevron open"></iconify-icon>
                    </div>

                    {{-- Panel body --}}
                    <div class="import-body">

                        {{-- ── Step cards ─────────────────────────────────────────────── --}}
                        @if(!$importParsed && !$importProcessed)
                            <div class="import-steps">

                                {{-- Step 1 --}}
                                <div class="import-step-card">
                                    <div class="import-step-num">1</div>
                                    <p class="import-step-title">Download Template</p>
                                    <p class="import-step-desc">Get the CSV template with the correct columns
                                        pre-configured.</p>
                                    <div class="import-step-tags">
                                        @if($isStudentOrg && $personType === 'student')
                                            <span class="import-step-tag">name</span>
                                            <span class="import-step-tag">id_number</span>
                                            <span class="import-step-tag">grade</span>
                                            <span class="import-step-tag">stream</span>
                                            <span class="import-step-tag">department</span>
                                        @else
                                            <span class="import-step-tag">name</span>
                                            <span class="import-step-tag">email</span>
                                            <span class="import-step-tag">phone</span>
                                            <span class="import-step-tag">id_number</span>
                                            <span class="import-step-tag">department</span>
                                            <span class="import-step-tag">role</span>
                                            <span class="import-step-tag">title</span>
                                        @endif
                                    </div>
                                    <div class="mt-3 d-flex gap-2">

                                        <button wire:click="downloadTemplate('csv')" type="button"
                                                class="btn btn-sm"
                                                style="background:#fde8e3; color:#c0341b; border:none; font-weight:600; border-radius:7px; font-size:0.78rem;">
                                            <iconify-icon icon="mdi:file-delimited-outline"
                                                          style="margin-right:4px;"></iconify-icon>
                                            Download .csv
                                        </button>
                                        <button wire:click="downloadTemplate('xlsx')" type="button"
                                                class="btn btn-sm"
                                                style="background:#e8f5e9; color:#2e7d32; border:none; font-weight:600; border-radius:7px; font-size:0.78rem;">
                                            <iconify-icon icon="mdi:microsoft-excel"
                                                          style="margin-right:4px;"></iconify-icon>
                                            Download .xlsx
                                        </button>
                                    </div>
                                </div>

                                {{-- Step 2 --}}
                                <div class="import-step-card">
                                    <div class="import-step-num">2</div>
                                    <p class="import-step-title">Fill In Data</p>
                                    <p class="import-step-desc">
                                        @if($isStudentOrg && $personType === 'student')
                                            Add student details — name, admission no., grade, stream.
                                        @else
                                            Add staff details — name, email, phone, ID number, department, role.
                                        @endif
                                    </p>
                                    <p class="import-step-desc mt-2" style="color:#e14326; font-weight:600;">
                                        <iconify-icon icon="mdi:information-outline"
                                                      style="margin-right:3px;"></iconify-icon>
                                        Max 500 rows per upload.
                                    </p>
                                </div>

                                {{-- Step 3 --}}
                                <div class="import-step-card">
                                    <div class="import-step-num">3</div>
                                    <p class="import-step-title">Upload &amp; Import</p>
                                    <p class="import-step-desc">Upload your file. Review the preview, confirm to create
                                        all records and sync to <strong>ZKBio</strong>.</p>
                                    <div class="import-step-tags" style="margin-top:0.5rem;">
                    <span class="import-step-tag" style="background:#e0f2fe;color:#0369a1;">
                        <iconify-icon icon="mdi:fingerprint" style="font-size:11px;margin-right:2px;"></iconify-icon>ZKBio Sync
                    </span>
                                        <span class="import-step-tag" style="background:#dcfce7;color:#15803d;">Auto-role</span>
                                    </div>
                                </div>

                            </div>
                        @endif

                        {{-- ── Error alert ──────────────────────────────────────────────── --}}
                        @if($importError)
                            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 mb-3"
                                 style="border-radius:9px; font-size:0.82rem;">
                                <iconify-icon icon="mdi:alert-circle-outline"
                                              style="font-size:18px;flex-shrink:0;"></iconify-icon>
                                {{ $importError }}
                            </div>
                        @endif

                        {{-- ── STEP A: Upload dropzone (before parse) ───────────────────── --}}
                        @if(!$importParsed && !$importProcessed)
                            <form wire:submit.prevent="parseImportFile">
                                <div class="import-dropzone"
                                     x-data="{ name: '' }"
                                     @dragover.prevent="$el.classList.add('dragover')"
                                     @dragleave.prevent="$el.classList.remove('dragover')"
                                     @drop.prevent="$el.classList.remove('dragover')">
                                    <input
                                        type="file"
                                        wire:model="importFile"
                                        accept=".xlsx,.xls,.csv"
                                        x-on:change="name = $event.target.files[0]?.name ?? ''"
                                    />
                                    <div class="import-dropzone-icon">
                                        <iconify-icon icon="mdi:cloud-upload-outline"></iconify-icon>
                                    </div>
                                    <p class="import-dropzone-text">
                                        Drop your file here, or <a href="#">browse</a>
                                    </p>
                                    <p class="import-dropzone-hint">Supports .xlsx and .csv — max 5 MB</p>
                                    <template x-if="name">
                                        <div class="import-file-chosen">
                                            <iconify-icon icon="mdi:file-check-outline"></iconify-icon>
                                            <span x-text="name"></span>
                                        </div>
                                    </template>
                                </div>
                                @error('importFile') <small
                                    class="text-danger d-block mt-1">{{ $message }}</small> @enderror

                                <div class="d-flex justify-content-end mt-3">
                                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-2"
                                            style="font-size:0.875rem; border-radius:8px;">
                                        <div wire:loading wire:target="parseImportFile">
                                            <span class="spinner-border spinner-border-sm me-1"></span>
                                        </div>
                                        <iconify-icon icon="mdi:table-eye" wire:loading.remove
                                                      wire:target="parseImportFile"></iconify-icon>
                                        Preview Data
                                    </button>
                                </div>
                            </form>
                        @endif

                        {{-- ── STEP B: Preview table (after parse, before commit) ──────── --}}
                        @if($importParsed && !$importProcessed)
                            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                <p class="mb-0" style="font-size:0.82rem; font-weight:600; color:#1e293b;">
                                    <iconify-icon icon="mdi:table-check"
                                                  style="color:#e14326;margin-right:4px;"></iconify-icon>
                                    Preview — <span style="color:#e14326;">{{ count($importPreview) }} rows</span> ready
                                    to import
                                </p>
                                <button wire:click="resetImport" type="button"
                                        class="btn btn-sm"
                                        style="font-size:0.78rem; background:#f1f5f9; border:none; color:#64748b; border-radius:7px;">
                                    <iconify-icon icon="mdi:refresh"></iconify-icon>
                                    Change File
                                </button>
                            </div>

                            <div class="import-preview-wrap mb-3">
                                <table class="table table-hover table-bordered mb-0">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        @foreach(array_keys($importPreview[0] ?? []) as $col)
                                            <th>{{ ucfirst(str_replace('_', ' ', $col)) }}</th>
                                        @endforeach
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach(array_slice($importPreview, 0, 20) as $i => $row)
                                        <tr>
                                            <td class="text-muted">{{ $i + 1 }}</td>
                                            @foreach($row as $val)
                                                <td>{{ $val ?: '—' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                    @if(count($importPreview) > 20)
                                        <tr>
                                            <td colspan="99" class="text-center text-muted py-2"
                                                style="font-size:0.75rem;">
                                                … and {{ count($importPreview) - 20 }} more rows (not shown in preview)
                                            </td>
                                        </tr>
                                    @endif
                                    </tbody>
                                </table>
                            </div>

                            <div class="alert d-flex align-items-start gap-2 py-2 px-3 mb-3"
                                 style="border-radius:9px; font-size:0.8rem; background:#e0f2fe; border:1px solid #bae6fd; color:#075985;">
                                <iconify-icon icon="mdi:fingerprint"
                                              style="font-size:18px;flex-shrink:0;margin-top:1px;"></iconify-icon>
                                <span>
                    Each record will be created as a
                    <strong>{{ $isStudentOrg && $personType === 'student' ? 'Student' : 'Staff Member' }}</strong>
                    and automatically <strong>synced to the ZKBio server</strong>.
                    If ZKBio sync fails for a row, the record is still saved — you can re-sync manually.
                </span>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button wire:click="resetImport" type="button"
                                        class="btn btn-outline-danger" style="font-size:0.875rem; border-radius:8px;">
                                    Cancel
                                </button>
                                <button wire:click="commitImport"
                                        wire:loading.attr="disabled"
                                        wire:target="commitImport"
                                        type="button"
                                        class="btn btn-success d-flex align-items-center gap-2"
                                        style="font-size:0.875rem; border-radius:8px;">
                                    <div wire:loading wire:target="commitImport">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                    </div>
                                    <iconify-icon icon="mdi:check-bold" wire:loading.remove
                                                  wire:target="commitImport"></iconify-icon>
                                    <span wire:loading.remove wire:target="commitImport">
                        Confirm &amp; Import {{ count($importPreview) }} Records
                    </span>
                                    <span wire:loading wire:target="commitImport">Processing…</span>
                                </button>
                            </div>
                        @endif

                        {{-- ── STEP C: Results (after commit) ──────────────────────────── --}}
                        @if($importProcessed)
                            {{-- Summary bar --}}
                            <div class="import-summary-bar">
                                <iconify-icon icon="mdi:check-circle"
                                              style="font-size:22px; color:#16a34a;"></iconify-icon>
                                <span class="import-summary-pill" style="color:#15803d;">
                    <iconify-icon icon="mdi:account-check"></iconify-icon>
                    {{ $importSuccessCount }} Created
                </span>
                                <span style="color:#e2e8f0;">|</span>
                                <span class="import-summary-pill" style="color:#dc2626;">
                    <iconify-icon icon="mdi:account-alert"></iconify-icon>
                    {{ $importErrorCount }} Failed
                </span>
                                <span style="color:#e2e8f0;">|</span>
                                <span class="import-summary-pill" style="color:#0369a1;">
                    <iconify-icon icon="mdi:fingerprint"></iconify-icon>
                    ZKBio sync attempted for all created records
                </span>
                                <div class="ms-auto">
                                    <button wire:click="resetImport" type="button"
                                            class="btn btn-sm"
                                            style="font-size:0.78rem; background:#f1f5f9; border:none; color:#1e293b; border-radius:7px; font-weight:600;">
                                        <iconify-icon icon="mdi:upload"></iconify-icon>
                                        Import Another File
                                    </button>
                                </div>
                            </div>

                            {{-- Per-row results table --}}
                            <div class="import-preview-wrap">
                                <table class="table table-hover mb-0">
                                    <thead>
                                    <tr>
                                        <th>Row</th>
                                        <th>Name</th>
                                        <th>ID / Adm. No.</th>
                                        <th>Status</th>
                                        <th>ZKBio</th>
                                        <th>Note</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($importResults as $r)
                                        <tr>
                                            <td class="text-muted">{{ $r['row'] }}</td>
                                            <td style="font-weight:600;">{{ $r['name'] }}</td>
                                            <td>{{ $r['id'] }}</td>
                                            <td>
                                                @if($r['status'] === 'success')
                                                    <span class="import-result-badge success">
                                        <iconify-icon icon="mdi:check-circle"></iconify-icon> Created
                                    </span>
                                                @else
                                                    <span class="import-result-badge error">
                                        <iconify-icon icon="mdi:close-circle"></iconify-icon> Failed
                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($r['status'] === 'success')
                                                    @if(str_starts_with($r['zk'], 'synced'))
                                                        <span class="import-result-badge zk-ok">
                                            <iconify-icon icon="mdi:fingerprint"></iconify-icon> Synced
                                        </span>
                                                    @elseif(str_starts_with($r['zk'], 'skipped'))
                                                        <span class="import-result-badge zk-skip">—</span>
                                                    @else
                                                        <span class="import-result-badge zk-err"
                                                              title="{{ $r['zk'] }}">
                                            <iconify-icon icon="mdi:alert"></iconify-icon> ZK Error
                                        </span>
                                                    @endif
                                                @else
                                                    <span class="import-result-badge zk-skip">—</span>
                                                @endif
                                            </td>
                                            <td style="font-size:0.75rem; color:{{ $r['status'] === 'error' ? '#dc2626' : '#64748b' }};">
                                                {{ $r['message'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>{{-- /import-body --}}
                </div>{{-- /import-accordion-wrap --}}
            @endif
            {{-- /showImportPanel --}}

            {{-- ══════════════════════════════════════════════════════════════
                  MICROSOFT AD SYNC PANEL
         ══════════════════════════════════════════════════════════════ --}}
            @if($showAdSyncPanel && !$isStudentOrg)
                <div class="mb-3"
                     style="border-radius:14px; border:1.5px solid #0078d4; background:#f0f6ff; overflow:hidden;">

                    {{-- Header --}}
                    <div class="d-flex align-items-center justify-content-between p-3"
                         style="border-bottom:1px solid #cce0f5; cursor:pointer;" wire:click="toggleAdSyncPanel">
                        <div class="d-flex align-items-center gap-3">
                            <div
                                style="width:38px;height:38px;background:#dbeafe;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#0078d4;font-size:20px;">
                                <iconify-icon icon="mdi:microsoft-azure"></iconify-icon>
                            </div>
                            <div>
                                <p style="font-size:0.9rem;font-weight:700;color:#1e293b;margin:0;">Microsoft Active
                                    Directory Sync</p>
                                <p style="pointer:cursor; font-size:0.75rem;color:#64748b;margin:0;">
                                    Fetch users from Cosmos AD tenant and import as employees with shift & ZKBio sync
                                    @if($adLastSynced)
                                        &nbsp;·&nbsp; Last
                                        synced: {{ \Carbon\Carbon::parse($adLastSynced)->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        <iconify-icon icon="mdi:chevron-up" style="color:#0078d4;font-size:20px;"></iconify-icon>
                    </div>

                    {{-- Body --}}
                    <div class="p-4">

                        {{-- Error --}}
                        @if($adSyncError)
                            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 mb-3"
                                 style="border-radius:9px;font-size:0.82rem;">
                                <iconify-icon icon="mdi:alert-circle-outline"
                                              style="font-size:18px;flex-shrink:0;"></iconify-icon>
                                {{ $adSyncError }}
                            </div>
                        @endif

                        {{-- STEP A: Initial fetch --}}
                        @if(!$adSyncPreviewed && !$adSyncProcessed)
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <div
                                        style="background:#fff;border:1px solid #e0edf8;border-radius:12px;padding:1.1rem;">
                                        <div
                                            style="width:28px;height:28px;background:#0078d4;border-radius:50%;color:#fff;font-weight:700;font-size:0.78rem;display:flex;align-items:center;justify-content:center;margin-bottom:0.65rem;">
                                            1
                                        </div>
                                        <p style="font-weight:700;font-size:0.85rem;color:#1e293b;margin-bottom:0.25rem;">
                                            Fetch from AD</p>
                                        <p style="font-size:0.76rem;color:#64748b;margin:0;">Pulls all users from your
                                            Microsoft Entra tenant with pagination.</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div
                                        style="background:#fff;border:1px solid #e0edf8;border-radius:12px;padding:1.1rem;">
                                        <div
                                            style="width:28px;height:28px;background:#0078d4;border-radius:50%;color:#fff;font-weight:700;font-size:0.78rem;display:flex;align-items:center;justify-content:center;margin-bottom:0.65rem;">
                                            2
                                        </div>
                                        <p style="font-weight:700;font-size:0.85rem;color:#1e293b;margin-bottom:0.25rem;">
                                            Review Preview</p>
                                        <p style="font-size:0.76rem;color:#64748b;margin:0;">See who's new vs already
                                            exists. New users get default shift & department.</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div
                                        style="background:#fff;border:1px solid #e0edf8;border-radius:12px;padding:1.1rem;">
                                        <div
                                            style="width:28px;height:28px;background:#0078d4;border-radius:50%;color:#fff;font-weight:700;font-size:0.78rem;display:flex;align-items:center;justify-content:center;margin-bottom:0.65rem;">
                                            3
                                        </div>
                                        <p style="font-weight:700;font-size:0.85rem;color:#1e293b;margin-bottom:0.25rem;">
                                            Import & Sync</p>
                                        <p style="font-size:0.76rem;color:#64748b;margin:0;">Creates employee accounts
                                            and syncs to ZKBio automatically.</p>
                                        <div class="d-flex gap-1 mt-2 flex-wrap">
                                            <span
                                                style="font-size:0.68rem;font-weight:600;background:#e0f2fe;color:#0369a1;border-radius:5px;padding:2px 8px;">ZKBio Sync</span>
                                            <span
                                                style="font-size:0.68rem;font-weight:600;background:#dcfce7;color:#15803d;border-radius:5px;padding:2px 8px;">Default Shift</span>
                                            <span
                                                style="font-size:0.68rem;font-weight:600;background:#ede9fe;color:#6d28d9;border-radius:5px;padding:2px 8px;">Auto Role</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- Default Areas Selection --}}
                            @if(!empty($availableZkbioAreas))
                                <div class="mb-4 p-3 rounded-3" style="background:#fff; border:1px solid #cce0f5;">
                                    <p class="mb-2 fw-semibold" style="font-size:0.82rem; color:#1e293b;">
                                        <iconify-icon icon="mdi:map-marker-multiple"
                                                      style="color:#0078d4; margin-right:4px;"></iconify-icon>
                                        Default Device Areas
                                        <small class="text-muted fw-normal ms-1">— assigned to all synced
                                            employees</small>
                                    </p>
                                    <div class="row g-2">
                                        @foreach($availableZkbioAreas as $area)
                                            <div class="col-md-6">
                                                <label class="d-flex align-items-center gap-2 p-2 rounded-2 w-100"
                                                       style="border:1.5px solid {{ in_array($area['area_code'], $defaultAdSyncAreas) ? '#0078d4' : '#e2e8f0' }};
                                  background:{{ in_array($area['area_code'], $defaultAdSyncAreas) ? '#f0f6ff' : '#fafafa' }};
                                  cursor:pointer; font-size:0.8rem;">
                                                    <input type="checkbox"
                                                           value="{{ $area['area_code'] }}"
                                                           wire:model="defaultAdSyncAreas"
                                                           style="accent-color:#0078d4; cursor:pointer;">
                                                    <iconify-icon icon="mdi:map-marker"
                                                                  style="color:{{ in_array($area['area_code'], $defaultAdSyncAreas) ? '#0078d4' : '#94a3b8' }};"></iconify-icon>
                                                    <span class="fw-semibold text-dark">{{ $area['area_name'] }}</span>
                                                    <small
                                                        class="text-muted ms-auto">Code: {{ $area['area_code'] }}</small>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if(!empty($defaultAdSyncAreas))
                                        <p class="mt-2 mb-0" style="font-size:0.72rem; color:#0078d4;">
                                            <iconify-icon icon="mdi:check-circle"></iconify-icon>
                                            {{ count($defaultAdSyncAreas) }} area(s) will be assigned to all synced
                                            employees
                                        </p>
                                    @endif
                                </div>
                            @endif

                            <div class="d-flex justify-content-end">

                                <button wire:click="previewAdSync" type="button"
                                        class="btn d-flex align-items-center gap-2"
                                        style="background:#0078d4;color:#fff;border:none;border-radius:8px;font-size:0.875rem;font-weight:600;padding:9px 20px;">
                <span wire:loading wire:target="previewAdSync">
                    <span class="spinner-border spinner-border-sm me-1"></span>
                </span>
                                    <iconify-icon icon="mdi:cloud-download-outline" wire:loading.remove
                                                  wire:target="previewAdSync"></iconify-icon>
                                    <span wire:loading wire:target="previewAdSync">Fetching from AD...</span>
                                    <span wire:loading.remove wire:target="previewAdSync">Fetch & Preview Users</span>
                                </button>
                            </div>
                        @endif

                        {{-- STEP B: Preview table --}}
                        @if($adSyncPreviewed && !$adSyncProcessed)
                            @php
                                $adNew      = collect($adPreview)->where('isNew', true)->count();
                                $adExisting = collect($adPreview)->where('isNew', false)->count();
                                $allAdIds   = collect($adPreview)->pluck('ad_id')->toArray();
                            @endphp

                            <div
                                x-data="{
            selected: [],
            allIds: @js($allAdIds),
            get allChecked() { return this.selected.length === this.allIds.length && this.allIds.length > 0; },
            get someChecked() { return this.selected.length > 0 && !this.allChecked; },
            toggleAll(checked) {
                this.selected = checked ? [...this.allIds] : [];
            },
            toggle(id) {
                this.selected.includes(id)
                    ? this.selected = this.selected.filter(x => x !== id)
                    : this.selected.push(id);
            }
        }"
                            >
                                {{-- Stats pills --}}
                                <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <span style="font-size:0.82rem;font-weight:700;color:#1e293b;">
                {{ count($adPreview) }} users found in AD
            </span>
                                    <span style="font-size:0.8rem;font-weight:700;color:#16a34a;">
                <iconify-icon icon="mdi:account-plus"></iconify-icon> {{ $adNew }} new
            </span>
                                    <span style="font-size:0.8rem;font-weight:700;color:#64748b;">
                <iconify-icon icon="mdi:account-check"></iconify-icon> {{ $adExisting }} already exist
            </span>
                                    @php $adDeactivating = collect($adPreview)->whereIn('action', ['disabled','removed'])->count(); @endphp
                                    @if($adDeactivating > 0)
                                        <span style="font-size:0.8rem;font-weight:700;color:#dc2626;">
        <iconify-icon icon="mdi:account-remove"></iconify-icon> {{ $adDeactivating }} will be deactivated
    </span>
                                    @endif
                                    {{-- Live selection counter --}}
                                    <span x-show="selected.length > 0"
                                          style="font-size:0.8rem;font-weight:700;color:#0078d4;background:#dbeafe;padding:2px 10px;border-radius:99px;">
                <iconify-icon icon="mdi:checkbox-marked"></iconify-icon>
                <span x-text="selected.length"></span> selected
            </span>
                                    <button wire:click="resetAdSync" type="button"
                                            class="btn btn-sm ms-auto"
                                            style="font-size:0.78rem;background:#f1f5f9;border:none;color:#64748b;border-radius:7px;">
                                        <iconify-icon icon="mdi:refresh"></iconify-icon>
                                        Re-fetch
                                    </button>
                                </div>

                                {{-- Preview table --}}
                                {{-- Preview table --}}
                                <div
                                    style="overflow-x:auto;border-radius:10px;border:1px solid #cce0f5;margin-bottom:1rem;">
                                    <table class="table table-hover mb-0" style="font-size:0.78rem;">
                                        <thead style="background:#e8f1fb;">
                                        <tr>
                                            <th style="padding:8px 12px;width:36px;">
                                                <input type="checkbox"
                                                       :checked="allChecked"
                                                       :indeterminate="someChecked"
                                                       @change="toggleAll($event.target.checked)"
                                                />
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Name
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Email
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Emp No.
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Job Title
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Department
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Division
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Section
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Phone
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Shift
                                            </th>
                                            <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                                Status
                                            </th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach(array_slice($adPreview, 0, 25) as $row)
                                            <tr style="{{ $row['isNew'] ? 'background:#f0fdf4;' : '' }}"
                                                :style="selected.includes('{{ $row['ad_id'] }}') ? 'outline:2px solid #0078d4;outline-offset:-2px;' : ''">
                                                <td style="padding:7px 12px;">
                                                    <input type="checkbox"
                                                           value="{{ $row['ad_id'] }}"
                                                           :checked="selected.includes('{{ $row['ad_id'] }}')"
                                                           @change="toggle('{{ $row['ad_id'] }}')"
                                                    />
                                                </td>
                                                <td style="padding:7px 12px;font-weight:600;color:#1e293b;">{{ $row['name'] }}</td>
                                                <td style="padding:7px 12px;color:#64748b;">{{ $row['email'] }}</td>
                                                <td style="padding:7px 12px;">
                                                    @if($row['employee_id'])
                                                        <span
                                                            style="font-size:0.7rem;font-weight:600;background:#ede9fe;color:#6d28d9;border-radius:5px;padding:2px 8px;">
                            {{ $row['employee_id'] }}
                        </span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td style="padding:7px 12px;color:#64748b;">{{ $row['job_title'] !== '?' ? $row['job_title'] : '—' }}</td>
                                                <td style="padding:7px 12px;">
                                                    @if($row['department'])
                                                        <span
                                                            style="font-size:0.7rem;font-weight:600;background:#fde8e3;color:#c0341b;border-radius:5px;padding:2px 8px;">
                            {{ $row['department'] }}
                        </span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td style="padding:7px 12px;">
                                                    @if($row['division'])
                                                        <span
                                                            style="font-size:0.7rem;font-weight:600;background:#e0f2fe;color:#0369a1;border-radius:5px;padding:2px 8px;">
                            {{ $row['division'] }}
                        </span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td style="padding:7px 12px;">
                                                    @if($row['section'])
                                                        <span
                                                            style="font-size:0.7rem;font-weight:600;background:#dcfce7;color:#15803d;border-radius:5px;padding:2px 8px;">
                            {{ $row['section'] }}
                        </span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td style="padding:7px 12px;color:#64748b;">{{ $row['phone'] !== '?' ? $row['phone'] : '—' }}</td>
                                                <td style="padding:7px 12px;">
                    <span
                        style="font-size:0.7rem;font-weight:600;background:#e0f2fe;color:#0369a1;border-radius:5px;padding:2px 8px;">
                        {{ $row['shift'] }}
                    </span>
                                                </td>
                                                <td style="padding:7px 12px;">
                                                    @if($row['isNew'])
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#dcfce7;color:#15803d;">
        <iconify-icon icon="mdi:plus-circle"></iconify-icon> New
    </span>
                                                    @elseif(($row['action'] ?? '') === 'disabled')
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#fee2e2;color:#dc2626;">
        <iconify-icon icon="mdi:account-cancel"></iconify-icon> Disabled in AD
    </span>
                                                    @elseif(($row['action'] ?? '') === 'removed')
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#fef3c7;color:#92400e;">
        <iconify-icon icon="mdi:account-remove"></iconify-icon> Removed from AD
    </span>
                                                    @else
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#f1f5f9;color:#64748b;">
        <iconify-icon icon="mdi:refresh"></iconify-icon> Update
    </span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        @if(count($adPreview) > 25)
                                            <tr>
                                                <td colspan="11" class="text-center text-muted py-2"
                                                    style="font-size:0.75rem;background:#f8fafc;font-style:italic;">
                                                    <iconify-icon icon="mdi:dots-horizontal"
                                                                  style="margin-right:4px;"></iconify-icon>
                                                    {{ count($adPreview) - 25 }} more users not shown — all will be
                                                    processed unless you select specific rows above
                                                </td>
                                            </tr>
                                        @endif
                                        </tbody>
                                    </table>
                                </div>

                                <div class="alert d-flex align-items-start gap-2 py-2 px-3 mb-3"
                                     style="border-radius:9px;font-size:0.8rem;background:#e0f2fe;border:1px solid #bae6fd;color:#075985;">
                                    <iconify-icon icon="mdi:information-outline"
                                                  style="font-size:18px;flex-shrink:0;margin-top:1px;"></iconify-icon>
                                    <span>
                New employees will be assigned the <strong>default Day Shift</strong> and
                <strong>first available department</strong>. You can edit these after import.
                Existing employees will have their name, phone and AD ID updated only.
                <strong>Leave all unchecked to process everyone.</strong>
            </span>
                                </div>

                                <div class="d-flex justify-content-end gap-2">
                                    <button wire:click="resetAdSync" type="button"
                                            class="btn btn-outline-danger"
                                            style="font-size:0.875rem;border-radius:8px;">
                                        Cancel
                                    </button>

                                    {{-- The key: sync Alpine selection to Livewire right before committing --}}
                                    <button type="button"
                                            wire:loading.attr="disabled"
                                            wire:target="commitAdSync"
                                            @click="
                        $wire.set('selectedAdUsers', selected);
                        $nextTick(() => $wire.commitAdSync());
                    "
                                            class="btn d-flex align-items-center gap-2"
                                            style="background:#0078d4;color:#fff;border:none;border-radius:8px;font-size:0.875rem;font-weight:600;padding:9px 20px;">
                                        <iconify-icon icon="mdi:account-multiple-plus"></iconify-icon>
                                        <span x-text="
                    selected.length > 0
                        ? 'Import ' + selected.length + ' Selected'
                        : 'Import {{ $adNew }} New & Update {{ $adExisting }}'
                "></span>
                                    </button>
                                </div>

                            </div>{{-- /x-data --}}
                        @endif

                        {{-- STEP C: Results --}}
                        @if($adSyncProcessed)
                            <div class="d-flex align-items-center gap-3 mb-3 flex-wrap"
                                 style="background:#fff;border-radius:10px;border:1px solid #cce0f5;padding:0.85rem 1.2rem;">
                                <iconify-icon icon="mdi:check-circle"
                                              style="font-size:22px;color:#16a34a;"></iconify-icon>
                                <span style="font-size:0.8rem;font-weight:700;color:#15803d;">
                <iconify-icon icon="mdi:account-plus"></iconify-icon> {{ $adImportedCount }} Imported
            </span>
                                <span style="color:#e2e8f0;">|</span>
                                <span style="font-size:0.8rem;font-weight:700;color:#0369a1;">
                <iconify-icon icon="mdi:refresh"></iconify-icon> {{ $adUpdatedCount }} Updated
            </span>
                                <span style="color:#e2e8f0;">|</span>
                                <span style="font-size:0.8rem;font-weight:700;color:#dc2626;">
                <iconify-icon icon="mdi:alert"></iconify-icon> {{ $adErrorCount }} Failed
            </span>
                                <span style="color:#e2e8f0;">|</span>
                                <span style="font-size:0.8rem;font-weight:700;color:#dc2626;">
    <iconify-icon icon="mdi:account-remove"></iconify-icon> {{ $adDeactivatedCount }} Deactivated
</span>
                                <button wire:click="resetAdSync" type="button"
                                        class="btn btn-sm ms-auto"
                                        style="font-size:0.78rem;background:#f1f5f9;border:none;color:#1e293b;border-radius:7px;font-weight:600;">
                                    <iconify-icon icon="mdi:refresh"></iconify-icon>
                                    Sync Again
                                </button>
                            </div>

                            <div style="overflow-x:auto;border-radius:10px;border:1px solid #cce0f5;">
                                <table class="table table-hover mb-0" style="font-size:0.78rem;">
                                    <thead style="background:#e8f1fb;">
                                    <tr>
                                        <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                            Name
                                        </th>
                                        <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                            Email
                                        </th>
                                        <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                            Status
                                        </th>
                                        <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                            ZKBio
                                        </th>
                                        <th style="padding:8px 12px;color:#1e4d8c;font-size:0.72rem;text-transform:uppercase;">
                                            Note
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($adResults as $r)
                                        <tr>
                                            <td style="padding:7px 12px;font-weight:600;">{{ $r['name'] }}</td>
                                            <td style="padding:7px 12px;color:#64748b;">{{ $r['email'] }}</td>
                                            <td style="padding:7px 12px;">
                                                @if($r['status'] === 'imported')
                                                    <span
                                                        style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#dcfce7;color:#15803d;">
                                <iconify-icon icon="mdi:check-circle"></iconify-icon> Imported
                            </span>
                                                @elseif($r['status'] === 'deactivated')
                                                    <span
                                                        style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#fee2e2;color:#dc2626;">
        <iconify-icon icon="mdi:account-remove"></iconify-icon> Deactivated
    </span>
                                                @elseif($r['status'] === 'updated')
                                                    <span
                                                        style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#e0f2fe;color:#0369a1;">
                                <iconify-icon icon="mdi:refresh"></iconify-icon> Updated
                            </span>
                                                @else
                                                    <span
                                                        style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#fee2e2;color:#dc2626;">
                                <iconify-icon icon="mdi:close-circle"></iconify-icon> Failed
                            </span>
                                                @endif
                                            </td>
                                            <td style="padding:7px 12px;">
                                                @if(isset($r['zk']))
                                                    @if($r['zk'] === 'synced')
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#e0f2fe;color:#0369a1;">
                                    <iconify-icon icon="mdi:fingerprint"></iconify-icon> Synced
                                </span>
                                                    @elseif($r['zk'] === 'failed')
                                                        <span
                                                            style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:99px;background:#fef9c3;color:#a16207;">
                                    <iconify-icon icon="mdi:alert"></iconify-icon> ZK Error
                                </span>
                                                    @else
                                                        <span style="color:#94a3b8;font-size:0.72rem;">—</span>
                                                    @endif
                                                @else
                                                    <span style="color:#94a3b8;font-size:0.72rem;">—</span>
                                                @endif
                                            </td>
                                            <td style="padding:7px 12px;font-size:0.75rem;color:{{ $r['status'] === 'error' ? '#dc2626' : '#64748b' }};">
                                                {{ $r['message'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                    </div>{{-- /body --}}
                </div>
            @endif
            {{-- /AD sync panel --}}

            <livewire:employee-table
                :type="$userType ?? null"
                theme="bootstrap-4"
                :key="'emp-table-' . $userType"
            />

        </div>

    </div>

    {{-- CREATE / EDIT MODAL --}}
    <div class="modal fade" id="employeeModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $modalTitle }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form wire:submit.prevent="{{ $editId ? 'updateEmployee' : 'createEmployee' }}">
                    <div class="modal-body">

                        {{-- Hidden shift resolver --}}
                        <input type="hidden" wire:model="shift_id"/>

                        <div class="row">

                            @if($isStudentOrg)
                                <div class="col-12 mb-3">
                                    <label class="form-label fw-semibold mb-2">Creating a</label>
                                    <div class="person-type-toggle">
                                        <input type="radio" wire:model.live="personType" value="student"
                                               id="typeStudent">
                                        <label for="typeStudent">
                                            <iconify-icon icon="mdi:school"
                                                          style="font-size:16px;margin-right:4px;"></iconify-icon>
                                            Student</label>
                                        <input type="radio" wire:model.live="personType" value="staff" id="typeStaff">
                                        <label for="typeStaff">
                                            <iconify-icon icon="mdi:account-tie"
                                                          style="font-size:16px;margin-right:4px;"></iconify-icon>
                                            Staff</label>
                                    </div>
                                </div>
                            @endif

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" wire:model="name" class="form-control"
                                       placeholder="{{ $isStudent ? 'e.g. Jane Kamau' : 'e.g. Mr. James Odhiambo' }}"/>
                                @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ $isStudent ? 'Student ID / Admission No.' : 'ID Number' }}
                                    <span class="text-danger">*</span></label>
                                <input type="text" wire:model="id_number" class="form-control"
                                       placeholder="{{ $isStudent ? 'e.g. STU-0041' : 'e.g. 12345678' }}"/>
                                @error('id_number') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            @if(!$isStudentOrg || $personType === 'staff')
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" wire:model="email" class="form-control"
                                           placeholder="e.g. james.o@school.ac.ke"/>
                                    @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="text" wire:model="phone" class="form-control"
                                           placeholder="e.g. 254712345678"/>
                                    @error('phone') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                            @endif

                            {{--                            <div class="col-md-6 mb-3">--}}
                            {{--                                <label class="form-label">{{ $isStudentOrg ? 'Session / Timetable' : 'Shift' }} <span class="text-danger">*</span></label>--}}
                            {{--                                <select wire:model="shift_id" class="form-control">--}}
                            {{--                                    <option value="">Select {{ $isStudentOrg ? 'Session' : 'Shift' }}</option>--}}
                            {{--                                    @foreach ($shifts as $shift)--}}
                            {{--                                        <option value="{{ $shift->id }}">{{ $shift->name }}</option>--}}
                            {{--                                    @endforeach--}}
                            {{--                                </select>--}}
                            {{--                                @error('shift_id') <small class="text-danger">{{ $message }}</small> @enderror--}}
                            {{--                            </div>--}}

                            @if($isStudent)
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Year Group<span class="text-danger">*</span></label>
                                    <select wire:model="grade" class="form-control">
                                        <option value="">Select Year Group</option>
                                        @foreach ($grades as $g)
                                            <option value="{{ $g }}">{{ $g }}</option>
                                        @endforeach
                                    </select>
                                    @error('grade') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                            @else
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Department <span class="text-danger">*</span></label>
                                    <select wire:model="department_id" class="form-control">
                                        <option value="">Select Department</option>
                                        @foreach ($departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('department_id') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                            @endif

                            <div class="col-md-6 mb-3">
                                <label
                                    class="form-label">{{ $isStudent ? 'Stream (optional)' : 'Title / Role' }}</label>
                                <input type="text" wire:model="employee_title" class="form-control"
                                       placeholder="{{ $isStudent ? 'e.g. North, East, Blue' : 'e.g. Teacher, Nurse, Housemaster' }}"/>
                                @error('employee_title') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>

                            @if(!$isStudentOrg || $personType === 'staff')
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">System Role <span class="text-danger">*</span></label>
                                    <select wire:model="roleName" class="form-control">
                                        <option value="">Select Role</option>
                                        @foreach ($roles as $id => $name)
                                            <option value="{{ $name }}">{{ ucfirst($name) }}</option>
                                        @endforeach
                                    </select>
                                    @error('roleName') <small class="text-danger">{{ $message }}</small> @enderror
                                </div>
                            @endif

                            @if(!$isStudent)
                                <div class="col-12 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" wire:model="active" class="form-check-input"
                                               id="activeToggle"/>
                                        <label for="activeToggle" class="form-check-label">Active</label>
                                    </div>
                                </div>
                            @endif

                        </div>
                    </div>
                    <div class="modal-footer d-flex gap-1">
                        <button type="submit" class="btn btn-success">{{ $editId ? 'Save Changes' : 'Create' }}</button>
                        <button wire:click="$dispatch('discard-employee-modal')" type="button"
                                class="btn btn-outline-danger" data-bs-dismiss="modal">Discard
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- WORK LOCATION MODAL --}}
    <div class="modal fade" id="workLocationModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Work Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form wire:submit.prevent="assignWorkLocation">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                                       wire:model="start_date">
                                @error('start_date')
                                <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-6">
                                <label class="form-label">End Date (optional)</label>
                                <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                       wire:model="end_date">
                                @error('end_date')
                                <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Search Location</label>
                            <input type="text" wire:keyup.debounce.500ms="$dispatch('search-work-location')"
                                   wire:model="search" class="form-control" placeholder="Type to search locations..."/>
                            @if(!empty($search) && !$selectedLocation)
                                <ul class="list-group mt-2" style="max-height:200px;overflow-y:auto;">
                                    @forelse($workLocations as $location)
                                        <li class="list-group-item list-group-item-action"
                                            wire:click="selectWorkLocation({{ $location->id }})"
                                            style="cursor:pointer;">
                                            <strong>{{ ucfirst(str_replace('_', ' ', $location->name)) }}</strong>
                                            <br><small class="text-muted">{{ $location->address }}</small>
                                        </li>
                                    @empty
                                        <li class="list-group-item text-muted">No locations found.</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- OFF-SHIFT MODAL --}}
    <div class="modal fade" id="offShiftModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content shadow-lg">
                <div class="modal-header bg-light rounded-top">
                    <h5 class="modal-title">Set Off-Shift Dates for {{ $employeeName }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert {{ $shiftStatus === 'off_shift' ? 'alert-warning' : 'alert-success' }}">
                        Current status:
                        <strong>{{ $shiftStatus === 'off_shift' ? 'Off Shift' : 'On Active Shift' }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Start Off-Shift Date</label>
                        <input type="date" wire:model="start_off_shift_date" class="form-control">
                        @error('start_off_shift_date') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Off-Shift Date</label>
                        <input type="date" wire:model="end_off_shift_date" class="form-control">
                        @error('end_off_shift_date') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" wire:click="saveOffShiftDates">Save</button>
                </div>
            </div>
        </div>
    </div>

    {{-- AREA MODEL--}}
    <div class="modal fade" id="areaModal" tabindex="-1" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">

                {{-- Header --}}
                <div class="modal-header"
                     style="background:var(--primary-color) !important; color:#fff; border-radius:12px 12px 0 0;">
                    <div class="d-flex align-items-center gap-2">
                        <iconify-icon icon="mdi:map-marker-radius" style="font-size:22px;"></iconify-icon>
                        <div>
                            <h6 class="mb-0 fw-700" style="font-size:0.95rem; color:white;">ZKBio Area Access</h6>
                            @if($employee)
                                <small style="opacity:0.8; font-size:0.75rem;">{{ $employee->name }}</small>
                            @endif
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                {{-- Body --}}
                <div class="modal-body p-4">

                    {{-- Employee info strip --}}
                    @if($employee)
                        <div class="d-flex align-items-center gap-3 p-3 mb-4 rounded-3"
                             style="background:#f0f6ff; border:1px solid #cce0f5;">
                            <iconify-icon icon="tabler:user" style="font-size:28px; color:#0078d4;"></iconify-icon>
                            <div>
                                <p class="mb-0 fw-semibold text-dark"
                                   style="font-size:0.88rem;">{{ $employee->name }}</p>
                                <small class="text-muted">
                                    PIN: <strong>{{ $employee->zkbio_pin ?? 'No PIN' }}</strong>
                                    @if($employee->department)
                                        &nbsp;·&nbsp; {{ $employee->department->name }}
                                    @endif
                                </small>
                            </div>
                        </div>
                    @endif

                    {{-- Areas header --}}
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <p class="mb-0 fw-semibold text-dark" style="font-size:0.85rem;">
                            <iconify-icon icon="mdi:map-marker-multiple" class="me-1"
                                          style="color:#0078d4;"></iconify-icon>
                            Select Access Areas
                        </p>
                        <button wire:click="refreshAreas" type="button"
                                class="btn btn-sm"
                                style="font-size:0.72rem; background:#f1f5f9; border:none; color:#64748b; border-radius:6px;">
                            <iconify-icon icon="mdi:refresh" style="font-size:13px;"></iconify-icon>
                            Refresh from ZKBio
                        </button>
                    </div>

                    @if(empty($availableAreas))
                        <div class="text-center py-4 text-muted" style="font-size:0.82rem;">
                            <iconify-icon icon="mdi:alert-circle-outline" style="font-size:28px;"></iconify-icon>
                            <p class="mt-2 mb-0">No areas found. Click "Refresh from ZKBio" to load.</p>
                        </div>
                    @else
                        {{-- Areas grid - 2 columns --}}
                        <div class="row g-2">
                            @foreach($availableAreas as $area)
                                <div class="col-md-6">
                                    <label class="d-flex align-items-center gap-3 p-3 rounded-3 w-100"
                                           style="border: 1.5px solid {{ in_array($area['area_code'], $selectedAreas) ? '#0078d4' : '#e2e8f0' }};
                                              background: {{ in_array($area['area_code'], $selectedAreas) ? '#f0f6ff' : '#fff' }};
                                              cursor:pointer; transition: all 0.2s;">
                                        <input
                                            type="checkbox"
                                            value="{{ $area['area_code'] }}"
                                            wire:model="selectedAreas"
                                            style="width:16px; height:16px; accent-color:#0078d4; cursor:pointer;"
                                        />
                                        <div class="d-flex align-items-center gap-2 flex-grow-1">
                                            <div style="width:32px; height:32px; background:{{ in_array($area['area_code'], $selectedAreas) ? '#dbeafe' : '#f1f5f9' }};
                                                    border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                <iconify-icon icon="mdi:map-marker"
                                                              style="font-size:16px; color:{{ in_array($area['area_code'], $selectedAreas) ? '#0078d4' : '#94a3b8' }};"></iconify-icon>
                                            </div>
                                            <div>
                                                <p class="mb-0 fw-semibold"
                                                   style="font-size:0.85rem; color:#1e293b;">{{ $area['area_name'] }}</p>
                                                <small class="text-muted"
                                                       style="font-size:0.7rem;">Code: {{ $area['area_code'] }}</small>
                                            </div>
                                        </div>
                                        @if(in_array($area['area_code'], $selectedAreas))
                                            <iconify-icon icon="mdi:check-circle"
                                                          style="font-size:18px; color:#0078d4; flex-shrink:0;"></iconify-icon>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        {{-- Selection summary --}}
                        <div class="mt-3 p-2 rounded-2 text-center"
                             style="background:#f8fafc; font-size:0.78rem; color:#64748b;">
                            <iconify-icon icon="mdi:checkbox-marked" class="me-1"></iconify-icon>
                            <strong>{{ count($selectedAreas) }}</strong> of
                            <strong>{{ count($availableAreas) }}</strong> areas selected
                            @if(count($selectedAreas) === count($availableAreas))
                                &nbsp;·&nbsp; <span style="color:#16a34a; font-weight:600;">Full Access</span>
                            @elseif(count($selectedAreas) === 0)
                                &nbsp;·&nbsp; <span style="color:#dc2626; font-weight:600;">No Access</span>
                            @else
                                &nbsp;·&nbsp; <span style="color:#0078d4; font-weight:600;">Restricted Access</span>
                            @endif
                        </div>
                    @endif

                </div>

                {{-- Footer --}}
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button wire:click="saveAreas"
                            wire:loading.attr="disabled"
                            wire:target="saveAreas"
                            type="button"
                            class="btn btn-sm d-flex align-items-center gap-2"
                            style="background:#fff; border:1.5px solid var(--primary-color) !important; color:var(--primary-color) !important;  border-radius:8px;">
                    <span wire:loading wire:target="saveAreas">
                        <span class="spinner-border spinner-border-sm"></span>
                    </span>
                        <iconify-icon icon="mdi:content-save" wire:loading.remove
                                      wire:target="saveAreas"></iconify-icon>
                        <span wire:loading.remove wire:target="saveAreas">Save Areas</span>
                        <span wire:loading wire:target="saveAreas">Saving...</span>
                    </button>
                </div>

            </div>
        </div>
    </div>

</div>

@push('scripts')
    <script>
        window.addEventListener('show-work-location-modal', () => {
            new bootstrap.Modal(document.getElementById('workLocationModal')).show();
        });
        window.addEventListener('hide-work-location-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('workLocationModal'))?.hide();
        });
        window.addEventListener('show-employee-modal', () => {
            new bootstrap.Modal(document.getElementById('employeeModal')).show();
        });
        window.addEventListener('hide-employee-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('employeeModal'))?.hide();
        });
        window.addEventListener('show-off-shift-modal', () => {
            new bootstrap.Modal(document.getElementById('offShiftModal')).show();
        });
        window.addEventListener('hide-off-shift-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('offShiftModal'))?.hide();
        });

        window.addEventListener('show-area-modal', () => {
            new bootstrap.Modal(document.getElementById('areaModal')).show();
        });
        window.addEventListener('hide-area-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('areaModal'))?.hide();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const observer = new MutationObserver(() => {
                const dropdown = document.querySelector('.dropdown-menu[role="menu"]');
                if (dropdown && !dropdown.querySelector('.filter-close-button')) {
                    const closeBtn = document.createElement('button');
                    closeBtn.innerHTML = '&times;';
                    closeBtn.className = 'filter-close-button';
                    closeBtn.setAttribute('type', 'button');
                    closeBtn.onclick = () => {
                        document.querySelector('.dropdown-menu[role="menu"]')?.classList.remove('show');
                    };
                    dropdown.insertBefore(closeBtn, dropdown.firstChild);
                }
            });
            observer.observe(document.body, {childList: true, subtree: true});
        });
    </script>
@endpush
