<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use App\Services\SmartDecisionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class OptimizeCohostingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oatcake:optimize-cohosting
                            {--benchmark : Jalankan benchmark kueri SQL, latensi, dan puncak memori}
                            {--write-doc : Perbarui berkas docs/COHOSTING_OPTIMIZATION.md jika diperlukan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnosa kesehatan runtime co-hosting, verifikasi indeks komposit, dan benchmark kueri/memori OATCAKE';

    public function __construct(
        private readonly LeaveLedgerService $leaveLedgerService,
        private readonly SmartDecisionService $smartDecisionService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== OATCAKE Co-Hosting Resource Diagnostics & Optimizer ===');

        // 1. Runtime & Configuration Audit
        $opcacheEnabled = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));
        $gdWebpSupported = function_exists('imagewebp');

        $this->table(['Komponen Runtime', 'Nilai Aktif'], [
            ['PHP Version', PHP_VERSION],
            ['Memory Limit', (string) ini_get('memory_limit')],
            ['OPcache Active', $opcacheEnabled ? 'Enabled' : 'Disabled / CLI'],
            ['GD WebP Support', $gdWebpSupported ? 'Enabled' : 'Fallback JPEG'],
            ['DB Driver', DB::connection()->getDriverName()],
            ['Cache Store', (string) config('cache.default')],
            ['Queue Connection', (string) config('queue.default')],
            ['Peak Memory (Start)', sprintf('%.2f MB', memory_get_peak_usage(true) / 1048576)],
        ]);

        // 2. Composite Index Integrity Check
        $expectedIndexes = [
            ['work_schedules', 'ws_company_date_status_idx'],
            ['work_schedules', 'ws_emp_date_status_idx'],
            ['attendances', 'att_company_status_clockin_idx'],
            ['attendances', 'att_emp_status_clockin_idx'],
            ['leave_requests', 'lr_company_status_dates_idx'],
        ];

        $indexRows = [];
        foreach ($expectedIndexes as [$table, $indexName]) {
            $present = Schema::hasTable($table) && Schema::hasIndex($table, $indexName);
            $indexRows[] = [$table, $indexName, $present ? 'OK' : 'MISSING'];
        }
        $this->table(['Tabel', 'Indeks Komposit', 'Status'], $indexRows);

        // 3. Tenant Private Storage Health
        $companies = Schema::hasTable('companies')
            ? Company::withoutGlobalScopes()->orderBy('id')->get()
            : collect();

        if ($companies->isNotEmpty()) {
            $storageRows = $companies->map(function (Company $company): array {
                $usedMb = $company->getStorageUsageMb();
                $limitMb = (int) ($company->storage_limit_mb ?? 1024);
                $pct = $limitMb > 0 ? round(($usedMb / $limitMb) * 100, 2) : 0.0;

                return [
                    $company->code,
                    $company->name,
                    sprintf('%.2f MB', $usedMb),
                    sprintf('%d MB (%.2f%%)', $limitMb, $pct),
                    sprintf('%d bln', (int) ($company->photo_retention_months ?? 3)),
                ];
            })->all();

            $this->table(['Kode Tenant', 'Nama Properti', 'Terpakai', 'Kuota Batas', 'Retensi Foto'], $storageRows);
        }

        // 4. Non-destructive Query & Memory Benchmark
        $benchmarkResults = $this->runBenchmarks($companies->first());
        $this->table(['Operasi Benchmark', 'Kueri SQL', 'Latensi (ms)', 'Peak Memori'], $benchmarkResults);

        // 5. Ensure Co-Hosting Documentation Exists
        $docPath = base_path('docs/COHOSTING_OPTIMIZATION.md');
        if (! File::exists($docPath) || $this->option('write-doc')) {
            File::ensureDirectoryExists(dirname($docPath));
            if (! File::exists($docPath)) {
                File::put($docPath, $this->defaultDocumentationStub());
            }
            $this->info('Panduan optimasi co-hosting tersedia di: '.$docPath);
        }

        return self::SUCCESS;
    }

    /**
     * Execute non-destructive read benchmarks on core query paths.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private function runBenchmarks(?Company $company): array
    {
        $rows = [];
        $conn = DB::connection();

        if ($company === null) {
            return [['No tenant data available', 0, '0.00', sprintf('%.2f MB', memory_get_peak_usage(true) / 1048576)]];
        }

        $now = Carbon::now('Asia/Jakarta');
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth = $now->copy()->endOfMonth()->toDateString();

        // Benchmark 1: Roster 31-day matrix eager-loaded read
        $rows[] = $this->measureOperation($conn, 'Roster 31-Day Matrix Read', function () use ($company, $startMonth, $endMonth): void {
            Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->active()
                ->with([
                    'user',
                    'tags' => fn ($q) => $q->withoutGlobalScopes(),
                    'workSchedules' => fn ($q) => $q->withoutGlobalScopes()
                        ->where('company_id', $company->id)
                        ->betweenDates($startMonth, $endMonth),
                ])
                ->get();

            WorkSchedule::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->betweenDates($startMonth, $endMonth)
                ->count();
        });

        // Benchmark 2: SPK SMART Department Weight Batch Load & Evaluation
        $rows[] = $this->measureOperation($conn, 'SPK SMART Weights & Pending Analysis', function () use ($company): void {
            $depts = Department::withoutGlobalScopes()->where('company_id', $company->id)->get();
            $this->smartDecisionService->getOrCreateWeightsForDepartments($depts);

            $pendingLeave = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('status', [LeaveRequest::STATUS_PENDING_MANAGER, LeaveRequest::STATUS_PENDING_HR])
                ->first();

            if ($pendingLeave !== null) {
                $this->smartDecisionService->evaluateComparativeForRequest($pendingLeave);
            }
        });

        // Benchmark 3: Leave Ledger & PWA 14-day Offline Snapshot Read
        $rows[] = $this->measureOperation($conn, 'Ledger Balance & PWA 14-Day Snapshot', function () use ($company, $now): void {
            $sampleEmp = Employee::withoutGlobalScopes()->where('company_id', $company->id)->active()->first();
            if ($sampleEmp !== null) {
                $this->leaveLedgerService->getBalance($sampleEmp, 'AL');
                $this->leaveLedgerService->getBalance($sampleEmp, 'DP');

                WorkSchedule::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('employee_id', $sampleEmp->id)
                    ->published()
                    ->whereBetween('schedule_date', [$now->toDateString(), $now->copy()->addDays(13)->toDateString()])
                    ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
                    ->get();
            }
        });

        return $rows;
    }

    /**
     * Measure query count, elapsed time (ms), and peak memory for a closure.
     *
     * @return array{0: string, 1: int, 2: string, 3: string}
     */
    private function measureOperation($conn, string $label, callable $fn): array
    {
        $conn->flushQueryLog();
        $conn->enableQueryLog();
        $t0 = hrtime(true);

        $fn();

        $elapsedMs = (hrtime(true) - $t0) / 1_000_000;
        $queryCount = count($conn->getQueryLog());
        $conn->disableQueryLog();

        return [
            $label,
            $queryCount,
            sprintf('%.2f', $elapsedMs),
            sprintf('%.2f MB', memory_get_peak_usage(true) / 1048576),
        ];
    }

    private function defaultDocumentationStub(): string
    {
        return "# OATCAKE Co-Hosting Optimization & Tuning Guide\n\nGenerated by `php artisan oatcake:optimize-cohosting`.\n";
    }
}
