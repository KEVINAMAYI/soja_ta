<?php

use App\Http\Controllers\APIs\AttendanceController;
use App\Http\Controllers\APIs\AuthController;
use Illuminate\Support\Facades\Route;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::group(["middleware" => ['auth:sanctum']], function () {

    Route::post('/qr_checkin', [AttendanceController::class, 'QRCheckin']);
    Route::post('/qr_checkout', [AttendanceController::class, 'QRCheckout']);

});

Route::fallback(function () {
    return response()->json(['error' => 'This Route was Not Found'], 404);
});
