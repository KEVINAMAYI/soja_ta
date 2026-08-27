<?php

use App\Http\Controllers\APIs\AttendanceController;
use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\DeviceController;
use App\Http\Controllers\APIs\ElevateHRApiController;
use App\Http\Controllers\APIs\LeaveController;
use App\Http\Controllers\APIs\OrganizationController;
use Illuminate\Support\Facades\Route;


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
        Route::get('/{id}/cancel', [LeaveController::class, 'cancel']);
        Route::get('/{id}', [LeaveController::class, 'show']);
    });

    Route::post('/devices/verify', [DeviceController::class, 'verify']);

});

// Routes authenticated via a database-stored API key (X-API-KEY header) instead of user sessions/tokens.
Route::middleware(['api.key'])->prefix('v1')->group(function () {
    Route::get('/ping', [ElevateHRApiController::class, 'ping']);
});

Route::fallback(function () {
    return response()->json(['error' => 'This Route was Not Found'], 404);
});
