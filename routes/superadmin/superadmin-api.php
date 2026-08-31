<?php

use App\Http\Controllers\SuperAdmin\Auth\SuperAdminAuth;
use App\Http\Controllers\SuperAdmin\Dashboard\DashboardController;
use App\Http\Controllers\SuperAdmin\Logs\LogController;
use App\Http\Controllers\SuperAdmin\Subscriptions\FeatureCategoryController;
use App\Http\Controllers\SuperAdmin\Subscriptions\FeatureController;
use App\Http\Controllers\SuperAdmin\Subscriptions\SubFeatureController;
use App\Http\Controllers\SuperAdmin\Subscriptions\SubscriptionPlanController;
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

    Route::prefix('subscriptions')->group(function () {
        Route::get('/feature-categories', [FeatureCategoryController::class, 'index']);
        Route::post('/feature-categories', [FeatureCategoryController::class, 'store']);
        Route::put('/feature-categories/{featureCategory}', [FeatureCategoryController::class, 'update']);
        Route::delete('/feature-categories/{featureCategory}', [FeatureCategoryController::class, 'destroy']);

        Route::post('/feature-categories/{featureCategory}/features', [FeatureController::class, 'store']);
        Route::put('/features/{feature}', [FeatureController::class, 'update']);
        Route::delete('/features/{feature}', [FeatureController::class, 'destroy']);

        Route::post('/features/{feature}/sub-features', [SubFeatureController::class, 'store']);
        Route::put('/sub-features/{subFeature}', [SubFeatureController::class, 'update']);
        Route::delete('/sub-features/{subFeature}', [SubFeatureController::class, 'destroy']);

        Route::get('/plans', [SubscriptionPlanController::class, 'index']);
        Route::post('/plans', [SubscriptionPlanController::class, 'store']);
        Route::get('/plans/{plan}', [SubscriptionPlanController::class, 'show']);
        Route::put('/plans/{plan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/plans/{plan}', [SubscriptionPlanController::class, 'destroy']);
        Route::put('/plans/{plan}/features', [SubscriptionPlanController::class, 'updateFeatures']);
        Route::post('/plans/{plan}/assign', [SubscriptionPlanController::class, 'assign']);
    });
});


