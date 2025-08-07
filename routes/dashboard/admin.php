<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Group all admin routes under the 'admin' prefix
Route::middleware(['auth'])->prefix('admin')->group(function () {

    // Route to manage employees
    Volt::route('employees', 'admin.employees.index')->name('employees.index');
    Volt::route('employees/view', 'admin.employees.view')->name('employees.view');

    Volt::route('settings', 'admin.settings.index')->name('settings.index');

    // Route to manage organizations
    Volt::route('organizations', 'admin.organizations.index')->name('organizations.index');

    // Route to manage organizations
    Volt::route('employee-types', 'admin.employee-types.index')->name('employee-types.index');

    //Routes to manage Attendance
    Volt::route('attendance/daily', 'admin.attendance.daily')->name('attendance.daily');
    Volt::route('attendance/monthly', 'admin.attendance.monthly')->name('attendance.monthly');

    //Routes to manage Attendance
    Volt::route('overtime', 'admin.overtime.index')->name('overtime.index');

});

