<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RosterController;
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
    Route::get('/staff/{employee}/edit', [StaffProvisioningController::class, 'edit'])->name('staff.edit');
    Route::put('/staff/{employee}', [StaffProvisioningController::class, 'update'])->name('staff.update');
    Route::patch('/staff/{employee}', [StaffProvisioningController::class, 'update']);
    Route::delete('/staff/{employee}', [StaffProvisioningController::class, 'destroy'])->name('staff.destroy');

    Route::get('/roster', [RosterController::class, 'index'])->name('roster.index');
    Route::post('/roster/assign', [RosterController::class, 'assign'])->name('roster.assign');
    Route::patch('/roster/{workSchedule}/resize', [RosterController::class, 'resize'])->name('roster.resize');
    Route::patch('/roster/{workSchedule}/restore', [RosterController::class, 'restore'])->name('roster.restore');
    Route::delete('/roster/{workSchedule}', [RosterController::class, 'destroy'])->name('roster.destroy');
    Route::post('/roster/publish', [RosterController::class, 'publish'])->name('roster.publish');
    Route::post('/roster/unpublish', [RosterController::class, 'unpublish'])->name('roster.unpublish');
    Route::post('/roster/discard', [RosterController::class, 'discardDrafts'])->name('roster.discard');
    Route::post('/roster/shifts', [RosterController::class, 'storeShift'])->name('roster.shifts.store');
    Route::put('/roster/shifts/{shift}', [RosterController::class, 'updateShift'])->name('roster.shifts.update');

    Route::get('/departments', [DashboardController::class, 'departments'])->name('departments.index');
    Route::get('/company/settings', [DashboardController::class, 'companySettings'])->name('company.settings');
    Route::post('/company/switch', [DashboardController::class, 'switchCompany'])->name('company.switch');
});

require __DIR__.'/auth.php';
