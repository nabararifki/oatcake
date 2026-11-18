<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LeaveLedgerService
{
    /**
     * Resolve a LeaveType instance from a model or code string.
     */
    public function resolveLeaveType(LeaveType|string $leaveType, ?int $companyId = null): LeaveType
    {
        if ($leaveType instanceof LeaveType) {
            return $leaveType;
        }

        $resolved = LeaveType::findByCode($leaveType, $companyId);

        if ($resolved === null) {
            throw new InvalidArgumentException("Leave type [{$leaveType}] was not found.");
        }

        return $resolved;
    }

    /**
     * Get the current active leave balance for an employee and leave type.
     */
    public function getBalance(Employee $employee, LeaveType|string $leaveType): int
    {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);

        $latest = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return 0;
        }

        return (int) round((float) $latest->balance_after);
    }

    /**
     * Record a positive annual/periodic leave accrual entry.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordAccrual(
        Employee $employee,
        LeaveType|string $leaveType = 'AL',
        int|float|null $amount = null,
        array $meta = []
    ): LeaveLedgerEntry {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);
        $effectiveQuota = $type->getEffectiveAnnualQuota((int) $employee->company_id);
        $quotaAmount = $amount !== null ? (int) round(abs((float) $amount)) : (int) ($effectiveQuota ?? 12);

        if ($quotaAmount <= 0) {
            throw new InvalidArgumentException('Accrual amount must be greater than zero.');
        }

        return DB::transaction(function () use ($employee, $type, $quotaAmount, $meta): LeaveLedgerEntry {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $accrualYear = isset($meta['accrual_year']) ? (int) $meta['accrual_year'] : null;

            if ($accrualYear !== null) {
                $existingAccrual = LeaveLedgerEntry::withoutGlobalScopes()
                    ->where('company_id', $lockedEmployee->company_id)
                    ->where('employee_id', $lockedEmployee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('transaction_type', LeaveLedgerEntry::TYPE_ACCRUAL)
                    ->where('accrual_year', $accrualYear)
                    ->first();

                if ($existingAccrual !== null) {
                    return $existingAccrual;
                }
            }

            $currentBalance = $this->getBalance($lockedEmployee, $type);
            $balanceAfter = (int) ($currentBalance + $quotaAmount);

            $effectiveValidityMonths = $type->getEffectiveValidityMonths((int) $lockedEmployee->company_id);

            if (array_key_exists('expires_at', $meta)) {
                $expiresAt = $meta['expires_at'] !== null
                    ? Carbon::parse($meta['expires_at'], 'Asia/Jakarta')->toDateString()
                    : null;
            } elseif ($effectiveValidityMonths === null) {
                $expiresAt = null;
            } elseif ($accrualYear !== null && $effectiveValidityMonths === 12) {
                $expiresAt = sprintf('%04d-12-31', $accrualYear);
            } else {
                $expiresAt = Carbon::now('Asia/Jakarta')
                    ->addMonths($effectiveValidityMonths)
                    ->toDateString();
            }

            return LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $type->id,
                'transaction_type' => LeaveLedgerEntry::TYPE_ACCRUAL,
                'amount' => $quotaAmount,
                'remaining_amount' => $quotaAmount,
                'balance_after' => $balanceAfter,
                'source_entry_id' => $meta['source_entry_id'] ?? null,
                'reference_type' => $meta['reference_type'] ?? 'annual_accrual',
                'reference_id' => $meta['reference_id'] ?? null,
                'accrual_year' => $accrualYear,
                'expires_at' => $expiresAt,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $meta['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record a positive credit entry (e.g., DP earned from public holiday attendance).
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordCredit(
        Employee $employee,
        LeaveType|string $leaveType = 'DP',
        int|float $amount = 1,
        array $meta = []
    ): LeaveLedgerEntry {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);
        $creditAmount = (int) round(abs((float) $amount));

        if ($creditAmount <= 0) {
            throw new InvalidArgumentException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($employee, $type, $creditAmount, $meta): LeaveLedgerEntry {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = $this->getBalance($lockedEmployee, $type);
            $balanceAfter = (int) ($currentBalance + $creditAmount);

            $effectiveValidityMonths = $type->getEffectiveValidityMonths((int) $lockedEmployee->company_id);

            if (array_key_exists('expires_at', $meta)) {
                $expiresAt = $meta['expires_at'] !== null
                    ? Carbon::parse($meta['expires_at'], 'Asia/Jakarta')->toDateString()
                    : null;
            } elseif ($effectiveValidityMonths !== null) {
                $expiresAt = Carbon::now('Asia/Jakarta')
                    ->addMonths($effectiveValidityMonths)
                    ->toDateString();
            } else {
                $expiresAt = null;
            }

            return LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $type->id,
                'transaction_type' => LeaveLedgerEntry::TYPE_CREDIT,
                'amount' => $creditAmount,
                'remaining_amount' => $creditAmount,
                'balance_after' => $balanceAfter,
                'source_entry_id' => $meta['source_entry_id'] ?? null,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
                'accrual_year' => $meta['accrual_year'] ?? null,
                'expires_at' => $expiresAt,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $meta['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record a debit entry and deplete active buckets using FIFO (First-Expiring, First-Out).
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordDebit(
        Employee $employee,
        LeaveType|string $leaveType,
        int|float $amount,
        array $meta = []
    ): LeaveLedgerEntry {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);
        $debitAmount = (int) round(abs((float) $amount));

        if ($debitAmount <= 0) {
            throw new InvalidArgumentException('Debit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($employee, $type, $debitAmount, $meta): LeaveLedgerEntry {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = $this->getBalance($lockedEmployee, $type);

            if ($currentBalance < $debitAmount) {
                throw new RuntimeException(
                    "Insufficient leave balance for {$type->code}. Available: {$currentBalance}, requested: {$debitAmount}."
                );
            }

            $todayStr = Carbon::today('Asia/Jakarta')->toDateString();

            $buckets = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $lockedEmployee->company_id)
                ->where('employee_id', $lockedEmployee->id)
                ->where('leave_type_id', $type->id)
                ->whereIn('transaction_type', [
                    LeaveLedgerEntry::TYPE_ACCRUAL,
                    LeaveLedgerEntry::TYPE_CREDIT,
                    LeaveLedgerEntry::TYPE_ADJUSTMENT,
                ])
                ->where('remaining_amount', '>', 0)
                ->where(function ($query) use ($todayStr): void {
                    $query->whereNull('expires_at')
                        ->orWhereDate('expires_at', '>=', $todayStr);
                })
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('expires_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $availableQuota = (int) round((float) $buckets->sum('remaining_amount'));
            if ($availableQuota < $debitAmount) {
                throw new RuntimeException(
                    "Insufficient non-expired leave quota for {$type->code}. Active available: {$availableQuota}, requested: {$debitAmount}."
                );
            }

            $remainingToDeplete = $debitAmount;
            $firstSourceBucketId = null;

            foreach ($buckets as $bucket) {
                if ($remainingToDeplete <= 0) {
                    break;
                }

                $bucketAvailable = (int) round((float) $bucket->remaining_amount);
                if ($bucketAvailable <= 0) {
                    continue;
                }

                if ($firstSourceBucketId === null) {
                    $firstSourceBucketId = $bucket->id;
                }

                $deduct = min($bucketAvailable, $remainingToDeplete);
                $bucket->remaining_amount = (int) ($bucketAvailable - $deduct);
                $bucket->save();

                $remainingToDeplete = (int) ($remainingToDeplete - $deduct);
            }

            $balanceAfter = (int) ($currentBalance - $debitAmount);

            return LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $type->id,
                'transaction_type' => LeaveLedgerEntry::TYPE_DEBIT,
                'amount' => -$debitAmount,
                'remaining_amount' => 0,
                'balance_after' => $balanceAfter,
                'source_entry_id' => $meta['source_entry_id'] ?? $firstSourceBucketId,
                'reference_type' => $meta['reference_type'] ?? 'leave_request',
                'reference_id' => $meta['reference_id'] ?? null,
                'accrual_year' => null,
                'expires_at' => null,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $meta['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record a positive or negative administrative adjustment entry.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordAdjustment(
        Employee $employee,
        LeaveType|string $leaveType,
        int|float $amount,
        array $meta = []
    ): LeaveLedgerEntry {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);
        $signedAmount = (int) round((float) $amount);

        if ($signedAmount === 0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        return DB::transaction(function () use ($employee, $type, $signedAmount, $meta): LeaveLedgerEntry {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = $this->getBalance($lockedEmployee, $type);
            $balanceAfter = (int) ($currentBalance + $signedAmount);

            if ($balanceAfter < 0) {
                throw new RuntimeException(
                    "Adjustment of {$signedAmount} would result in a negative balance ({$balanceAfter}) for {$type->code}."
                );
            }

            $remainingAmount = 0;
            $expiresAt = null;
            $firstSourceBucketId = null;

            if ($signedAmount > 0) {
                $remainingAmount = $signedAmount;
                if (array_key_exists('expires_at', $meta)) {
                    $expiresAt = $meta['expires_at'] !== null
                        ? Carbon::parse($meta['expires_at'], 'Asia/Jakarta')->toDateString()
                        : null;
                } elseif ($type->validity_months !== null) {
                    $expiresAt = Carbon::now('Asia/Jakarta')
                        ->addMonths((int) $type->validity_months)
                        ->toDateString();
                }
            } else {
                // Deplete active buckets FIFO for negative adjustment
                $toDeplete = abs($signedAmount);
                $todayStr = Carbon::today('Asia/Jakarta')->toDateString();

                $buckets = LeaveLedgerEntry::withoutGlobalScopes()
                    ->where('company_id', $lockedEmployee->company_id)
                    ->where('employee_id', $lockedEmployee->id)
                    ->where('leave_type_id', $type->id)
                    ->whereIn('transaction_type', [
                        LeaveLedgerEntry::TYPE_ACCRUAL,
                        LeaveLedgerEntry::TYPE_CREDIT,
                        LeaveLedgerEntry::TYPE_ADJUSTMENT,
                    ])
                    ->where('remaining_amount', '>', 0)
                    ->where(function ($query) use ($todayStr): void {
                        $query->whereNull('expires_at')
                            ->orWhereDate('expires_at', '>=', $todayStr);
                    })
                    ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderBy('expires_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($buckets as $bucket) {
                    if ($toDeplete <= 0) {
                        break;
                    }

                    $bucketAvailable = (int) round((float) $bucket->remaining_amount);
                    if ($bucketAvailable <= 0) {
                        continue;
                    }

                    if ($firstSourceBucketId === null) {
                        $firstSourceBucketId = $bucket->id;
                    }

                    $deduct = min($bucketAvailable, $toDeplete);
                    $bucket->remaining_amount = (int) ($bucketAvailable - $deduct);
                    $bucket->save();

                    $toDeplete = (int) ($toDeplete - $deduct);
                }
            }

            return LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $type->id,
                'transaction_type' => LeaveLedgerEntry::TYPE_ADJUSTMENT,
                'amount' => $signedAmount,
                'remaining_amount' => $remainingAmount,
                'balance_after' => $balanceAfter,
                'source_entry_id' => $meta['source_entry_id'] ?? $firstSourceBucketId,
                'reference_type' => $meta['reference_type'] ?? 'manual_adjustment',
                'reference_id' => $meta['reference_id'] ?? null,
                'accrual_year' => null,
                'expires_at' => $expiresAt,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $meta['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record an append-only positive refund (reversal) entry when an approved leave request
     * is cancelled or edited, restoring active bucket quota without mutating historical debit rows.
     */
    public function recordCancellationRefund(
        Employee $employee,
        LeaveType|string $leaveType,
        int|float $amount,
        LeaveRequest $leaveRequest,
        ?User $actor = null,
        ?string $notes = null
    ): LeaveLedgerEntry {
        $type = $this->resolveLeaveType($leaveType, (int) $employee->company_id);
        $refundAmount = (int) round(abs((float) $amount));

        if ($refundAmount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $employee,
            $type,
            $refundAmount,
            $leaveRequest,
            $actor,
            $notes
        ): LeaveLedgerEntry {
            $lockedEmployee = Employee::withoutGlobalScopes()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = $this->getBalance($lockedEmployee, $type);
            $balanceAfter = (int) ($currentBalance + $refundAmount);

            $expiresAt = null;
            $todayStr = Carbon::today('Asia/Jakarta')->toDateString();

            if ($leaveRequest->ledger_entry_id !== null) {
                $debitEntry = LeaveLedgerEntry::withoutGlobalScopes()->find($leaveRequest->ledger_entry_id);
                if ($debitEntry !== null && $debitEntry->source_entry_id !== null) {
                    $sourceBucket = LeaveLedgerEntry::withoutGlobalScopes()->find($debitEntry->source_entry_id);
                    if ($sourceBucket !== null && $sourceBucket->expires_at !== null) {
                        $sourceExpiry = Carbon::parse($sourceBucket->expires_at, 'Asia/Jakarta')->toDateString();
                        if ($sourceExpiry >= $todayStr) {
                            $expiresAt = $sourceExpiry;
                        }
                    }
                }
            }

            if ($expiresAt === null) {
                $effectiveValidityMonths = $type->getEffectiveValidityMonths((int) $lockedEmployee->company_id);
                if ($effectiveValidityMonths !== null) {
                    $expiresAt = Carbon::now('Asia/Jakarta')
                        ->addMonths($effectiveValidityMonths)
                        ->toDateString();
                }
            }

            return LeaveLedgerEntry::withoutGlobalScopes()->create([
                'company_id' => $lockedEmployee->company_id,
                'employee_id' => $lockedEmployee->id,
                'leave_type_id' => $type->id,
                'transaction_type' => LeaveLedgerEntry::TYPE_CREDIT,
                'amount' => $refundAmount,
                'remaining_amount' => $refundAmount,
                'balance_after' => $balanceAfter,
                'source_entry_id' => $leaveRequest->ledger_entry_id,
                'reference_type' => 'leave_cancellation',
                'reference_id' => $leaveRequest->id,
                'accrual_year' => null,
                'expires_at' => $expiresAt,
                'notes' => $notes ?? "Refund pembatalan/perubahan cuti #{$leaveRequest->id}",
                'created_by' => $actor?->id ?? auth()->id(),
            ]);
        });
    }
}
