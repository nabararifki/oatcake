<?php

namespace App\Services;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeaveRequestService
{
    public function __construct(
        private readonly LeaveLedgerService $ledgerService,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly SmartDecisionService $smartDecisionService,
        private readonly ?WebPushService $webPushService = null
    ) {}

    /**
     * Calculate effective leave days between startDate and endDate (inclusive) in Asia/Jakarta,
     * excluding dates where the employee's published roster is scheduled as Day Off (OFF).
     *
     * @return array{
     *     calendar_days: int,
     *     excluded_off_days: int,
     *     effective_days: int,
     *     excluded_dates: list<string>
     * }
     */
    public function calculateEffectiveDays(
        Employee $employee,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate
    ): array {
        $start = Carbon::parse($startDate, 'Asia/Jakarta')->startOfDay();
        $end = Carbon::parse($endDate, 'Asia/Jakarta')->startOfDay();

        if ($start->greaterThan($end)) {
            return [
                'calendar_days' => 0,
                'excluded_off_days' => 0,
                'effective_days' => 0,
                'excluded_dates' => [],
            ];
        }

        $schedulesByDate = WorkSchedule::withoutGlobalScopes()
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'published')
            ->whereDate('schedule_date', '>=', $start->toDateString())
            ->whereDate('schedule_date', '<=', $end->toDateString())
            ->get()
            ->groupBy(fn (WorkSchedule $ws): string => $ws->schedule_date instanceof CarbonInterface
                ? $ws->schedule_date->toDateString()
                : Carbon::parse($ws->schedule_date, 'Asia/Jakarta')->toDateString()
            );

        $calendarDays = 0;
        $excludedOffDays = 0;
        $effectiveDays = 0;
        $excludedDates = [];

        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();
            $calendarDays++;

            $daySchedules = $schedulesByDate->get($dateStr, collect());

            $isScheduledOff = $daySchedules->isNotEmpty()
                && $daySchedules->every(function (WorkSchedule $ws): bool {
                    if ($ws->isLockedLeave()) {
                        return false;
                    }

                    return $ws->isOff()
                        || (bool) $ws->getAttribute('is_day_off')
                        || ($ws->shift !== null && strtoupper((string) $ws->shift->code) === 'OFF');
                });

            if ($isScheduledOff) {
                $excludedOffDays++;
                $excludedDates[] = $dateStr;
            } else {
                $effectiveDays += 1;
            }

