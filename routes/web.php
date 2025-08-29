<?php

use App\Http\Controllers\PDFExports\EmployeeExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;


Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile')->name('user.settings');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
});


require __DIR__ . '/auth.php';
require __DIR__ . '/dashboard/admin.php';


// This route will handle the PDF download
Route::get('/employees/export/pdf', [EmployeeExportController::class, 'exportPdf'])->name('employees.export.pdf');
