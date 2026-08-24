<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::middleware([
	'auth',
	'verified',
	RoleMiddleware::class . ':super-admin',
])->prefix('superadmin')->group(function () {
	Volt::route('platform-admin', 'platform-admin.index')->name('platform-admin.index');
});
