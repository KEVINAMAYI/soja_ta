<?php

use App\Http\Controllers\PDFExports\AttendanceExportController;
use App\Http\Controllers\PDFExports\ClientsExportController;
use App\Http\Controllers\PDFExports\EmployeeExportController;
use App\Jobs\SendReportJob;
use App\Models\ReportSetting;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use App\Services\AttendanceSeeder;
use App\Models\Attendance;
use Carbon\Carbon;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile')->name('user.settings');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
});


require __DIR__ . '/auth.php';
require __DIR__ . '/dashboard/admin.php';

// This route will handle the PDF download
Route::get('/employees/export/daily/pdf', [EmployeeExportController::class, 'exportEmployeePdf'])->name('employees.export.pdf');
Route::get('/attendance/daily/pdf', [AttendanceExportController::class, 'exportAttendanceDailyPdf'])->name('attendance-daily.export.pdf');
Route::get('/attendance/monthly/pdf', [AttendanceExportController::class, 'exportAttendanceMonthlyPdf'])->name('attendance-monthly.export.pdf');
Route::get('/attendance/department/pdf', [AttendanceExportController::class, 'exportAttendanceDepartmentPdf'])->name('department-attendance.export.pdf');
Route::get('/clients/export/pdf', [ClientsExportController::class, 'exportClientsPdf'])->name('clients.export.pdf');



Route::get('/test-attendance-seeder', function (AttendanceSeeder $seeder) {

    // Optional: limit to a specific organization for testing
    $orgId = 1;

    info("========== TEST ATTENDANCE SEEDER START ==========");
    $seeder->seedMissingAttendanceRecords($orgId);
    info("========== TEST ATTENDANCE SEEDER END ==========");

    // Return a simple summary
    $today = now()->toDateString();
    $attendances = \App\Models\Attendance::with('employee')->whereDate('date', $today)->get();

    $summary = $attendances->map(function ($a) {
        return [
            'employee_id' => $a->employee_id,
            'employee_name' => $a->employee->name,
            'status' => $a->status,
            'check_in' => $a->check_in_time,
            'check_out' => $a->check_out_time,
            'worked_hours' => $a->worked_hours,
        ];
    });

    return response()->json($summary);
});




Route::get('/test-attendance-scenarios', function () {

    // 1. Employee never checked in
    Attendance::where('employee_id', 1)->whereDate('date', now()->toDateString())->delete();

    // 2. Employee clocked in but not out
    Attendance::updateOrCreate(
        ['employee_id' => 2, 'date' => now()->toDateString()],
        ['check_in_time' => Carbon::now()->subHours(9), 'status' => 'clocked_in']
    );

    // 3. Employee already clocked out
    Attendance::updateOrCreate(
        ['employee_id' => 3, 'date' => now()->toDateString()],
        [
            'check_in_time' => Carbon::now()->subHours(8),
            'check_out_time' => Carbon::now()->subHours(1),
            'status' => 'clocked_out'
        ]
    );

    return response()->json([
        'message' => 'Test attendance scenarios created successfully.',
        'date' => now()->toDateString()
    ]);
});



