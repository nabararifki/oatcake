<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccrueAnnualLeaveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leave:accrue-annual {--year= : Tahun akrual, default tahun berjalan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically accrue annual leave (AL) quota for eligible active employees idempotently';

    /**
     * Execute the console command.
     */
    public function handle(LeaveLedgerService $ledgerService): int
    {
        $yearOption = $this->option('year');
        $year = $yearOption !== null && $yearOption !== ''
            ? (int) $yearOption
            : (int) Carbon::now('Asia/Jakarta')->year;

        $expiresAt = sprintf('%04d-12-31', $year);

        $activeEmployees = Employee::withoutGlobalScopes()
            ->active()
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->where(function ($query) use ($expiresAt): void {
                $query->whereNull('join_date')
                    ->orWhereDate('join_date', '<=', $expiresAt);
            })
            ->orderBy('id')
            ->get();

        $accruedCount = 0;
        $skippedCount = 0;

        foreach ($activeEmployees as $employee) {
            $alType = LeaveType::findByCode('AL', (int) $employee->company_id);
            if ($alType === null || ! $alType->is_active) {
                $skippedCount++;
                continue;
            }

            $alreadyAccrued = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $alType->id)
                ->where('transaction_type', LeaveLedgerEntry::TYPE_ACCRUAL)
                ->where('accrual_year', $year)
                ->exists();

            if ($alreadyAccrued) {
                $skippedCount++;
                continue;
            }

            $quota = (int) ($alType->getEffectiveAnnualQuota((int) $employee->company_id) ?? 12);
            $effectiveValidityMonths = $alType->getEffectiveValidityMonths((int) $employee->company_id);

            $entryExpiresAt = match (true) {
                $effectiveValidityMonths === null => null,
                $effectiveValidityMonths === 12 => $expiresAt,
                default => Carbon::now('Asia/Jakarta')->addMonths($effectiveValidityMonths)->toDateString(),
            };

            $ledgerService->recordAccrual($employee, $alType, $quota, [
                'accrual_year' => $year,
                'expires_at' => $entryExpiresAt,
                'reference_type' => 'annual_accrual',
                'notes' => "Automatic annual leave accrual for year {$year}",
            ]);

            $accruedCount++;
        }

        $this->info("Annual leave accrual ({$year}): {$accruedCount} accrued, {$skippedCount} skipped.");

        return self::SUCCESS;
    }
}
