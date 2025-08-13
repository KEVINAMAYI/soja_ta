<?php

use App\Http\Controllers\APIs\AttendanceController;
use App\Http\Controllers\APIs\AuthController;
use Illuminate\Support\Facades\Route;


// Public authentication routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {

    // Enroll user & employee under current organization
    Route::post('/enroll', [AuthController::class, 'enroll']);

    Route::prefix('attendance')->group(function () {
        Route::post('/checkin', [AttendanceController::class, 'checkIn']);
        Route::post('/checkout', [AttendanceController::class, 'checkOut']);
        Route::get('/history', [AttendanceController::class, 'attendanceHistory']);
        Route::get('/history/{employeeId}', [AttendanceController::class, 'attendanceHistory']);
    });
});

Route::fallback(function () {
    return response()->json(['error' => 'This Route was Not Found'], 404);
});
