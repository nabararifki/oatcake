<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($user->company_id !== null && $user->isManagerOrAbove());
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Department $department): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->matchesSuperAdminTenant($department->company_id);
        }

        return $user->company_id !== null
            && (int) $user->company_id === (int) $department->company_id
            && $user->isManagerOrAbove();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($user->company_id !== null
                && ($user->isAdmin() || $user->isSuperUser()));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Department $department): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->matchesSuperAdminTenant($department->company_id);
        }

        return $user->company_id !== null
            && (int) $user->company_id === (int) $department->company_id
            && ($user->isAdmin() || $user->isSuperUser());
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Department $department): bool
    {
        return $this->update($user, $department);
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
