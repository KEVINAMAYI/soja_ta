<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;


Volt::route('/', 'admin.dashboard.index')->middleware(['auth', 'verified']);

// Group all admin routes under the 'admin' prefix
Route::middleware(['auth'])->prefix('admin')->group(function () {

    Volt::route('dashboard', 'admin.dashboard.index')->name('dashboard');

    // Route to manage employees
    Volt::route('employees/view/{employeeId}', 'admin.employees.view')->name('employees.view');
    Volt::route('employees', 'admin.employees.index')->name('employees.index');

    //Routes to manage Settings
    Volt::route('system-settings', 'admin.system-settings.index')->name('system-settings.index');
    Volt::route('account-settings', 'admin.account-settings.index')->name('account-settings.index');

    // Route to manage Organizations
    Volt::route('organizations', 'admin.organizations.index')->name('organizations.index');

    // Route to manage Shifts
    Volt::route('shifts', 'admin.shifts.index')->name('shifts.index');
    Volt::route('shifts/view/{shift}', 'admin.shifts.view')->name('shifts.view');

    // Route to manage Employee Types
    Volt::route('employee-types', 'admin.employee-types.index')->name('employee-types.index');

    //Routes to manage Attendance
    Volt::route('timesheets', 'admin.attendance.index')->name('timesheets.index');
    Volt::route('attendance', 'admin.attendance.index')->name('attendance.index');


    //Routes to manage Overtime
    Volt::route('overtime', 'admin.overtime.index')->name('overtime.index');

    //Routes to manage Reports
    Volt::route('reports/employees', 'admin.reports.employees')->name('reports.employees');
    Volt::route('reports/departments', 'admin.reports.departments')->name('reports.departments');
    Volt::route('reports/organization', 'admin.reports.organization')->name('analytics');

    //Work Locations
    Volt::route('work-locations/view/{workLocation}', 'admin.location-assignment.view')->name('work-location.view');

    //Leaves Locations
    Volt::route('leaves', 'admin.leaves.index')->name('leaves.index');
    Volt::route('leaves/create', 'admin.leaves.create')->name('leaves.create');

    //shifts
    Volt::route('shifts/create', 'admin.shifts.create')->name('shifts.create');


});

