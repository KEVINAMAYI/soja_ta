<?php

use App\Http\Controllers\Impersonation\ImpersonationSessionController;
use App\Http\Controllers\PDFExports\AttendanceExportController;
use App\Http\Controllers\PDFExports\ClientsExportController;
use App\Http\Controllers\PDFExports\EmployeeExportController;
use App\Http\Controllers\PDFExports\LeaveBalanceExportController;
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
require __DIR__ . '/dashboard/superadmin.php';

// Super-admin impersonation handoff: the token is single-use and short-lived.
Route::get('impersonate/{token}', [ImpersonationSessionController::class, 'enter'])
    ->middleware('throttle:10,1')
    ->name('impersonation.enter');
Route::post('impersonate/stop', [ImpersonationSessionController::class, 'leave'])
    ->middleware('auth')
    ->name('impersonation.stop');

// This route will handle the PDF download
Route::get('/employees/export/daily/pdf', [EmployeeExportController::class, 'exportEmployeePdf'])->name('employees.export.pdf');
Route::get('/attendance/daily/pdf', [AttendanceExportController::class, 'exportAttendanceDailyPdf'])->name('attendance-daily.export.pdf');
Route::get('/attendance/monthly/pdf', [AttendanceExportController::class, 'exportAttendanceMonthlyPdf'])->name('attendance-monthly.export.pdf');
Route::get('/attendance/department/pdf', [AttendanceExportController::class, 'exportAttendanceDepartmentPdf'])->name('department-attendance.export.pdf');
Route::get('/clients/export/pdf', [ClientsExportController::class, 'exportClientsPdf'])->name('clients.export.pdf');
Route::get('/leave-balances/export/pdf', [LeaveBalanceExportController::class, 'exportPdf'])->name('leave-balances.export.pdf');


//TEST JOBS
// Test route to trigger report job manually
Route::get('/test-report-job/{settingId?}', function ($settingId = null) {

    // Get setting ID from URL or find first active setting
    if (!$settingId) {
        $setting = ReportSetting::where('active', true)
            ->where('organization_id', auth()->user()->employee->organization_id ?? null)
            ->first();

        if (!$setting) {
            return response()->json([
                'error' => 'No active report settings found',
                'message' => 'Create a report schedule first or pass a setting ID in the URL: /test-report-job/1'
            ], 404);
        }

        $settingId = $setting->id;
    } else {
        $setting = ReportSetting::find($settingId);

        if (!$setting) {
            return response()->json([
                'error' => 'Report setting not found',
                'message' => "No report setting with ID {$settingId}"
            ], 404);
        }
    }

    try {
        // Force log to file before job runs
        $logFile = storage_path('logs/test-route.log');
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] 🚀 Test route triggered for Setting#{$settingId}\n";
        file_put_contents($logFile, $entry, FILE_APPEND);

        // Dispatch the job synchronously (runs immediately, not queued)
        // This helps us see errors directly
        SendReportJob::dispatchSync($settingId, $setting->organization_id);

        file_put_contents($logFile, "[{$timestamp}] ✅ Job completed successfully\n", FILE_APPEND);

        return response()->json([
            'success' => true,
            'message' => 'Report job dispatched successfully (synchronous)',
            'setting_id' => $settingId,
            'email' => $setting->email,
            'report_type' => $setting->report_type,
            'organization_id' => $setting->organization_id,
            'logs' => [
                'Check storage/logs/test-route.log',
                'Check storage/logs/job-debug.log',
                'Check storage/logs/laravel.log',
            ]
        ]);

    } catch (\Throwable $e) {
        // Log error to test file
        $logFile = storage_path('logs/test-route.log');
        $timestamp = date('Y-m-d H:i:s');
        $errorEntry = "[{$timestamp}] ❌ ERROR: {$e->getMessage()}\n";
        $errorEntry .= "Line: {$e->getLine()}\n";
        $errorEntry .= "File: {$e->getFile()}\n\n";
        file_put_contents($logFile, $errorEntry, FILE_APPEND);

        return response()->json([
            'error' => true,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
})->name('test.report.job');

// Alternative: Dispatch to queue (async)
Route::get('/test-report-job-queue/{settingId?}', function ($settingId = null) {

    if (!$settingId) {
        $setting = ReportSetting::where('active', true)
            ->where('organization_id', auth()->user()->employee->organization_id ?? null)
            ->first();

        if (!$setting) {
            return response()->json([
                'error' => 'No active report settings found'
            ], 404);
        }

        $settingId = $setting->id;
    } else {
        $setting = ReportSetting::find($settingId);

        if (!$setting) {
            return response()->json([
                'error' => 'Report setting not found'
            ], 404);
        }
    }

    try {
        // Dispatch to queue (async)
        SendReportJob::dispatch($settingId, $setting->organization_id);

        return response()->json([
            'success' => true,
            'message' => 'Report job queued successfully (asynchronous)',
            'setting_id' => $settingId,
            'email' => $setting->email,
            'report_type' => $setting->report_type,
            'organization_id' => $setting->organization_id,
            'note' => 'Job is queued. Check queue worker logs to see execution.',
            'logs' => [
                'Make sure queue worker is running: php artisan queue:work',
                'Check storage/logs/job-debug.log',
                'Check storage/logs/laravel.log',
            ]
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'error' => true,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ], 500);
    }
})->name('test.report.job.queue');

// List all report settings
Route::get('/test-report-settings', function () {
    $orgId = auth()->user()->employee->organization_id ?? null;

    if (!$orgId) {
        return response()->json([
            'error' => 'No organization found for current user'
        ], 404);
    }

    $settings = ReportSetting::where('organization_id', $orgId)
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($setting) {
            return [
                'id' => $setting->id,
                'email' => $setting->email,
                'report_type' => $setting->report_type,
                'frequency' => $setting->frequency,
                'time' => $setting->time,
                'day_of_week' => $setting->day_of_week,
                'active' => $setting->active,
                'last_run_at' => $setting->last_run_at?->format('Y-m-d H:i:s'),
                'next_run_at' => $setting->next_run_at?->format('Y-m-d H:i:s'),
                'test_url' => route('test.report.job', $setting->id),
            ];
        });

    return response()->json([
        'organization_id' => $orgId,
        'settings_count' => $settings->count(),
        'settings' => $settings,
        'instructions' => [
            'Visit /test-report-job/{id} to test a specific report',
            'Visit /test-report-job to test the first active report',
            'Visit /test-report-job-queue/{id} to queue the job (async)',
        ]
    ]);
})->name('test.report.settings');


