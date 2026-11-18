<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceVerificationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
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

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/pwa/offline-snapshot', [PwaController::class, 'offlineSnapshot'])->name('pwa.snapshot');

    Route::get('/notifications', [PushSubscriptionController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('notifications.subscriptions.store');
    Route::delete('/notifications/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('notifications.subscriptions.destroy');
    Route::post('/notifications/test-push', [PushSubscriptionController::class, 'testPush'])->name('notifications.testPush');
    Route::post('/notifications/read-all', [PushSubscriptionController::class, 'markAllRead'])->name('notifications.markAllRead');
    Route::post('/notifications/{userNotification}/read', [PushSubscriptionController::class, 'markRead'])->name('notifications.markRead');

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

    Route::get('/leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::get('/leave/calculate-days', [LeaveRequestController::class, 'calculateDays'])->name('leave.calculateDays');
    Route::put('/leave/smart-weights', [LeaveRequestController::class, 'updateSmartWeights'])->name('leave.smartWeights.update');
    Route::post('/leave/smart-debug', [LeaveRequestController::class, 'smartDebug'])->name('leave.smartDebug');
    Route::put('/leave/types/{leaveType}/settings', [LeaveRequestController::class, 'updateLeaveTypeSettings'])->name('leave.types.updateSettings');
    Route::post('/leave/ledger/adjust', [LeaveRequestController::class, 'adjustLedger'])->name('leave.ledger.adjust');
    Route::get('/leave/ledger/{employee}', [LeaveRequestController::class, 'ledgerHistory'])->name('leave.ledger.history');
    Route::post('/leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::put('/leave/{leaveRequest}', [LeaveRequestController::class, 'update'])->name('leave.update');
    Route::post('/leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');
    Route::post('/leave/{leaveRequest}/approve-tier1', [LeaveRequestController::class, 'approveTier1'])->name('leave.approveTier1');
    Route::post('/leave/{leaveRequest}/approve-tier2', [LeaveRequestController::class, 'approveTier2'])->name('leave.approveTier2');
    Route::post('/leave/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('leave.reject');
    Route::get('/leave/{leaveRequest}/smart-analysis', [LeaveRequestController::class, 'smartAnalysis'])->name('leave.smartAnalysis');
    Route::get('/leave/{leaveRequest}/attachment', [LeaveRequestController::class, 'streamAttachment'])->name('leave.attachment');

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

    Route::post('/settings/holidays', [CompanySettingsController::class, 'storeHoliday'])->name('settings.holidays.store');
    Route::put('/settings/holidays/{publicHoliday}', [CompanySettingsController::class, 'updateHoliday'])->name('settings.holidays.update');
    Route::patch('/settings/holidays/{publicHoliday}', [CompanySettingsController::class, 'updateHoliday']);
    Route::delete('/settings/holidays/{publicHoliday}', [CompanySettingsController::class, 'destroyHoliday'])->name('settings.holidays.destroy');

    Route::get('/company/settings', [CompanySettingsController::class, 'edit'])->name('company.settings');
    Route::put('/company/settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');
    Route::patch('/company/settings', [CompanySettingsController::class, 'update']);

    Route::post('/admin/companies', [CompanySettingsController::class, 'store'])->name('admin.companies.store');
    Route::post('/company/switch', [DashboardController::class, 'switchCompany'])->name('company.switch');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
});

require __DIR__.'/auth.php';
