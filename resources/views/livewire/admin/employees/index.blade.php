<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
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

new class extends Component {

    use WithFileUploads;

    public array $grades = [
        'PP1', 'PP2',
        'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4',
        'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9',
        'Form 1', 'Form 2', 'Form 3', 'Form 4',
        'Year 1', 'Year 2', 'Year 3', 'Year 4',
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
                ? ['Full Name', 'ID / Admission No.', 'Grade', 'Stream']
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
                \Illuminate\Support\Facades\DB::beginTransaction();

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

                // Resolve department
                $deptName = $row['department'] ?? '';
                $dept = $org->departments->firstWhere('name', $deptName)
                    ?? $org->departments->first();
                $deptId = $dept?->id;

                $roleName = $isStudent ? 'student' : ($row['role'] ?? 'employee');
                $email = $isStudent
                    ? "student_{$idNumber}@{$org->id}.local"
                    : ($row['email'] ?? '');
                $phone = $isStudent
                    ? ($org->phone_number ?? '')
                    : \App\Helpers\PhoneSanitizer::sanitize($row['phone'] ?? '');

                $user = \App\Models\User::create([
                    'name' => $name,
                    'email' => $email ?: "auto_{$idNumber}_{$org->id}@internal.local",
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                ]);

                $employee = \App\Models\Employee::create([
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
                $defaultLocation = \App\Models\WorkLocation::where('organization_id', $org->id)
                    ->where('is_default', true)->first();
                if ($defaultLocation) {
                    \App\Models\EmployeeAssignment::updateOrCreate(
                        ['employee_id' => $employee->id],
                        ['work_location_id' => $defaultLocation->id, 'start_date' => null, 'end_date' => null, 'is_current' => true]
                    );
                }

                \Illuminate\Support\Facades\DB::commit();

                // ── ZKBio Sync ──────────────────────────────────────────────
                $zkStatus = 'skipped';
                try {
                    app(\App\Services\ZKBioPersonService::class, ['organization' => $org])
                        ->syncPerson($employee->fresh());
                    $zkStatus = 'synced';
                } catch (\Throwable $zkErr) {
                    $zkStatus = 'zk_failed: ' . $zkErr->getMessage();
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
                \Illuminate\Support\Facades\DB::rollBack();
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

            // reset unused properties so they don't bleed through
            $this->totalStudents = 0;
            $this->totalStaff = 0;
            $this->presentCount = 0;
            $this->leftSchoolCount = 0;
            $this->notReportedCount = 0;
            $this->staffPresentCount = 0;
            $this->staffLeftCount = 0;
            $this->staffNotReportedCount = 0;
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
            app(ZKBioPersonService::class, ['organization' => $org])->syncPerson($employee->fresh());
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
            if ($employee->zkbio_pin) {
                app(ZKBioPersonService::class, ['organization' => $org])->deletePerson($employee->zkbio_pin);
            }
            $employee->delete();
            LivewireAlert::title('Success!')->text("{$label} deleted successfully.")->success()->toast()->position('top-end')->show();
            $this->dispatch('refreshDatatable');
            $this->loadSummaryStats();
        } catch (\Exception $e) {
            LivewireAlert::title('Error!')->text('Something went wrong.')->error()->toast()->position('top-end')->show();
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

}; ?>

@push('styles')
    <style>

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
                        <p class="summary-card-title">Unscanned</p>
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

                {{-- Total Employees --}}
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="summary-card">
                        <div class="summary-card-icon" style="background:#ede9fe; color:#7c3aed;">
                            <iconify-icon icon="mdi:account-group"></iconify-icon>
                        </div>
                        <p class="summary-card-title">Total Employees</p>
                        <div class="summary-card-value">{{ $totalEmployees }}</div>
                        <p class="summary-card-subtitle">All registered employees</p>
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

                    {{-- Single create (existing) --}}
                    <a href="javascript:void(0)"
                       class="btn btn-primary d-flex align-items-center gap-2"
                       data-bs-toggle="modal" data-bs-target="#employeeModal">
                        <i class="ti ti-user-plus fs-5"></i>
                        Add {{ $isStudent ? 'Student' : ($isStudentOrg ? 'Staff' : 'Employee') }}
                    </a>
                </div>
            </div>


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
                                <button wire:click="commitImport" type="button"
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
                                    <label class="form-label">Grade <span class="text-danger">*</span></label>
                                    <select wire:model="grade" class="form-control">
                                        <option value="">Select Grade</option>
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
