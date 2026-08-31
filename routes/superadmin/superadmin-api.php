<?php

use App\Http\Controllers\SuperAdmin\Auth\SuperAdminAuth;
use App\Http\Controllers\SuperAdmin\Dashboard\DashboardController;
use App\Http\Controllers\SuperAdmin\Logs\LogController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::prefix('super-man')->group(function () {
    Route::post('/login', [SuperAdminAuth::class, 'login']);
});

Route::prefix('super-man')->middleware([
    'auth:sanctum',
    RoleMiddleware::class . ':super-admin',
])->group(function () {
    Route::get('/user-activity-logs/filter', [LogController::class, 'filterUserActivityLogs']);
    Route::get('/audit-logs/filter', [LogController::class, 'filterAuditLogs']);
    Route::get('/dashboard/analytics', [DashboardController::class, 'analytics']);
});

