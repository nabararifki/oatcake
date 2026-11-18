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
        return $user->isAdminOrAbove();
    }

    /**
     * Only Admin+ users within the same tenant company (or Super Admin globally) may inspect an audit log entry.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        if (! $user->isAdminOrAbove()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return (int) $user->company_id === (int) $auditLog->company_id;
    }
}
