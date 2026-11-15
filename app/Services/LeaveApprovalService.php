<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveLedgerService $leaveLedgerService,
        private readonly SmartDecisionService $smartDecisionService
    ) {}

    /**
     * Tier 1 Approval — Department Manager (or Admin+) transitions a request from pending_manager to pending_hr.
     * Does NOT debit the leave ledger (quota remains reserved until Tier 2 HR finalization).
     *
     * @throws ValidationException
     */
    public function approveTier1(LeaveRequest $leaveRequest, User $actor, ?string $notes = null): LeaveRequest
    {
        $this->ensureNotManagerSelfApproval($leaveRequest, $actor);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING_MANAGER) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_invalid_status_for_tier1')],
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $notes): LeaveRequest {
            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== LeaveRequest::STATUS_PENDING_MANAGER) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier1')],
                ]);
            }

            $now = Carbon::now('Asia/Jakarta');
            $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

            $lockedRequest->update([
                'status' => LeaveRequest::STATUS_PENDING_HR,
                'manager_approved_by' => $actor->id,
                'manager_approved_at' => $now,
            ]);

            LeaveRequestApproval::withoutGlobalScopes()->create([
                'company_id' => $lockedRequest->company_id,
                'leave_request_id' => $lockedRequest->id,
                'approver_id' => $actor->id,
                'tier' => 1,
                'action' => LeaveRequestApproval::ACTION_APPROVED,
                'notes' => $cleanNotes,
                'smart_score_snapshot' => $lockedRequest->smart_score,
                'acted_at' => $now,
            ]);

            return $lockedRequest->refresh();
        });
    }

    /**
     * Tier 2 Approval — Final HR / Admin approval.
     * Atomically depletes the leave ledger via FIFO (if requires_quota), transitions status to approved,
     * syncs the employee's roster schedules to OFF, and recalculates overlapping pending requests' SPK SMART scores.
     *
     * @throws ValidationException
     */
    public function approveTier2(LeaveRequest $leaveRequest, User $actor, ?string $notes = null): LeaveRequest
    {
        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING_HR) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_invalid_status_for_tier2')],
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $notes): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->with(['leaveType' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== LeaveRequest::STATUS_PENDING_HR) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier2')],
                ]);
            }

            if ($lockedRequest->ledger_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier2')],
                ]);
            }

            $leaveType = $lockedRequest->leaveType;
            $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $ledgerEntryId = null;

            if ($leaveType !== null && $leaveType->requires_quota) {
                try {
                    $ledgerEntry = $this->leaveLedgerService->recordDebit(
                        $lockedEmployee,
                        $leaveType,
                        (float) $lockedRequest->total_days,
                        [
                            'reference_type' => 'leave_request',
                            'reference_id' => $lockedRequest->id,
                            'notes' => $cleanNotes ?: sprintf(
                                'Approved leave request (%s to %s)',
                                $lockedRequest->start_date->format('Y-m-d'),
                                $lockedRequest->end_date->format('Y-m-d')
                            ),
                            'created_by' => $actor->id,
                        ]
                    );
                    $ledgerEntryId = $ledgerEntry->id;
                } catch (RuntimeException|InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => [$e->getMessage()],
                    ]);
                }
            }

            $now = Carbon::now('Asia/Jakarta');

            $lockedRequest->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'ledger_entry_id' => $ledgerEntryId,
                'hr_approved_by' => $actor->id,
                'hr_approved_at' => $now,
            ]);

            LeaveRequestApproval::withoutGlobalScopes()->create([
                'company_id' => $lockedRequest->company_id,
                'leave_request_id' => $lockedRequest->id,
                'approver_id' => $actor->id,
                'tier' => 2,
                'action' => LeaveRequestApproval::ACTION_APPROVED,
                'notes' => $cleanNotes,
                'smart_score_snapshot' => $lockedRequest->smart_score,
                'acted_at' => $now,
            ]);

            $this->syncApprovedLeaveToRoster($lockedRequest, $lockedEmployee);
            $this->recalculateOverlappingPendingRequests($lockedRequest, $lockedEmployee);

            return $lockedRequest->refresh();
        });
    }

    /**
     * Reject a pending leave request (Tier 1 if pending_manager, Tier 2 if pending_hr).
     * Releases any reserved quota without mutating the leave ledger.
     *
     * @throws ValidationException
     */
    public function reject(LeaveRequest $leaveRequest, User $actor, string $reason): LeaveRequest
    {
        $this->ensureNotManagerSelfApproval($leaveRequest, $actor);

        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 5) {
            throw ValidationException::withMessages([
                'reason' => [__('leave.err_rejection_reason_min')],
            ]);
        }

        if (! $leaveRequest->isPending()) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_invalid_status_for_reject')],
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $cleanReason): LeaveRequest {
            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_reject')],
                ]);
            }

            $tier = $lockedRequest->status === LeaveRequest::STATUS_PENDING_MANAGER ? 1 : 2;
            $now = Carbon::now('Asia/Jakarta');

            $lockedRequest->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => $now,
                'rejection_reason' => $cleanReason,
            ]);

            LeaveRequestApproval::withoutGlobalScopes()->create([
                'company_id' => $lockedRequest->company_id,
                'leave_request_id' => $lockedRequest->id,
                'approver_id' => $actor->id,
                'tier' => $tier,
                'action' => LeaveRequestApproval::ACTION_REJECTED,
                'notes' => $cleanReason,
                'smart_score_snapshot' => $lockedRequest->smart_score,
                'acted_at' => $now,
            ]);

            return $lockedRequest->refresh();
        });
    }

    /**
     * Synchronize the employee's work schedules across the approved leave date range to OFF.
     */
    private function syncApprovedLeaveToRoster(LeaveRequest $leaveRequest, Employee $employee): void
    {
        $offShift = Shift::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $leaveRequest->company_id,
                'code' => 'OFF',
            ],
            [
                'name' => 'Day Off',
                'start_time' => '00:00:00',
                'end_time' => '00:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 0,
                'color_badge' => '#9CA3AF',
                'is_active' => true,
            ]
        );

        $start = Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->startOfDay();
        $end = Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->startOfDay();
        $leaveCode = $leaveRequest->leaveType?->code ?? 'LEAVE';
        $leaveName = $leaveRequest->leaveType?->name ?? $leaveCode;
        $cleanReason = trim(str_replace(["\r", "\n"], ' ', (string) $leaveRequest->reason));
        $structuredNotes = mb_substr("LEAVE:{$leaveCode}:{$leaveRequest->id}|{$leaveName}: {$cleanReason}", 0, 500);

        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();

            $daySchedules = WorkSchedule::withoutGlobalScopes()
                ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
                ->where('company_id', $leaveRequest->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('schedule_date', $dateStr)
                ->orderBy('id')
                ->get();

            $isRegularPublishedOff = $daySchedules->isNotEmpty()
                && $daySchedules->every(function (WorkSchedule $ws): bool {
                    return $ws->isOff() && $ws->status === 'published' && ! $ws->isLockedLeave();
                });

            if ($isRegularPublishedOff) {
                $cursor->addDay();

                continue;
            }

            if ($daySchedules->isNotEmpty()) {
                $primary = $daySchedules->first();

                // Delete any secondary split-shift segments on the same date
                foreach ($daySchedules->slice(1) as $extraSegment) {
                    $extraSegment->delete();
                }

                $primary->update([
                    'shift_id' => $offShift->id,
                    'custom_start_time' => null,
                    'custom_end_time' => null,
                    'published_start_time' => null,
                    'published_end_time' => null,
                    'duty_tag_id' => null,
                    'status' => 'published',
                    'notes' => $structuredNotes,
                ]);
            } else {
                WorkSchedule::withoutGlobalScopes()->create([
                    'company_id' => $leaveRequest->company_id,
                    'employee_id' => $employee->id,
                    'shift_id' => $offShift->id,
                    'schedule_date' => $dateStr,
                    'custom_start_time' => null,
                    'custom_end_time' => null,
                    'published_start_time' => null,
                    'published_end_time' => null,
                    'duty_tag_id' => null,
                    'status' => 'published',
                    'notes' => $structuredNotes,
                ]);
            }

            $cursor->addDay();
        }
    }

    /**
     * Recalculate SPK SMART scores for any overlapping pending leave requests in the same department.
     */
    private function recalculateOverlappingPendingRequests(LeaveRequest $approvedRequest, Employee $employee): void
    {
        if ($employee->department_id === null) {
            return;
        }

        $startStr = Carbon::parse($approvedRequest->start_date, 'Asia/Jakarta')->toDateString();
        $endStr = Carbon::parse($approvedRequest->end_date, 'Asia/Jakarta')->toDateString();

        $competingRequests = LeaveRequest::withoutGlobalScopes()
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with(['department' => fn ($dq) => $dq->withoutGlobalScopes()]),
                'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->where('company_id', $approvedRequest->company_id)
            ->where('id', '!=', $approvedRequest->id)
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING_MANAGER,
                LeaveRequest::STATUS_PENDING_HR,
            ])
            ->whereDate('start_date', '<=', $endStr)
            ->whereDate('end_date', '>=', $startStr)
            ->whereHas('employee', function ($q) use ($employee): void {
                $q->withoutGlobalScopes()->where('department_id', $employee->department_id);
            })
            ->get();

        foreach ($competingRequests as $competing) {
            $this->smartDecisionService->evaluateSingle($competing, true);
        }
    }

    /**
     * Prevent a manager (or non-admin user) from approving or rejecting their own leave request.
     *
     * @throws ValidationException
     */
    private function ensureNotManagerSelfApproval(LeaveRequest $leaveRequest, User $actor): void
    {
        if (
            ! $actor->isAdminOrAbove()
            && $actor->employee !== null
            && (int) $actor->employee->id === (int) $leaveRequest->employee_id
        ) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_self_approval_forbidden')],
            ]);
        }
    }
}
