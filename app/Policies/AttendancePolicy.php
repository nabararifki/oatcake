<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;

class AttendancePolicy
{
    /**
     * Determine whether the user can access the personal attendance module.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Determine whether the user can access the managerial attendance verification console.
     */
    public function viewVerifications(User $user): bool
    {
        return (bool) $user->is_active && $user->isManagerOrAbove();
    }

    /**
     * Determine whether the user can view a specific attendance record.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        return $this->viewProof($user, $attendance);
    }

    /**
     * Determine whether the user can stream the private selfie proof photo of an attendance record.
     */
    public function viewProof(User $user, Attendance $attendance): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $attendance->company_id === (int) session('active_company_id');
            }

            return true;
        }

        if ($user->company_id === null || (int) $user->company_id !== (int) $attendance->company_id) {
            return false;
        }

        // Pemilik presensi dapat melihat bukti swafotonya sendiri
        if ($user->employee !== null && (int) $user->employee->id === (int) $attendance->employee_id) {
            return true;
        }

        if ($user->isSuperUser() || $user->isAdmin()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $employee = $attendance->relationLoaded('employee')
                ? $attendance->employee
                : Employee::withoutGlobalScopes()->find($attendance->employee_id);

            return $managerDeptId !== null
                && $employee !== null
                && (int) $employee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can verify (approve or reject) an outside duty attendance record.
     */
    public function verify(User $user, Attendance $attendance): bool
    {
        if (! $user->is_active || ! $user->isManagerOrAbove()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $attendance->company_id === (int) session('active_company_id');
            }

            return true;
        }

        if ($user->company_id === null || (int) $user->company_id !== (int) $attendance->company_id) {
            return false;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $employee = $attendance->relationLoaded('employee')
                ? $attendance->employee
                : Employee::withoutGlobalScopes()->find($attendance->employee_id);

            return $managerDeptId !== null
                && $employee !== null
                && (int) $employee->department_id === (int) $managerDeptId;
        }

        return true;
    }

    /**
     * Determine whether the user can correct (update) an attendance record.
     */
    public function update(User $user, Attendance $attendance): bool
    {
        return $user->isAdminOrAbove()
            && (int) $attendance->company_id === (int) $user->company_id;
    }

    /**
     * Determine whether the user can delete an attendance record.
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        return $user->isAdminOrAbove()
            && (int) $attendance->company_id === (int) $user->company_id;
    }

    /**
     * Determine whether the user can perform clock-in/clock-out for the target employee.
     * Strict self-only rule: users may only clock-in/clock-out for themselves.
     */
    public function clockForEmployee(User $user, Employee $targetEmployee): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $selfEmployee = $user->employee;
        if ($selfEmployee === null) {
            return false;
        }

        return (int) $selfEmployee->id === (int) $targetEmployee->id
            && (int) $selfEmployee->company_id === (int) $targetEmployee->company_id;
    }

    /**
     * Determine whether the user can clock-out an existing attendance record.
     */
    public function clockOut(User $user, Attendance $attendance): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $selfEmployee = $user->employee;
        if ($selfEmployee === null) {
            return false;
        }

        return (int) $selfEmployee->id === (int) $attendance->employee_id
            && (int) $selfEmployee->company_id === (int) $attendance->company_id;
    }
}
