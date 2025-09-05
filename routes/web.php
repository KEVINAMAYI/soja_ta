<?php

use App\Http\Controllers\PDFExports\AttendanceExportController;
use App\Http\Controllers\PDFExports\ClientsExportController;
use App\Http\Controllers\PDFExports\EmployeeExportController;
use App\Jobs\SendReportJob;
use App\Models\ReportSetting;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;


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



