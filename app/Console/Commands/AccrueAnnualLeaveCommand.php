<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $accruedCount = 0;
        $skippedCount = 0;

        Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($companies) use ($ledgerService, $year, $expiresAt, &$accruedCount, &$skippedCount): void {
                foreach ($companies as $company) {
                    try {
                        $alType = LeaveType::findByCode('AL', (int) $company->id);
                        if ($alType === null || ! $alType->is_active) {
                            continue;
                        }

                        $quota = (int) ($alType->getEffectiveAnnualQuota((int) $company->id) ?? 12);
                        if ($quota <= 0) {
                            Log::warning('Skipping annual leave accrual for company with non-positive AL quota.', [
                                'company_id' => $company->id,
                                'quota' => $quota,
                            ]);
                            continue;
                        }

                        $effectiveValidityMonths = $alType->getEffectiveValidityMonths((int) $company->id);
                        $entryExpiresAt = match (true) {
                            $effectiveValidityMonths === null => null,
                            $effectiveValidityMonths === 12 => $expiresAt,
                            default => Carbon::now('Asia/Jakarta')->addMonths($effectiveValidityMonths)->toDateString(),
                        };

                        Employee::withoutGlobalScopes()
                            ->where('company_id', $company->id)
                            ->active()
                            ->where(function ($query) use ($expiresAt): void {
                                $query->whereNull('join_date')
                                    ->orWhereDate('join_date', '<=', $expiresAt);
                            })
                            ->orderBy('id')
                            ->chunkById(100, function ($employees) use (
                                $company,
                                $alType,
                                $quota,
                                $entryExpiresAt,
                                $year,
                                $ledgerService,
                                &$accruedCount,
                                &$skippedCount
                            ): void {
                                $employeeIds = $employees->pluck('id')->all();
                                $alreadyAccruedIds = LeaveLedgerEntry::withoutGlobalScopes()
                                    ->where('company_id', $company->id)
                                    ->whereIn('employee_id', $employeeIds)
                                    ->where('leave_type_id', $alType->id)
                                    ->where('transaction_type', LeaveLedgerEntry::TYPE_ACCRUAL)
                                    ->where('accrual_year', $year)
                                    ->pluck('employee_id')
                                    ->flip()
                                    ->all();

                                foreach ($employees as $employee) {
                                    if (isset($alreadyAccruedIds[$employee->id])) {
                                        $skippedCount++;
                                        continue;
                                    }

                                    try {
                                        $ledgerService->recordAccrual($employee, $alType, $quota, [
                                            'accrual_year' => $year,
                                            'expires_at' => $entryExpiresAt,
                                            'reference_type' => 'annual_accrual',
                                            'notes' => "Automatic annual leave accrual for year {$year}",
                                        ]);

                                        $accruedCount++;
                                    } catch (Throwable $e) {
                                        $skippedCount++;
                                        Log::warning('Failed annual leave accrual for employee.', [
                                            'company_id' => $company->id,
                                            'employee_id' => $employee->id,
                                            'error' => $e->getMessage(),
                                        ]);
                                    }
                                }
                            });
                    } catch (Throwable $e) {
                        Log::warning('Failed annual leave accrual batch for company.', [
                            'company_id' => $company->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Annual leave accrual ({$year}): {$accruedCount} accrued, {$skippedCount} skipped.");

        return self::SUCCESS;
    }
}
