<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Group all admin routes under the 'admin' prefix
Route::middleware(['auth'])->prefix('admin')->group(function () {

    // Route to manage employees
    Volt::route('employees', 'admin.employees.index')->name('employees.index');
    Volt::route('employees/view', 'admin.employees.view')->name('employees.view');

    Volt::route('system-settings', 'admin.system-settings.index')->name('system-settings.index');
    Volt::route('account-settings', 'admin.account-settings.index')->name('account-settings.index');

    // Route to manage organizations
    Volt::route('organizations', 'admin.organizations.index')->name('organizations.index');

    // Route to manage organizations
    Volt::route('employee-types', 'admin.employee-types.index')->name('employee-types.index');

    //Routes to manage Attendance
    Volt::route('attendance', 'admin.attendance.index')->name('attendance.index');

    //Routes to manage Attendance
    Volt::route('overtime', 'admin.overtime.index')->name('overtime.index');

    //Routes to manage Attendance
    Volt::route('reports', 'admin.reports.index')->name('reports.index');

});

