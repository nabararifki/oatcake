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
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->company_id !== null
            && in_array($user->role, ['manager', 'admin', 'super_user'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Employee $employee): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->matchesSuperAdminTenant($employee->company_id);
        }

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
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

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
        if (! $user->is_active || ! $this->canManageTargetRank($user, $employee)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->matchesSuperAdminTenant($employee->company_id);
        }

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
        if (! $user->is_active || ! $this->canManageTargetRank($user, $employee)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->matchesSuperAdminTenant($employee->company_id);
        }

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
     * Enforce strict role hierarchy: actor must outrank the target user (or match rank for self).
     */
    private function canManageTargetRank(User $actor, Employee $target): bool
    {
        $isSelf = $target->user_id !== null && (int) $actor->id === (int) $target->user_id;
        $targetUser = $target->relationLoaded('user') ? $target->user : $target->user()->first();
        $targetRank = User::rankForRole($targetUser?->role ?? 'user');
        $actorRank = $actor->roleRank();

        if ($isSelf) {
            return $actorRank >= $targetRank;
        }

        return $actorRank > $targetRank;
    }

    /**
     * Check Super Admin tenant context when an active company switch is present.
     */
    private function matchesSuperAdminTenant(?int $targetCompanyId): bool
    {
        if (session()->has('active_company_id') && session('active_company_id') !== null) {
            return (int) $targetCompanyId === (int) session('active_company_id');
        }

        return true;
    }
}
