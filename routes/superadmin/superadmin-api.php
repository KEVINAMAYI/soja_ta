<?php

use App\Http\Controllers\SuperAdmin\Auth\SuperAdminAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('super-man')->group(function () {
    Route::post('/login', [SuperAdminAuth::class, 'login']);
});