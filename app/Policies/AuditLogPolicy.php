<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    /**
     * Only Admin, Super User, and Super Admin may access the audit trail console.
     * Manager and regular Staff roles are strictly forbidden (HTTP 403).
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active && $user->isAdminOrAbove();
    }

    /**
     * Only Admin+ users within the same tenant company (or Super Admin globally) may inspect an audit log entry.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        if (! $user->is_active || ! $user->isAdminOrAbove()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $auditLog->company_id === (int) session('active_company_id');
            }

            return true;
        }

        return $user->company_id !== null
            && (int) $user->company_id === (int) $auditLog->company_id;
    }
}
