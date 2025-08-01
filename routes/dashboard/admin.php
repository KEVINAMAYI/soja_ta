<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;



Volt::route('/', 'auth.login')
    ->name('home');


Volt::route('login', 'auth.login')
    ->name('login');

// Group all admin routes under the 'admin' prefix
Route::middleware(['auth'])->prefix('admin')->group(function () {

    // Route to manage employees
    Volt::route('employees', 'admin.employees.index')->name('employees.index');

    // Route to manage organizations
    Volt::route('organizations', 'admin.organizations.index')->name('organizations.index');

    // Route to manage organizations
    Volt::route('employee-types', 'admin.employee-types.index')->name('employee-types.index');

});

