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
        return $this->canBeCancelled($user, $leaveRequest);
    }

    /**
     * Determine whether the user can cancel the leave request (blocked if start_date <= today).
     */
    public function canBeCancelled(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($leaveRequest->hasStartedOrPassed()) {
            return false;
        }

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
        return $this->canBeEdited($user, $leaveRequest);
    }

    /**
     * Determine whether the user can edit/update the leave request (blocked if start_date <= today).
     */
    public function canBeEdited(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($leaveRequest->hasStartedOrPassed()) {
            return false;
        }

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

        $reqEmployee = $this->resolveRequestEmployee($leaveRequest);

        // 3. Direct manager of the applicant (e.g., GM overseeing an HOD in another department)
        if ($this->isDirectManagerOf($user, $reqEmployee)) {
            return true;
        }

        // 4. Manager or Admin Staff pada departemen yang sama
        if ($user->isSupervisorOrManager()) {
            $managerDeptId = $user->employee?->department_id;

            return $managerDeptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can view the SPK SMART analysis for a leave request.
     * Restricted strictly to Manager/Admin Staff (same department), direct superior of the applicant,
     * and Admin HR/Super User/Super Admin (same tenant).
     */
    public function viewSmartAnalysis(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        if ($user->isAdminOrAbove()) {
            return true;
        }

        $reqEmployee = $this->resolveRequestEmployee($leaveRequest);

        if ($this->isDirectManagerOf($user, $reqEmployee)) {
            return true;
        }

        if ($user->isSupervisorOrManager()) {
            $managerDeptId = $user->employee?->department_id;

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
     * Determine whether the user can execute Tier 1 approval on a leave request.
     * - Regular staff: Manager / Admin Staff (non-AL) / Admin+
     * - HOD-tagged applicant: Admin HR / Super User / Super Admin (Tier 1 = pending_hr)
     */
    public function approveTier1(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        $reqEmployee = $this->resolveRequestEmployee($leaveRequest);
        $isHod = $reqEmployee !== null && $reqEmployee->hasTag('HOD');
        $isSelf = $user->employee !== null && (int) $user->employee->id === (int) $leaveRequest->employee_id;

        // Prevent self-approval (Admin+ cannot self-approve HOD requests unless Super Admin)
        if ($isSelf && (! $user->isAdminOrAbove() || ($isHod && ! $user->isSuperAdmin()))) {
            return false;
        }

        if ($isHod) {
            return $user->isAdminOrAbove();
        }

        if ($user->isAdminOrAbove()) {
            return true;
        }

        if ($user->isAdminStaff()) {
            // Admin Staff (Supervisor / Duty Manager) is strictly forbidden from approving Annual Leave (AL).
            if ($this->isAnnualLeave($leaveRequest)) {
                return false;
            }

            $deptId = $user->employee?->department_id;

            return $deptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $deptId;
        }

        if ($user->isManager()) {
            $managerDeptId = $user->employee?->department_id;

            return $managerDeptId !== null
                && $reqEmployee !== null
                && (int) $reqEmployee->department_id === (int) $managerDeptId;
        }

        return false;
    }

    /**
     * Determine whether the user can execute Tier 2 (final) approval on a leave request.
     * - Regular staff: Admin HR / Super User / Super Admin
     * - HOD-tagged applicant: HOD's direct superior (employee.manager_id) or Super User / Super Admin emergency fallback
     */
    public function approveTier2(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->is_active || ! $this->isSameTenant($user, $leaveRequest)) {
            return false;
        }

        $reqEmployee = $this->resolveRequestEmployee($leaveRequest);
        $isHod = $reqEmployee !== null && $reqEmployee->hasTag('HOD');

        if ($isHod) {
            $isSelf = $user->employee !== null && (int) $user->employee->id === (int) $leaveRequest->employee_id;
            if ($isSelf && ! $user->isSuperAdmin()) {
                return false;
            }

            // Super Admin & Super User always act as executive/emergency fallback for Tier 2
            if ($user->isSuperAdmin() || $user->isSuperUser()) {
                return true;
            }

            return $this->isDirectManagerOf($user, $reqEmployee);
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

        if ($leaveRequest->canBeApprovedTier2()) {
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

        // 3. Manager or Admin Staff of the same department
        if ($user->isSupervisorOrManager()) {
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
     * Determine whether the user can access the SPK SMART Debug & Simulator console (Super Admin & Super User).
     */
    public function debugSmartEngine(User $user): bool
    {
        return (bool) $user->is_active && in_array($user->role, ['super_admin', 'super_user'], true);
    }

    /**
     * Resolve the applicant Employee with tags loaded (scope-safe).
     */
    private function resolveRequestEmployee(LeaveRequest $leaveRequest): ?Employee
    {
        $reqEmployee = $leaveRequest->relationLoaded('employee')
            ? $leaveRequest->employee
            : Employee::withoutGlobalScopes()
                ->with(['tags' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($leaveRequest->employee_id);

        if ($reqEmployee !== null && ! $reqEmployee->relationLoaded('tags') && ! array_key_exists('has_hod_tag', $reqEmployee->getAttributes())) {
            $reqEmployee->load(['tags' => fn ($q) => $q->withoutGlobalScopes()]);
        }

        return $reqEmployee;
    }

    /**
     * Determine whether the acting user is the direct superior (manager_id) of the applicant employee.
     */
    private function isDirectManagerOf(User $user, ?Employee $employee): bool
    {
        return $user->employee !== null
            && $employee !== null
            && $employee->manager_id !== null
            && (int) $user->employee->id === (int) $employee->manager_id;
    }

    /**
     * Determine whether the leave request is for Annual Leave (AL).
     */
    private function isAnnualLeave(LeaveRequest $leaveRequest): bool
    {
        $leaveType = $leaveRequest->relationLoaded('leaveType')
            ? $leaveRequest->leaveType
            : LeaveType::withoutGlobalScopes()->find($leaveRequest->leave_type_id);

        return strtoupper((string) ($leaveType?->code ?? '')) === 'AL';
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
