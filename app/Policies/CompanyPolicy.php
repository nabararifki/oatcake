<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active && $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Company $company): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isSuperAdmin()
            || (($user->isSuperUser() || $user->isAdmin())
                && $user->company_id !== null
                && (int) $user->company_id === (int) $company->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_active && $user->isSuperAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Company $company): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isSuperAdmin()
            || (($user->isSuperUser() || $user->isAdmin())
                && $user->company_id !== null
                && (int) $user->company_id === (int) $company->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Company $company): bool
    {
        return (bool) $user->is_active && $user->isSuperAdmin();
    }
}
