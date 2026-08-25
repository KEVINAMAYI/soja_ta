<?php

use App\Http\Controllers\APIs\AttendanceController;
use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\CheckInApprovalController;
use App\Http\Controllers\APIs\DeviceController;
use App\Http\Controllers\APIs\LeaveController;
use App\Http\Controllers\APIs\OrganizationController;
use Illuminate\Support\Facades\Route;



require __DIR__ . '/superadmin/superadmin-api.php';

// Public authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/login-otp', [AuthController::class, 'loginViaOtp']);
Route::post('/login-face', [AuthController::class, 'loginViaFaceId']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {

    // Enroll user & employee under current organization and logout
    Route::post('/enroll', [AuthController::class, 'enroll']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('attendance')->group(function () {
        Route::post('/checkin', [AttendanceController::class, 'checkIn']);
        Route::post('/checkout', [AttendanceController::class, 'checkOut']);
        Route::get('/history', [AttendanceController::class, 'attendanceHistory']);
    });

    Route::prefix('organization')->group(function () {
        Route::get('/departments', [OrganizationController::class, 'departments']);
        Route::get('/employees', [OrganizationController::class, 'employees']);
        Route::get('/employee-by-phone', [OrganizationController::class, 'employeeByPhone']);
        Route::get('/employee-working-hours', [OrganizationController::class, 'workingHours']);
        Route::get('/work-locations', [OrganizationController::class, 'workLocations']);
        Route::post('/assign-work-location', [OrganizationController::class, 'assignWorkLocation']);
        Route::post('/assign-qr-code', [AuthController::class, 'assignToken']);
        Route::post('/assign-face-id', [AuthController::class, 'assignFaceId']);
        Route::get('/roles', [OrganizationController::class, 'getAllRoles']);
    });

    Route::prefix('leaves')->group(function () {
        Route::post('/apply', [LeaveController::class, 'apply']);
        Route::get('/my-leaves', [LeaveController::class, 'myLeaves']);
        Route::get('/types', [LeaveController::class, 'leaveTypes']);
        Route::get('/balances', [LeaveController::class, 'balances']);
        Route::get('/{id}/cancel', [LeaveController::class, 'cancel']);
        Route::post('/{id}/approve', [LeaveController::class, 'approve']);
        Route::post('/{id}/reject', [LeaveController::class, 'reject']);
        Route::get('/{id}/approval-progress', [LeaveController::class, 'approvalProgress']);
        Route::get('/{id}', [LeaveController::class, 'show']);
    });

    Route::post('/devices/verify', [DeviceController::class, 'verify']);

    // REPLACE WITH:
    Route::prefix('checkin-requests')->group(function () {
        Route::get('/my-requests', [CheckInApprovalController::class, 'myRequests']);
        Route::get('/', [CheckInApprovalController::class, 'index']);
        Route::get('/{id}', [CheckInApprovalController::class, 'show']);
        Route::post('/{id}/action', [CheckInApprovalController::class, 'action']);
    });

});

Route::fallback(function () {
    return response()->json(['error' => 'This Route was Not Found'], 404);
});
