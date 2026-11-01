<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffProvisioningController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::patch('/preferences', PreferenceController::class)->middleware('auth')->name('preferences.update');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/staff', [StaffProvisioningController::class, 'index'])->name('staff.index');
    Route::get('/staff/create', [StaffProvisioningController::class, 'create'])->name('staff.create');
    Route::post('/staff', [StaffProvisioningController::class, 'store'])->name('staff.store');

    Route::get('/departments', [DashboardController::class, 'departments'])->name('departments.index');
    Route::get('/company/settings', [DashboardController::class, 'companySettings'])->name('company.settings');
    Route::post('/company/switch', [DashboardController::class, 'switchCompany'])->name('company.switch');
});

require __DIR__.'/auth.php';
