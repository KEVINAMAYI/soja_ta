<?php

use App\Http\Controllers\SuperAdmin\Auth\SuperAdminAuth;
use App\Http\Controllers\SuperAdmin\Clients\ClientController;
use App\Http\Controllers\SuperAdmin\Dashboard\DashboardController;
use App\Http\Controllers\SuperAdmin\Devices\DeviceController;
use App\Http\Controllers\SuperAdmin\Impersonation\ImpersonationController;
use App\Http\Controllers\SuperAdmin\Integrations\IntegrationController;
use App\Http\Controllers\SuperAdmin\Logs\LogController;
use App\Http\Controllers\SuperAdmin\QrTokens\QrTokenController;
use App\Http\Controllers\SuperAdmin\Subscriptions\FeatureCategoryController;
use App\Http\Controllers\SuperAdmin\Subscriptions\FeatureController;
use App\Http\Controllers\SuperAdmin\Subscriptions\SubFeatureController;
use App\Http\Controllers\SuperAdmin\Subscriptions\SubscriptionPlanController;
use App\Http\Controllers\SuperAdmin\Users\UserController;
use App\Http\Controllers\SuperAdmin\WorkLocations\WorkLocationController;
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

    // terminate a running impersonation session from the super admin console
    Route::delete('/impersonations/{impersonationSession}', [ImpersonationController::class, 'destroy']);

    Route::prefix('clients')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::post('/', [ClientController::class, 'store']);
        Route::put('/{organization}', [ClientController::class, 'update']);
        Route::post('/{organization}/logo', [ClientController::class, 'uploadLogo']);

        // log in to the client portal as the organization's first admin
        Route::post('/{organization}/impersonate', [ImpersonationController::class, 'store']);

        // route to update client employee defaults
        Route::put('/{organization}/employee-defaults', [ClientController::class, 'setClientEmployeeDefaults']);
        Route::get('/{organization}/departments', [ClientController::class, 'getOrganizationDepartments']);
        Route::post('/{organization}/departments', [ClientController::class, 'createOrganizationDepartment']);
        Route::put('/{organization}/departments/{departmentId}', [ClientController::class, 'updateOrganizationDepartment']);

        // job title management routesgetOrganizationHierarchy
        Route::get('/{organization}/hierarchy', [ClientController::class, 'getOrganizationHierarchy']);
        Route::post('/{organization}/job-title', [ClientController::class, 'storeJobTitle']);
        Route::put('/{organization}/job-title/{jobTitleId}', [ClientController::class, 'updateJobTitle']);

        // client portal integration settings: API keys, ZKBio hardware sync, Active Directory sync
        Route::get('/{organization}/integrations', [IntegrationController::class, 'show']);
        Route::put('/{organization}/integrations/zkbio', [IntegrationController::class, 'updateZkbio']);
        Route::put('/{organization}/integrations/active-directory', [IntegrationController::class, 'updateActiveDirectory']);
        Route::put('/{organization}/integrations/api-docs', [IntegrationController::class, 'updateApiDocs']);
        Route::post('/{organization}/integrations/api-keys/{environment}/generate', [IntegrationController::class, 'generateApiKey']);
        Route::put('/{organization}/integrations/api-keys/{environment}/toggle', [IntegrationController::class, 'toggleApiKey']);
    });

    Route::prefix('work-locations')->group(function () {
        Route::get('/', [WorkLocationController::class, 'index']);
        Route::post('/', [WorkLocationController::class, 'store']);
        Route::put('/{workLocation}', [WorkLocationController::class, 'update']);
    });

    Route::prefix('devices')->group(function () {
        Route::get('/', [DeviceController::class, 'index']);
        Route::post('/', [DeviceController::class, 'store']);
        Route::put('/{device}', [DeviceController::class, 'update']);
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::put('/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    });

    Route::prefix('qr-tokens')->group(function () {
        Route::get('/', [QrTokenController::class, 'index']);
        Route::put('/{employee}/revoke', [QrTokenController::class, 'revoke']);
        Route::put('/{employee}/activate', [QrTokenController::class, 'activate']);
    });

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