            $cursor->addDay();
        }

        return [
            'calendar_days' => $calendarDays,
            'excluded_off_days' => $excludedOffDays,
            'effective_days' => (int) $effectiveDays,
            'excluded_dates' => $excludedDates,
        ];
    }

    /**
     * Get the real-time quota summary (ledger balance, pending reserved days, and available balance).
     *
     * @return array{
     *     ledger_balance: int,
     *     reserved_days: int,
     *     available_balance: int
     * }
     */
    public function getQuotaSummary(
        Employee $employee,
        LeaveType|string $leaveType,
        ?int $excludeRequestId = null
    ): array {
        $type = $this->ledgerService->resolveLeaveType($leaveType, (int) $employee->company_id);

        $ledgerBalance = (int) $this->ledgerService->getBalance($employee, $type);

        $reservedQuery = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING_MANAGER,
                LeaveRequest::STATUS_PENDING_HR,
            ]);

        if ($excludeRequestId !== null) {
            $reservedQuery->where('id', '!=', $excludeRequestId);
        }

        $reservedDays = (int) round((float) $reservedQuery->sum('total_days'));

        $availableBalance = max(0, (int) ($ledgerBalance - $reservedDays));

        return [
            'ledger_balance' => (int) $ledgerBalance,
            'reserved_days' => (int) $reservedDays,
            'available_balance' => (int) $availableBalance,
        ];
    }

    /**
     * Submit a new leave request with row-level locking, effective day calculation,
     * overlap check, attachment guard, and quota reservation guard (no premature ledger debit).
     *
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function submitRequest(
        Employee $employee,
        array $data,
        ?UploadedFile $attachment = null,
        ?User $actor = null
    ): LeaveRequest {
        $start = Carbon::parse($data['start_date'] ?? '', 'Asia/Jakarta')->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? '', 'Asia/Jakarta')->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'end_date' => [__('leave.err_invalid_date_range')],
            ]);
        }

        $startDateStr = $start->toDateString();
        $endDateStr = $end->toDateString();

        $leaveType = $this->resolveLeaveTypeFromPayload($data, (int) $employee->company_id);
        if ($leaveType === null || ! $leaveType->is_active) {
            throw ValidationException::withMessages([
                'leave_type_id' => [__('leave.err_invalid_leave_type')],
            ]);
        }

        $uploadedFile = $attachment ?? ($data['attachment'] ?? null);
        if ($leaveType->requires_attachment && ! ($uploadedFile instanceof UploadedFile)) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_attachment_required', ['type' => $leaveType->name])],
            ]);
        }

        $leaveRequest = DB::transaction(function () use (
            $employee,
            $leaveType,
            $startDateStr,
            $endDateStr,
            $data,
            $uploadedFile,
            $actor
        ): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Calculate effective days based on published roster
            $calculation = $this->calculateEffectiveDays($lockedEmployee, $startDateStr, $endDateStr);
            $effectiveDays = (int) $calculation['effective_days'];

            if ($effectiveDays <= 0) {
                throw ValidationException::withMessages([
                    'start_date' => [__('leave.err_zero_effective_days')],
                ]);
            }

            // 2. Anti-Overlap Guard
            $hasOverlap = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $lockedEmployee->company_id)
                ->where('employee_id', $lockedEmployee->id)
                ->whereIn('status', [
                    LeaveRequest::STATUS_PENDING_MANAGER,
                    LeaveRequest::STATUS_PENDING_HR,
                    LeaveRequest::STATUS_APPROVED,
                ])
                ->whereDate('start_date', '<=', $endDateStr)
                ->whereDate('end_date', '>=', $startDateStr)
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'start_date' => [__('leave.err_overlapping_request')],
                ]);
            }

            // 3. Quota & Reservation Guard (No Premature Ledger Debit)
            if ($leaveType->requires_quota) {
                $quotaSummary = $this->getQuotaSummary($lockedEmployee, $leaveType);

                if ($quotaSummary['available_balance'] < $effectiveDays) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => [__('leave.err_insufficient_quota', [
                            'type' => $leaveType->code,
                            'available' => (string) (int) $quotaSummary['available_balance'],
                            'requested' => (string) (int) $effectiveDays,
                        ])],
                    ]);
                }
            }

            // 4. Process private attachment if provided
            $attachmentPath = null;
            if ($uploadedFile instanceof UploadedFile) {
                $company = Company::withoutGlobalScopes()->find($lockedEmployee->company_id);
                $attachmentPath = $this->attachmentService->processAndStore(
                    $uploadedFile,
                    $company ?? (int) $lockedEmployee->company_id,
                    (string) $lockedEmployee->uuid
                );
            }

            // 5. Determine initial approval status based on applicant's User role
            $applicantUser = $actor
                ?? ($lockedEmployee->relationLoaded('user') ? $lockedEmployee->user : null)
                ?? ($lockedEmployee->user_id ? User::find($lockedEmployee->user_id) : null);

            $initialStatus = ($applicantUser !== null && in_array($applicantUser->role, ['manager', 'admin', 'super_user', 'super_admin'], true))
                ? LeaveRequest::STATUS_PENDING_HR
                : LeaveRequest::STATUS_PENDING_MANAGER;

            $leaveRequest = LeaveRequest::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'total_days' => $effectiveDays,
                'reason' => trim((string) ($data['reason'] ?? '')),
                'attachment_path' => $attachmentPath,
                'status' => $initialStatus,
            ]);

            $this->smartDecisionService->evaluateSingle($leaveRequest, true);

            return $leaveRequest->refresh();
        }, 3);

        $this->dispatchLeaveSubmittedNotification($leaveRequest, $employee, $leaveType, $actor);

        return $leaveRequest;
    }

    /**
     * Cancel a pending or approved leave request.
     * If the request was already approved, refund the debited quota append-only
     * and release locked LEAVE: schedules from the Roster.
     *
     * @throws ValidationException
     */
    public function cancelRequest(
        LeaveRequest $leaveRequest,
        ?string $cancellationReason = null,
        ?User $actor = null
    ): LeaveRequest {
        if (! $leaveRequest->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_cannot_cancel_processed')],
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $cancellationReason, $actor): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->with(['leaveType' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_cannot_cancel_processed')],
                ]);
            }

            $oldStartDate = $lockedRequest->start_date instanceof CarbonInterface
                ? $lockedRequest->start_date->toDateString()
                : Carbon::parse($lockedRequest->start_date, 'Asia/Jakarta')->toDateString();
            $oldEndDate = $lockedRequest->end_date instanceof CarbonInterface
                ? $lockedRequest->end_date->toDateString()
                : Carbon::parse($lockedRequest->end_date, 'Asia/Jakarta')->toDateString();

            $cleanCancelReason = $cancellationReason !== null && trim($cancellationReason) !== ''
                ? trim($cancellationReason)
                : null;

            if ($lockedRequest->status === LeaveRequest::STATUS_APPROVED) {
                $leaveType = $lockedRequest->leaveType;

                if ($lockedRequest->ledger_entry_id !== null && $leaveType !== null && $leaveType->requires_quota) {
                    $refundNotes = $cleanCancelReason !== null
                        ? "Refund pembatalan cuti #{$lockedRequest->id}: {$cleanCancelReason}"
                        : "Refund pembatalan cuti #{$lockedRequest->id}";

                    $this->ledgerService->recordCancellationRefund(
                        $lockedEmployee,
                        $leaveType,
                        (int) $lockedRequest->total_days,
                        $lockedRequest,
                        $actor,
                        $refundNotes
                    );
                }

                $this->removeLeaveFromRoster($lockedEmployee, $oldStartDate, $oldEndDate, (int) $lockedRequest->id);
            }

            $lockedRequest->update([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'ledger_entry_id' => null,
                'cancelled_at' => Carbon::now('Asia/Jakarta'),
                'cancellation_reason' => $cleanCancelReason,
            ]);

            $this->recalculateOverlappingDepartmentRequests($lockedEmployee, $oldStartDate, $oldEndDate);

            return $lockedRequest->refresh();
        }, 3);
    }

    /**
     * Edit an existing pending or approved leave request.
     * If previously approved, refunds the old ledger debit, releases the old Roster lock,
     * resets approval status back to pending, and recalculates SPK SMART scores.
     *
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function updateRequest(
        LeaveRequest $leaveRequest,
        array $data,
        ?UploadedFile $attachment = null,
        ?User $actor = null
    ): LeaveRequest {
        if (! $leaveRequest->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => [__('leave.err_cannot_edit_processed')],
            ]);
        }

        $start = Carbon::parse($data['start_date'] ?? '', 'Asia/Jakarta')->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? '', 'Asia/Jakarta')->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'end_date' => [__('leave.err_invalid_date_range')],
            ]);
        }

        $newStartDateStr = $start->toDateString();
        $newEndDateStr = $end->toDateString();

        $newLeaveType = $this->resolveLeaveTypeFromPayload($data, (int) $leaveRequest->company_id);
        if ($newLeaveType === null || ! $newLeaveType->is_active) {
            throw ValidationException::withMessages([
                'leave_type_id' => [__('leave.err_invalid_leave_type')],
            ]);
        }

        $uploadedFile = $attachment ?? ($data['attachment'] ?? null);
        $hasExistingAttachment = $leaveRequest->attachment_path !== null && trim((string) $leaveRequest->attachment_path) !== '';

        if ($newLeaveType->requires_attachment && ! ($uploadedFile instanceof UploadedFile) && ! $hasExistingAttachment) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_attachment_required', ['type' => $newLeaveType->name])],
            ]);
        }

        $updatedRequest = DB::transaction(function () use (
            $leaveRequest,
            $newLeaveType,
            $newStartDateStr,
            $newEndDateStr,
            $data,
            $uploadedFile,
            $actor
        ): LeaveRequest {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest = LeaveRequest::withoutGlobalScopes()
                ->with(['leaveType' => fn ($q) => $q->withoutGlobalScopes()])
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->canBeEdited()) {
                throw ValidationException::withMessages([
                    'status' => [__('leave.err_cannot_edit_processed')],
                ]);
            }

            $oldStartDateStr = $lockedRequest->start_date instanceof CarbonInterface
                ? $lockedRequest->start_date->toDateString()
                : Carbon::parse($lockedRequest->start_date, 'Asia/Jakarta')->toDateString();
            $oldEndDateStr = $lockedRequest->end_date instanceof CarbonInterface
                ? $lockedRequest->end_date->toDateString()
                : Carbon::parse($lockedRequest->end_date, 'Asia/Jakarta')->toDateString();

            // 1. If previously approved, refund old ledger debit and release old Roster lock first
            if ($lockedRequest->status === LeaveRequest::STATUS_APPROVED) {
                $oldLeaveType = $lockedRequest->leaveType;

                if ($lockedRequest->ledger_entry_id !== null && $oldLeaveType !== null && $oldLeaveType->requires_quota) {
                    $this->ledgerService->recordCancellationRefund(
                        $lockedEmployee,
                        $oldLeaveType,
                        (int) $lockedRequest->total_days,
                        $lockedRequest,
                        $actor,
                        "Refund perubahan (edit) pengajuan cuti #{$lockedRequest->id}"
                    );
                }

                $lockedRequest->ledger_entry_id = null;
                $this->removeLeaveFromRoster($lockedEmployee, $oldStartDateStr, $oldEndDateStr, (int) $lockedRequest->id);
            }

            // 2. Calculate effective days for the new date range
            $calculation = $this->calculateEffectiveDays($lockedEmployee, $newStartDateStr, $newEndDateStr);
            $effectiveDays = (int) $calculation['effective_days'];

            if ($effectiveDays <= 0) {
                throw ValidationException::withMessages([
                    'start_date' => [__('leave.err_zero_effective_days')],
                ]);
            }

            // 3. Anti-Overlap Guard (excluding current request ID)
            $hasOverlap = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $lockedEmployee->company_id)
                ->where('employee_id', $lockedEmployee->id)
                ->where('id', '!=', $lockedRequest->id)
                ->whereIn('status', [
                    LeaveRequest::STATUS_PENDING_MANAGER,
                    LeaveRequest::STATUS_PENDING_HR,
                    LeaveRequest::STATUS_APPROVED,
                ])
                ->whereDate('start_date', '<=', $newEndDateStr)
                ->whereDate('end_date', '>=', $newStartDateStr)
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'start_date' => [__('leave.err_overlapping_request')],
                ]);
            }

            // 4. Quota & Reservation Guard (excluding current request ID from reserved days)
            if ($newLeaveType->requires_quota) {
                $quotaSummary = $this->getQuotaSummary($lockedEmployee, $newLeaveType, (int) $lockedRequest->id);

                if ($quotaSummary['available_balance'] < $effectiveDays) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => [__('leave.err_insufficient_quota', [
                            'type' => $newLeaveType->code,
                            'available' => (string) (int) $quotaSummary['available_balance'],
                            'requested' => (string) (int) $effectiveDays,
                        ])],
                    ]);
                }
            }

            // 5. Process private attachment if a new file is uploaded
            $attachmentPath = $lockedRequest->attachment_path;
            if ($uploadedFile instanceof UploadedFile) {
                $company = Company::withoutGlobalScopes()->find($lockedEmployee->company_id);
                $attachmentPath = $this->attachmentService->processAndStore(
                    $uploadedFile,
                    $company ?? (int) $lockedEmployee->company_id,
                    (string) $lockedEmployee->uuid
                );
            }

            // 6. Reset approval status and active approval timestamps
            $applicantUser = ($lockedEmployee->relationLoaded('user') ? $lockedEmployee->user : null)
                ?? ($lockedEmployee->user_id ? User::find($lockedEmployee->user_id) : null)
                ?? $actor;

            $resetStatus = ($applicantUser !== null && in_array($applicantUser->role, ['manager', 'admin', 'super_user', 'super_admin'], true))
                ? LeaveRequest::STATUS_PENDING_HR
                : LeaveRequest::STATUS_PENDING_MANAGER;

            $lockedRequest->update([
                'leave_type_id' => $newLeaveType->id,
                'start_date' => $newStartDateStr,
                'end_date' => $newEndDateStr,
                'total_days' => $effectiveDays,
                'reason' => trim((string) ($data['reason'] ?? '')),
                'attachment_path' => $attachmentPath,
                'status' => $resetStatus,
                'ledger_entry_id' => null,
                'manager_approved_by' => null,
                'manager_approved_at' => null,
                'hr_approved_by' => null,
                'hr_approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            // 7. Recalculate SPK SMART scores for this request and overlapping department requests
            $this->smartDecisionService->evaluateSingle($lockedRequest, true);

            if ($oldStartDateStr !== $newStartDateStr || $oldEndDateStr !== $newEndDateStr) {
                $this->recalculateOverlappingDepartmentRequests($lockedEmployee, $oldStartDateStr, $oldEndDateStr);
            }

            return $lockedRequest->refresh();
        }, 3);

        $employeeModel = Employee::withoutGlobalScopes()->with('user')->find($updatedRequest->employee_id);
        if ($employeeModel !== null) {
            $this->dispatchLeaveSubmittedNotification($updatedRequest, $employeeModel, $newLeaveType, $actor);
        }

        return $updatedRequest;
    }

    /**
     * Dispatch real-time Web Push & in-app notification to department managers or HR admins on leave submission/update.
     */
    private function dispatchLeaveSubmittedNotification(
        LeaveRequest $leaveRequest,
        Employee $employee,
        LeaveType $leaveType,
        ?User $actor = null
    ): void {
        try {
            $push = $this->webPushService ?? app(WebPushService::class);
            $applicantUser = $actor
                ?? ($employee->relationLoaded('user') ? $employee->user : null)
                ?? ($employee->user_id ? User::find($employee->user_id) : null);

            $applicantName = $applicantUser?->name ?? 'Staf';
            $startStr = $leaveRequest->start_date instanceof CarbonInterface
                ? $leaveRequest->start_date->toDateString()
                : Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->toDateString();
            $endStr = $leaveRequest->end_date instanceof CarbonInterface
                ? $leaveRequest->end_date->toDateString()
                : Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->toDateString();
            $daysStr = (string) (int) $leaveRequest->total_days;
            $actionUrl = route('leave.index', [], false);
            $meta = [
                'leave_request_id' => $leaveRequest->id,
                'leave_request_uuid' => $leaveRequest->uuid,
                'employee_id' => $employee->id,
            ];

            if ($leaveRequest->status === LeaveRequest::STATUS_PENDING_MANAGER) {
                $recipientIds = $push->resolveDepartmentManagersOrCompanyAdmins(
                    (int) $employee->company_id,
                    $employee->department_id,
                    $applicantUser?->id
                )->pluck('id')->all();

                if ($recipientIds !== []) {
                    SendWebPushNotificationJob::dispatch(
                        $recipientIds,
                        'leave_submitted',
                        __('pwa.notif_leave_submitted_title'),
                        __('pwa.notif_leave_submitted_body', [
                            'name' => $applicantName,
                            'type' => $leaveType->code,
                            'start' => $startStr,
                            'end' => $endStr,
                            'days' => $daysStr,
                        ]),
                        $actionUrl,
                        $meta
                    );
                }
            } elseif ($leaveRequest->status === LeaveRequest::STATUS_PENDING_HR) {
                $recipientIds = $push->resolveCompanyAdmins(
                    (int) $employee->company_id,
                    $applicantUser?->id
                )->pluck('id')->all();

                if ($recipientIds !== []) {
                    SendWebPushNotificationJob::dispatch(
                        $recipientIds,
                        'leave_submitted',
                        __('pwa.notif_leave_submitted_hr_title'),
                        __('pwa.notif_leave_submitted_hr_body', [
                            'name' => $applicantName,
                            'type' => $leaveType->code,
                            'start' => $startStr,
                            'end' => $endStr,
                            'days' => $daysStr,
                        ]),
                        $actionUrl,
                        $meta
                    );
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch leave submission notification: '.$e->getMessage());
        }
    }

    /**
     * Remove locked LEAVE: schedules belonging strictly to the given leaveRequestId across its date range.
     */
    private function removeLeaveFromRoster(Employee $employee, string $startDateStr, string $endDateStr, int $leaveRequestId): void
    {
        WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', '>=', $startDateStr)
            ->whereDate('schedule_date', '<=', $endDateStr)
            ->where(function ($q) use ($leaveRequestId): void {
                $q->where('notes', 'like', "LEAVE:%:{$leaveRequestId}|%")
                    ->orWhere('notes', 'like', "LEAVE:%:{$leaveRequestId}:%")
                    ->orWhere('notes', 'like', "LEAVE:%:{$leaveRequestId}");
            })
            ->delete();
    }

    /**
     * Recalculate SPK SMART scores for any remaining active requests in the same department
     * overlapping the given date window.
     */
    private function recalculateOverlappingDepartmentRequests(
        Employee $employee,
        string $startDateStr,
        string $endDateStr
    ): void {
        $this->smartDecisionService->recalculateOverlappingDepartmentRequests(
            $employee,
            $startDateStr,
            $endDateStr
        );
    }

    /**
     * Resolve LeaveType from flexible payload keys (leave_type, leave_type_id, or leave_type_code).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveLeaveTypeFromPayload(array $data, int $companyId): ?LeaveType
    {
        $raw = $data['leave_type'] ?? $data['leave_type_id'] ?? $data['leave_type_code'] ?? null;

        if ($raw instanceof LeaveType) {
            if ($raw->company_id !== null && (int) $raw->company_id !== $companyId) {
                return null;
            }

            return $raw;
        }

        if (is_numeric($raw)) {
            return LeaveType::withoutGlobalScopes()
                ->where(function ($q) use ($companyId): void {
                    $q->whereNull('company_id')
                        ->orWhere('company_id', $companyId);
                })
                ->find((int) $raw);
        }

        if (is_string($raw) && trim($raw) !== '') {
            return LeaveType::findByCode($raw, $companyId);
        }

        return null;
    }
}
