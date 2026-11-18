<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DpCommitService
{
    /**
     * Minimum actual work duration (in hours) required on a public holiday to earn +1 DP.
     */
    public const MIN_WORK_HOURS_FOR_DP = 4.0;

    public function __construct(
        private readonly LeaveLedgerService $ledgerService
    ) {}

    /**
     * Attempt to execute Phase 2 DP Commit from a public holiday attendance record.
     * Strictly idempotent: each qualifying attendance record can commit at most once.
     */
    public function attemptCommitFromAttendance(Attendance $attendance, ?User $actor = null): ?LeaveLedgerEntry
    {
        if (! $this->meetsEligibilityCriteria($attendance)) {
            return null;
        }

        return DB::transaction(function () use ($attendance, $actor): ?LeaveLedgerEntry {
            $lockedAttendance = Attendance::withoutGlobalScopes()
                ->whereKey($attendance->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttendance === null || ! $this->meetsEligibilityCriteria($lockedAttendance)) {
                return null;
            }

            // Extra idempotency guard: check if a credit entry referencing this attendance already exists
            $existingEntry = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $lockedAttendance->company_id)
                ->where('employee_id', $lockedAttendance->employee_id)
                ->where('reference_type', 'attendance')
                ->where('reference_id', $lockedAttendance->id)
                ->first();

            if ($existingEntry !== null) {
                $lockedAttendance->update([
                    'dp_committed_at' => $lockedAttendance->dp_committed_at ?? Carbon::now('Asia/Jakarta'),
                    'dp_ledger_entry_id' => $existingEntry->id,
                ]);
                $attendance->refresh();

                return $existingEntry;
            }

            $employee = Employee::withoutGlobalScopes()->findOrFail($lockedAttendance->employee_id);
            $dpLeaveType = LeaveType::findByCode('DP', (int) $lockedAttendance->company_id);

            $validityMonths = $dpLeaveType !== null
                ? $dpLeaveType->getEffectiveValidityMonths((int) $lockedAttendance->company_id)
                : 3;

            $expiresAt = $validityMonths !== null
                ? Carbon::parse($lockedAttendance->clock_in_at)
                    ->copy()
                    ->setTimezone('Asia/Jakarta')
                    ->addMonths($validityMonths)
                    ->toDateString()
                : null;

            $entry = $this->ledgerService->recordCredit(
                $employee,
                $dpLeaveType ?? 'DP',
                1,
                [
                    'reference_type' => 'attendance',
                    'reference_id' => $lockedAttendance->id,
                    'expires_at' => $expiresAt,
                    'notes' => 'Two-Phase DP Commit from public holiday attendance #' . $lockedAttendance->id,
                    'created_by' => $actor?->id ?? auth()->id(),
                ]
            );

            $lockedAttendance->update([
                'dp_committed_at' => Carbon::now('Asia/Jakarta'),
                'dp_ledger_entry_id' => $entry->id,
            ]);

            $attendance->refresh();

            return $entry;
        });
    }

    /**
     * Validate all 5 Phase-2 DP commit conditions.
     */
    private function meetsEligibilityCriteria(Attendance $attendance): bool
    {
        if ($attendance->is_provisional_dp !== true) {
            return false;
        }

        if ($attendance->dp_committed_at !== null || $attendance->dp_ledger_entry_id !== null) {
            return false;
        }

        if ($attendance->clock_in_at === null || $attendance->clock_out_at === null) {
            return false;
        }

        if (! in_array($attendance->status, ['on_time', 'late', 'early_leave'], true)) {
            return false;
        }

        if ($attendance->getWorkDurationInHours() < self::MIN_WORK_HOURS_FOR_DP) {
            return false;
        }

        return true;
    }
}
