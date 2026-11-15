<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredLeaveBalancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:prune-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep expired leave and DP quota buckets and record negative expired ledger entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $todayStr = Carbon::today('Asia/Jakarta')->toDateString();

        $expiredBuckets = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $todayStr)
            ->orderBy('expires_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $sweptCount = 0;
        $totalExpiredDays = 0.0;

        foreach ($expiredBuckets as $bucket) {
            DB::transaction(function () use ($bucket, &$sweptCount, &$totalExpiredDays): void {
                $lockedEmployee = Employee::withoutGlobalScopes()
                    ->whereKey($bucket->employee_id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedEmployee === null) {
                    return;
                }

                $lockedBucket = LeaveLedgerEntry::withoutGlobalScopes()
                    ->whereKey($bucket->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedBucket === null || (float) $lockedBucket->remaining_amount <= 0) {
                    return;
                }

                $expiredAmount = round((float) $lockedBucket->remaining_amount, 2);

                $latestEntry = LeaveLedgerEntry::withoutGlobalScopes()
                    ->where('company_id', $lockedBucket->company_id)
                    ->where('employee_id', $lockedBucket->employee_id)
                    ->where('leave_type_id', $lockedBucket->leave_type_id)
                    ->orderByDesc('id')
                    ->first();

                $currentBalance = $latestEntry !== null ? round((float) $latestEntry->balance_after, 2) : 0.0;
                $balanceAfter = max(0.0, round($currentBalance - $expiredAmount, 2));

                $lockedBucket->remaining_amount = 0;
                $lockedBucket->save();

                LeaveLedgerEntry::withoutGlobalScopes()->create([
                    'company_id' => $lockedBucket->company_id,
                    'employee_id' => $lockedBucket->employee_id,
                    'leave_type_id' => $lockedBucket->leave_type_id,
                    'transaction_type' => LeaveLedgerEntry::TYPE_EXPIRED,
                    'amount' => -$expiredAmount,
                    'remaining_amount' => 0,
                    'balance_after' => $balanceAfter,
                    'source_entry_id' => $lockedBucket->id,
                    'reference_type' => 'expiry_sweep',
                    'reference_id' => $lockedBucket->id,
                    'accrual_year' => null,
                    'expires_at' => null,
                    'notes' => "Automated expiry sweep for bucket #{$lockedBucket->id} (expired on {$lockedBucket->expires_at?->toDateString()})",
                    'created_by' => null,
                ]);

                $sweptCount++;
                $totalExpiredDays = round($totalExpiredDays + $expiredAmount, 2);
            });
        }

        $this->info("Swept {$sweptCount} expired leave buckets ({$totalExpiredDays} days total).");

        return self::SUCCESS;
    }
}
