<?php

use App\Http\Controllers\APIs\AttendanceController;
use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\APIs\OrganizationController;
use Illuminate\Support\Facades\Route;


// Public authentication routes
Route::post('/login', [AuthController::class, 'login']);

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
    });


});

Route::fallback(function () {
    return response()->json(['error' => 'This Route was Not Found'], 404);
});
