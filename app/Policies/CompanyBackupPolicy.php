<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyBackupPolicy
{
    /**
     * Determine whether the user can manage backups (list, create, download, delete) for a company.
     * Exclusively restricted to active super_admin (global) or super_user (own company only).
     */
    public function manage(User $user, Company $company): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'super_user') {
            return $user->company_id !== null && (int) $user->company_id === (int) $company->id;
        }

        return false;
    }

    /**
     * Determine whether the user can list backup archives for a company.
     */
    public function viewAny(User $user, Company $company): bool
    {
        return $this->manage($user, $company);
    }

    /**
     * Determine whether the user can create a backup archive for a company.
     */
    public function create(User $user, Company $company): bool
    {
        return $this->manage($user, $company);
    }

    /**
     * Determine whether the user can download a backup archive for a company.
     */
    public function download(User $user, Company $company): bool
    {
        return $this->manage($user, $company);
    }

    /**
     * Determine whether the user can delete a backup archive for a company.
     */
    public function delete(User $user, Company $company): bool
    {
        return $this->manage($user, $company);
    }

    /**
     * Determine whether the user can restore a company backup bundle.
     */
    public function restore(User $user, ?Company $company = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'super_user') {
            return $company !== null
                && $user->company_id !== null
                && (int) $user->company_id === (int) $company->id;
        }

        return false;
    }
}
