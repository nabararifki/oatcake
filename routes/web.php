<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceVerificationController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
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
    Route::get('/dashboard/today-schedules', [DashboardController::class, 'todaySchedules'])->name('dashboard.today-schedules');

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
    Route::post('/roster/{workSchedule}/split', [RosterController::class, 'split'])->name('roster.split');
    Route::patch('/roster/{workSchedule}/resize', [RosterController::class, 'resize'])->name('roster.resize');
    Route::patch('/roster/{workSchedule}/restore', [RosterController::class, 'restore'])->name('roster.restore');
    Route::delete('/roster/{workSchedule}', [RosterController::class, 'destroy'])->name('roster.destroy');
    Route::post('/roster/publish', [RosterController::class, 'publish'])->name('roster.publish');
    Route::post('/roster/unpublish', [RosterController::class, 'unpublish'])->name('roster.unpublish');
    Route::post('/roster/discard', [RosterController::class, 'discardDrafts'])->name('roster.discard');
    Route::post('/roster/shifts', [RosterController::class, 'storeShift'])->name('roster.shifts.store');
    Route::put('/roster/shifts/{shift}', [RosterController::class, 'updateShift'])->name('roster.shifts.update');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])->name('attendance.clockIn');
    Route::post('/attendance/outside-clock-in', [AttendanceController::class, 'clockInOutside'])->name('attendance.clockInOutside');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])->name('attendance.clockOut');
    Route::get('/attendance/verifications', [AttendanceVerificationController::class, 'index'])->name('attendance.verifications.index');
    Route::post('/attendance/verifications/{attendance}/approve', [AttendanceVerificationController::class, 'approve'])->name('attendance.verifications.approve');
    Route::post('/attendance/verifications/{attendance}/reject', [AttendanceVerificationController::class, 'reject'])->name('attendance.verifications.reject');
    Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');
    Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    Route::get('/attendance/{attendance}/proof', [AttendanceVerificationController::class, 'streamProofPhoto'])->name('attendance.proof');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company');
    Route::get('/settings/company/edit', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
    Route::put('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
    Route::patch('/settings/company', [CompanySettingsController::class, 'update']);
    Route::post('/settings/company', [CompanySettingsController::class, 'update']);
    Route::put('/settings/company/{company}', [CompanySettingsController::class, 'update'])->name('settings.company.update.tenant');
    Route::patch('/settings/company/{company}', [CompanySettingsController::class, 'update']);

    Route::get('/company/settings', [CompanySettingsController::class, 'edit'])->name('company.settings');
    Route::put('/company/settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');
    Route::patch('/company/settings', [CompanySettingsController::class, 'update']);

    Route::post('/admin/companies', [CompanySettingsController::class, 'store'])->name('admin.companies.store');
    Route::post('/company/switch', [DashboardController::class, 'switchCompany'])->name('company.switch');
});

require __DIR__.'/auth.php';
