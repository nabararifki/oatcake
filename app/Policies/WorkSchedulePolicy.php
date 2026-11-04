<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;

class WorkSchedulePolicy
{
    /**
     * Determine whether the user can view any work schedules.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Determine whether the user can view a specific work schedule.
     */
    public function view(User $user, WorkSchedule $workSchedule): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $workSchedule->company_id === (int) session('active_company_id');
            }

            return true;
        }

        if ($user->company_id === null || (int) $user->company_id !== (int) $workSchedule->company_id) {
            return false;
        }

        if ($user->isUser()) {
            return $workSchedule->status === 'published';
        }

        return true;
    }

    /**
     * Determine whether the user can mutate (update, resize, split, delete) a work schedule.
     */
    public function update(User $user, WorkSchedule $workSchedule): bool
    {
        if (! $user->is_active || ! in_array($user->role, ['super_admin', 'super_user', 'admin', 'manager'], true)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $workSchedule->company_id === (int) session('active_company_id');
            }

            return true;
        }

        if ($user->company_id === null || (int) $user->company_id !== (int) $workSchedule->company_id) {
            return false;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $employee = $workSchedule->relationLoaded('employee')
                ? $workSchedule->employee
                : Employee::withoutGlobalScopes()->find($workSchedule->employee_id);

            return $managerDeptId !== null
                && $employee !== null
                && (int) $employee->department_id === (int) $managerDeptId;
        }

        return true;
    }

    /**
     * Determine whether the user can split a work schedule into two sessions.
     */
    public function split(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->update($user, $workSchedule);
    }

    /**
     * Determine whether the user can delete a work schedule.
     */
    public function delete(User $user, WorkSchedule $workSchedule): bool
    {
        return $this->update($user, $workSchedule);
    }
}
