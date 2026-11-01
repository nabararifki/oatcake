<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->company_id !== null
            && in_array($user->role, ['manager', 'admin', 'super_user'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Employee $employee): bool
    {
        if ($user->company_id === null || (int) $user->company_id !== (int) $employee->company_id) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperUser()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDepartmentId = $user->employee?->department_id;

            return $managerDepartmentId !== null
                && (int) $employee->department_id === (int) $managerDepartmentId;
        }

        if ($user->isUser()) {
            return (int) $employee->user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?int $departmentId = null): bool
    {
        if ($user->company_id === null) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperUser()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDepartmentId = $user->employee?->department_id;

            if ($managerDepartmentId === null) {
                return false;
            }

            return $departmentId === null || (int) $departmentId === (int) $managerDepartmentId;
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Employee $employee): bool
    {
        if ($user->company_id === null || (int) $user->company_id !== (int) $employee->company_id) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperUser()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDepartmentId = $user->employee?->department_id;

            return $managerDepartmentId !== null
                && (int) $employee->department_id === (int) $managerDepartmentId;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $employee->company_id
            && ($user->isAdmin() || $user->isSuperUser());
    }
}
