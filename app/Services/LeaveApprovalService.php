<?php

namespace App\Services;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveLedgerService $leaveLedgerService,
        private readonly SmartDecisionService $smartDecisionService,
        private readonly ?WebPushService $webPushService = null,
        private readonly ?AuditTrailService $auditTrailService = null
    ) {}

    /**
     * Tier 1 Approval.
     * - Regular staff: Manager (or Admin+) transitions pending_manager -> pending_hr.
     * - HOD-tagged applicant: Admin HR (or above) transitions pending_hr -> pending_manager.
     * Does NOT debit the leave ledger (quota remains reserved until Tier 2 finalization).
     *
     * @throws ValidationException
     */
    public function approveTier1(LeaveRequest $leaveRequest, User $actor, ?string $notes = null): LeaveRequest
    {
        $isHod = false;
        $previousStatus = $leaveRequest->status;

        $approvedRequest = DB::transaction(function () use ($leaveRequest, $actor, $notes, &$isHod, &$previousStatus): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->with(['tags' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $isHod = $lockedEmployee->hasTag('HOD');
            $this->ensureNotManagerSelfApproval($lockedRequest, $actor, $isHod);

            $expectedStatus = $isHod ? LeaveRequest::STATUS_PENDING_HR : LeaveRequest::STATUS_PENDING_MANAGER;
            if ($lockedRequest->status !== $expectedStatus) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier1')],
                ]);
            }

            if ($isHod && ! $actor->isAdminOrAbove()) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier1')],
                ]);
            }

            $previousStatus = $lockedRequest->status;
            $now = Carbon::now('Asia/Jakarta');
            $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

            if ($isHod) {
                $lockedRequest->update([
                    'status' => LeaveRequest::STATUS_PENDING_MANAGER,
                    'hr_approved_by' => $actor->id,
                    'hr_approved_at' => $now,
                ]);
            } else {
                $lockedRequest->update([
                    'status' => LeaveRequest::STATUS_PENDING_HR,
                    'manager_approved_by' => $actor->id,
                    'manager_approved_at' => $now,
                ]);
            }

            LeaveRequestApproval::withoutGlobalScopes()->create([
                'company_id' => $lockedRequest->company_id,
                'leave_request_id' => $lockedRequest->id,
                'approver_id' => $actor->id,
                'tier' => LeaveRequestApproval::TIER_MANAGER,
                'action' => LeaveRequestApproval::ACTION_APPROVED,
                'notes' => $cleanNotes,
                'smart_score_snapshot' => $lockedRequest->smart_score,
                'acted_at' => $now,
            ]);

            return $lockedRequest->refresh();
        }, 3);

        ($this->auditTrailService ?? app(AuditTrailService::class))->record(
            'approved_tier1',
            $approvedRequest,
            ['status' => $previousStatus],
            array_filter([
                'status' => $approvedRequest->status,
                'manager_approved_by' => $approvedRequest->manager_approved_by,
                'hr_approved_by' => $approvedRequest->hr_approved_by,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ], static fn ($v) => $v !== null),
            $isHod
                ? "Tier 1 HR approval (HOD flow) for leave request #{$approvedRequest->id}"
                : "Tier 1 manager approval for leave request #{$approvedRequest->id}",
            ['tier' => 1, 'is_hod' => $isHod],
            $actor,
            (int) $approvedRequest->company_id
        );

        $this->dispatchTier1ApprovedNotifications($approvedRequest, $actor, $isHod);

        return $approvedRequest;
    }

    /**
     * Tier 2 Final Approval.
     * - Regular staff: Final HR / Admin approval (pending_hr -> approved).
     * - HOD-tagged applicant: Direct superior (manager_id) or Super User/Super Admin fallback (pending_manager -> approved).
     * Atomically depletes the leave ledger via FIFO (if requires_quota), transitions status to approved,
     * syncs the employee's roster schedules to OFF, and recalculates overlapping pending requests' SPK SMART scores.
     *
     * @throws ValidationException
     */
    public function approveTier2(LeaveRequest $leaveRequest, User $actor, ?string $notes = null): LeaveRequest
    {
        $isHod = false;
        $previousStatus = $leaveRequest->status;

        $approvedRequest = DB::transaction(function () use ($leaveRequest, $actor, $notes, &$isHod, &$previousStatus): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->with(['tags' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->with(['leaveType' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $isHod = $lockedEmployee->hasTag('HOD');
            $this->ensureNotManagerSelfApproval($lockedRequest, $actor, $isHod);

            $expectedStatus = $isHod ? LeaveRequest::STATUS_PENDING_MANAGER : LeaveRequest::STATUS_PENDING_HR;
            if ($lockedRequest->status !== $expectedStatus) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_tier2')],
                ]);
            }

            if ($isHod) {
                $isDirectManager = $actor->employee !== null
                    && $lockedEmployee->manager_id !== null
                    && (int) $actor->employee->id === (int) $lockedEmployee->manager_id;

                if (! $actor->isSuperAdmin() && ! $actor->isSuperUser() && ! $isDirectManager) {
                    throw ValidationException::withMessages([
                        'status' => [__('leave.err_invalid_status_for_tier2')],
                    ]);
                }
            } elseif (! $actor->isAdminOrAbove()) {
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
                        (int) $lockedRequest->total_days,
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

            $previousStatus = $lockedRequest->status;
            $now = Carbon::now('Asia/Jakarta');

            if ($isHod) {
                $lockedRequest->update([
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'ledger_entry_id' => $ledgerEntryId,
                    'manager_approved_by' => $actor->id,
                    'manager_approved_at' => $now,
                ]);
            } else {
                $lockedRequest->update([
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'ledger_entry_id' => $ledgerEntryId,
                    'hr_approved_by' => $actor->id,
                    'hr_approved_at' => $now,
                ]);
            }

            LeaveRequestApproval::withoutGlobalScopes()->create([
                'company_id' => $lockedRequest->company_id,
                'leave_request_id' => $lockedRequest->id,
                'approver_id' => $actor->id,
                'tier' => LeaveRequestApproval::TIER_HR,
                'action' => LeaveRequestApproval::ACTION_APPROVED,
                'notes' => $cleanNotes,
                'smart_score_snapshot' => $lockedRequest->smart_score,
                'acted_at' => $now,
            ]);

            $this->syncApprovedLeaveToRoster($lockedRequest, $lockedEmployee);
            $this->recalculateOverlappingPendingRequests($lockedRequest, $lockedEmployee);

            return $lockedRequest->refresh();
        }, 3);

        ($this->auditTrailService ?? app(AuditTrailService::class))->record(
            'approved_tier2',
            $approvedRequest,
            ['status' => $previousStatus],
            array_filter([
                'status' => LeaveRequest::STATUS_APPROVED,
                'manager_approved_by' => $approvedRequest->manager_approved_by,
                'hr_approved_by' => $approvedRequest->hr_approved_by,
                'ledger_entry_id' => $approvedRequest->ledger_entry_id,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ], static fn ($v) => $v !== null),
            $isHod
                ? "Tier 2 final superior approval (HOD flow) for leave request #{$approvedRequest->id}"
                : "Tier 2 final HR approval for leave request #{$approvedRequest->id}",
            ['tier' => 2, 'is_hod' => $isHod],
            $actor,
            (int) $approvedRequest->company_id
        );

        $this->dispatchTier2ApprovedNotification($approvedRequest);

        return $approvedRequest;
    }

    /**
     * Reject a pending leave request (Tier 1 or Tier 2, supporting both regular and HOD flows).
     * Releases any reserved quota without mutating the leave ledger.
     *
     * @throws ValidationException
     */
    public function reject(LeaveRequest $leaveRequest, User $actor, string $reason): LeaveRequest
    {
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

        $previousStatus = $leaveRequest->status;
        $rejectedRequest = DB::transaction(function () use ($leaveRequest, $actor, $cleanReason, &$previousStatus): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->with(['tags' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_invalid_status_for_reject')],
                ]);
            }

            $isHod = $lockedEmployee->hasTag('HOD');
            $this->ensureNotManagerSelfApproval($lockedRequest, $actor, $isHod);

            if ($isHod) {
                if ($lockedRequest->status === LeaveRequest::STATUS_PENDING_HR && ! $actor->isAdminOrAbove()) {
                    throw ValidationException::withMessages([
                        'status' => [__('leave.err_invalid_status_for_reject')],
                    ]);
                }

                if ($lockedRequest->status === LeaveRequest::STATUS_PENDING_MANAGER) {
                    $isDirectManager = $actor->employee !== null
                        && $lockedEmployee->manager_id !== null
                        && (int) $actor->employee->id === (int) $lockedEmployee->manager_id;

                    if (! $actor->isSuperAdmin() && ! $actor->isSuperUser() && ! $isDirectManager) {
                        throw ValidationException::withMessages([
                            'status' => [__('leave.err_invalid_status_for_reject')],
                        ]);
                    }
                }

                $tier = $lockedRequest->status === LeaveRequest::STATUS_PENDING_HR
                    ? LeaveRequestApproval::TIER_MANAGER
                    : LeaveRequestApproval::TIER_HR;
            } else {
                if ($lockedRequest->status === LeaveRequest::STATUS_PENDING_HR && ! $actor->isAdminOrAbove()) {
                    throw ValidationException::withMessages([
                        'status' => [__('leave.err_invalid_status_for_reject')],
                    ]);
                }

                $tier = $lockedRequest->status === LeaveRequest::STATUS_PENDING_MANAGER
                    ? LeaveRequestApproval::TIER_MANAGER
                    : LeaveRequestApproval::TIER_HR;
            }

            $previousStatus = $lockedRequest->status;
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
        }, 3);

        ($this->auditTrailService ?? app(AuditTrailService::class))->record(
            'rejected',
            $rejectedRequest,
            ['status' => $previousStatus],
            [
                'status' => LeaveRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejection_reason' => $cleanReason,
            ],
            "Leave request #{$rejectedRequest->id} rejected",
            [],
            $actor,
            (int) $rejectedRequest->company_id
        );

        $this->dispatchRejectedNotification($rejectedRequest, $cleanReason);

        return $rejectedRequest;
    }

    private function dispatchTier1ApprovedNotifications(LeaveRequest $leaveRequest, User $actor, bool $isHod = false): void
    {
        try {
            $leaveRequest->loadMissing([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with(['user', 'manager']),
                'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

            $push = $this->webPushService ?? app(WebPushService::class);
            $applicantUser = $leaveRequest->employee?->user;
            $applicantName = $applicantUser?->name ?? 'Staf';
            $leaveTypeCode = $leaveRequest->leaveType?->code ?? 'LEAVE';
            $startStr = $leaveRequest->start_date instanceof CarbonInterface
                ? $leaveRequest->start_date->toDateString()
                : Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->toDateString();
            $endStr = $leaveRequest->end_date instanceof CarbonInterface
                ? $leaveRequest->end_date->toDateString()
                : Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->toDateString();
            $actionUrl = route('leave.index', [], false);
            $meta = [
                'leave_request_id' => $leaveRequest->id,
                'leave_request_uuid' => $leaveRequest->uuid,
                'tier' => 1,
            ];

            if ($isHod) {
                $recipientIds = User::where('company_id', $leaveRequest->company_id)
                    ->where('role', 'super_user')
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();

                $directManagerUserId = $leaveRequest->employee?->manager?->user_id;
                if ($directManagerUserId !== null) {
                    $recipientIds[] = (int) $directManagerUserId;
                }

                $adminIds = array_values(array_diff(array_unique($recipientIds), [$actor->id]));
            } else {
                $adminIds = $push->resolveCompanyAdmins((int) $leaveRequest->company_id, $actor->id)
                    ->pluck('id')
                    ->all();
            }
            if ($adminIds !== []) {
                SendWebPushNotificationJob::dispatch(
                    $adminIds,
                    'leave_tier1_approved',
                    __('pwa.notif_leave_tier1_admin_title'),
                    __('pwa.notif_leave_tier1_admin_body', [
                        'name' => $applicantName,
                        'type' => $leaveTypeCode,
                        'start' => $startStr,
                        'end' => $endStr,
                    ]),
                    $actionUrl,
                    $meta
                );
            }

            if ($applicantUser !== null && $applicantUser->is_active && $applicantUser->id !== $actor->id) {
                SendWebPushNotificationJob::dispatch(
                    [$applicantUser->id],
                    'leave_tier1_approved',
                    __('pwa.notif_leave_tier1_staff_title'),
                    __('pwa.notif_leave_tier1_staff_body', [
                        'type' => $leaveTypeCode,
                        'start' => $startStr,
                        'end' => $endStr,
                    ]),
                    $actionUrl,
                    $meta
                );
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch Tier 1 leave approval notifications: '.$e->getMessage());
        }
    }

    private function dispatchTier2ApprovedNotification(LeaveRequest $leaveRequest): void
    {
        try {
            $leaveRequest->loadMissing([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with('user'),
                'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

            $applicantUser = $leaveRequest->employee?->user;
            if ($applicantUser === null || ! $applicantUser->is_active) {
                return;
            }

            $leaveTypeCode = $leaveRequest->leaveType?->code ?? 'LEAVE';
            $startStr = $leaveRequest->start_date instanceof CarbonInterface
                ? $leaveRequest->start_date->toDateString()
                : Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->toDateString();
            $endStr = $leaveRequest->end_date instanceof CarbonInterface
                ? $leaveRequest->end_date->toDateString()
                : Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->toDateString();
            $daysStr = (string) (int) $leaveRequest->total_days;

            SendWebPushNotificationJob::dispatch(
                [$applicantUser->id],
                'leave_approved',
                __('pwa.notif_leave_approved_title'),
                __('pwa.notif_leave_approved_body', [
                    'type' => $leaveTypeCode,
                    'start' => $startStr,
                    'end' => $endStr,
                    'days' => $daysStr,
                ]),
                route('leave.index', [], false),
                [
                    'leave_request_id' => $leaveRequest->id,
                    'leave_request_uuid' => $leaveRequest->uuid,
                    'tier' => 2,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch Tier 2 leave approval notification: '.$e->getMessage());
        }
    }

    private function dispatchRejectedNotification(LeaveRequest $leaveRequest, string $reason): void
    {
        try {
            $leaveRequest->loadMissing([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with('user'),
                'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

            $applicantUser = $leaveRequest->employee?->user;
            if ($applicantUser === null || ! $applicantUser->is_active) {
                return;
            }

            $leaveTypeCode = $leaveRequest->leaveType?->code ?? 'LEAVE';
            $startStr = $leaveRequest->start_date instanceof CarbonInterface
                ? $leaveRequest->start_date->toDateString()
                : Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->toDateString();
            $endStr = $leaveRequest->end_date instanceof CarbonInterface
                ? $leaveRequest->end_date->toDateString()
                : Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->toDateString();

            SendWebPushNotificationJob::dispatch(
                [$applicantUser->id],
                'leave_rejected',
                __('pwa.notif_leave_rejected_title'),
                __('pwa.notif_leave_rejected_body', [
                    'type' => $leaveTypeCode,
                    'start' => $startStr,
                    'end' => $endStr,
                    'reason' => $reason,
                ]),
                route('leave.index', [], false),
                [
                    'leave_request_id' => $leaveRequest->id,
                    'leave_request_uuid' => $leaveRequest->uuid,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch leave rejection notification: '.$e->getMessage());
        }
    }

    /**
     * Synchronize the employee's work schedules across the approved leave date range to OFF.
     */
    private function syncApprovedLeaveToRoster(LeaveRequest $leaveRequest, Employee $employee): void
    {
        $offShift = Shift::withoutGlobalScopes()
            ->where('company_id', $leaveRequest->company_id)
            ->where('code', 'OFF')
            ->whereNull('deleted_at')
            ->first();

        if (! $offShift) {
            $offShift = Shift::withoutGlobalScopes()->create([
                'company_id' => $leaveRequest->company_id,
                'code' => 'OFF',
                'name' => 'Day Off',
                'start_time' => '00:00:00',
                'end_time' => '00:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 0,
                'color_badge' => '#9CA3AF',
                'is_active' => true,
            ]);
        }

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
                ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
                ->where('company_id', $leaveRequest->company_id)
                ->where('employee_id', $employee->id)
                ->whereDate('schedule_date', $dateStr)
                ->orderBy('id')
                ->get();

            $hasOtherLockedLeave = $daySchedules->contains(function (WorkSchedule $ws) use ($leaveRequest): bool {
                if (! $ws->isLockedLeave()) {
                    return false;
                }

                $meta = $ws->parseLeaveMetadata();
                $existingReqId = $meta['leave_request_id'] ?? null;
                if ($existingReqId !== null) {
                    return (int) $existingReqId !== (int) $leaveRequest->id;
                }

                if (preg_match('/^LEAVE:[^:|]+:(\d+)/', (string) $ws->notes, $matches) === 1) {
                    return (int) $matches[1] !== (int) $leaveRequest->id;
                }

                return true;
            });

            if ($hasOtherLockedLeave) {
                $cursor->addDay();

                continue;
            }

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
        $startStr = Carbon::parse($approvedRequest->start_date, 'Asia/Jakarta')->toDateString();
        $endStr = Carbon::parse($approvedRequest->end_date, 'Asia/Jakarta')->toDateString();

        $this->smartDecisionService->recalculateOverlappingDepartmentRequests(
            $employee,
            $startStr,
            $endStr,
            (int) $approvedRequest->id
        );
    }

    /**
     * Prevent an approver from approving or rejecting their own leave request.
     *
     * @throws ValidationException
     */
    private function ensureNotManagerSelfApproval(LeaveRequest $leaveRequest, User $actor, bool $isHod = false): void
    {
        $isSelf = $actor->employee !== null && (int) $actor->employee->id === (int) $leaveRequest->employee_id;
        if ($isSelf && (! $actor->isAdminOrAbove() || ($isHod && ! $actor->isSuperAdmin()))) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_self_approval_forbidden')],
            ]);
        }
    }
}
