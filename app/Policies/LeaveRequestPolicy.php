<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;

class LeaveRequestPolicy
{
    /**
     * Determine whether the user can view the leave management module.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    /**
     * Determine whether the user can submit a new self-service leave request.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_active && $user->employee !== null;
    }

    /**
     * Determine whether the user has ownership or tenant-admin authority to cancel the leave request.
     */
    public function cancelOwnership(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        if ($user->employee !== null && (int) $user->employee->id === (int) $leaveRequest->employee_id) {
            return true;
        }

        return $user->isAdminOrAbove();
    }

    /**
     * Determine whether the user can cancel a pending or approved leave request.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->cancelOwnership($user, $leaveRequest) && $leaveRequest->canBeCancelled();
    }

    /**
     * Determine whether the user owns the leave request for self-service editing.
     */
    public function updateOwnership(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        return $user->employee !== null && (int) $user->employee->id === (int) $leaveRequest->employee_id;
    }

    /**
     * Determine whether the user can edit/update their leave request.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->updateOwnership($user, $leaveRequest) && $leaveRequest->canBeEdited();
    }

    /**
     * Determine whether the user can stream the private attachment of a leave request.
     */
    public function viewAttachment(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        // 1. Pemilik pengajuan
        if ($user->employee !== null && (int) $user->employee->id === (int) $leaveRequest->employee_id) {
            return true;
        }

        // 2. Admin / Super User / Super Admin di tenant yang sama
        if ($user->isAdminOrAbove()) {
            return true;
        }

        // 3. Manager pada departemen yang sama
        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $reqEmployee = $leaveRequest->relationLoaded('employee')
                ? $leaveRequest->employee
                : Employee::withoutGlobalScopes()->find($leaveRequest->employee_id);

            return $managerDeptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can view the SPK SMART analysis for a leave request.
     * Restricted strictly to Manager (same department) and Admin/Super User/Super Admin (same tenant).
     */
    public function viewSmartAnalysis(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        if ($user->isAdminOrAbove()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $reqEmployee = $leaveRequest->relationLoaded('employee')
                ? $leaveRequest->employee
                : Employee::withoutGlobalScopes()->find($leaveRequest->employee_id);

            return $managerDeptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can update SPK SMART criteria weights for a given department.
     */
    public function manageDepartmentSmartWeights(User $user, Department $department): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $department->company_id === (int) session('active_company_id');
            }

            return true;
        }

        if ($user->company_id === null || (int) $user->company_id !== (int) $department->company_id) {
            return false;
        }

        if ($user->isAdminOrAbove()) {
            return true;
        }

        if ($user->isManager()) {
            return $user->employee !== null
                && (int) $user->employee->department_id === (int) $department->id;
        }

        return false;
    }

    /**
     * Determine whether the user can update leave type expiry and quota settings (Admin+ only).
     */
    public function manageLeaveTypeSettings(User $user, ?LeaveType $leaveType = null): bool
    {
        if (! $user->is_active || ! $user->isAdminOrAbove()) {
            return false;
        }

        if ($leaveType !== null && $leaveType->company_id !== null && ! $user->isSuperAdmin()) {
            return $user->company_id !== null && (int) $user->company_id === (int) $leaveType->company_id;
        }

        return true;
    }

    /**
     * Determine whether the user can execute Tier 1 (Manager level) approval on a leave request.
     */
    public function approveTier1(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        // Prevent manager self-approval
        if (
            ! $user->isAdminOrAbove()
            && $user->employee !== null
            && (int) $user->employee->id === (int) $leaveRequest->employee_id
        ) {
            return false;
        }

        if ($user->isAdminOrAbove()) {
            return true;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;
            $reqEmployee = $leaveRequest->relationLoaded('employee')
                ? $leaveRequest->employee
                : Employee::withoutGlobalScopes()->find($leaveRequest->employee_id);

            return $managerDeptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can execute Tier 2 (Final HR/Admin level) approval on a leave request.
     */
    public function approveTier2(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        return $user->isAdminOrAbove();
    }

    /**
     * Determine whether the user can reject a leave request.
     */
    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        if ($leaveRequest->status === LeaveRequest::STATUS_PENDING_HR) {
            return $this->approveTier2($user, $leaveRequest);
        }

        return $this->approveTier1($user, $leaveRequest);
    }

    /**
     * Determine whether the user can view the leave ledger history of a specific employee.
     */
    public function viewLedger(User $user, Employee $employee): bool
    {
        if (! $user->is_active || ! $this->isSameEmployeeTenant($user, $employee)) {
            return false;
        }

        // 1. Own ledger
        if ($user->employee !== null && (int) $user->employee->id === (int) $employee->id) {
            return true;
        }

        // 2. Admin / Super User / Super Admin in same tenant
        if ($user->isAdminOrAbove()) {
            return true;
        }

        // 3. Manager of the same department
        if ($user->isManager()) {
            return $user->employee !== null
                && $user->employee->department_id !== null
                && (int) $user->employee->department_id === (int) $employee->department_id;
        }

        return false;
    }

    /**
     * Determine whether the user can execute manual HR ledger adjustments (Admin+ only).
     */
    public function adjustLedger(User $user): bool
    {
        return (bool) $user->is_active && $user->isAdminOrAbove();
    }

    /**
     * Verify strict tenant isolation for the leave request.
     */
    private function isSameTenant(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $leaveRequest->company_id === (int) session('active_company_id');
            }

            return true;
        }

        return $user->company_id !== null
            && (int) $user->company_id === (int) $leaveRequest->company_id;
    }

    /**
     * Verify strict tenant isolation for an employee record.
     */
    private function isSameEmployeeTenant(User $user, Employee $employee): bool
    {
        if ($user->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return (int) $employee->company_id === (int) session('active_company_id');
            }

            return true;
        }

        return $user->company_id !== null
            && (int) $user->company_id === (int) $employee->company_id;
    }
}
