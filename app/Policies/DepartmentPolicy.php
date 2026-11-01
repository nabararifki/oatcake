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
        return $user->company_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Department $department): bool
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $department->company_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->company_id !== null
            && ($user->isAdmin() || $user->isSuperUser());
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Department $department): bool
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $department->company_id
            && ($user->isAdmin() || $user->isSuperUser());
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Department $department): bool
    {
        return $user->company_id !== null
            && (int) $user->company_id === (int) $department->company_id
            && ($user->isAdmin() || $user->isSuperUser());
    }
}
