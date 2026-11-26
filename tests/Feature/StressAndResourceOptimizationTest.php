<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AuditTrailService;
use App\Services\LeaveLedgerService;
use App\Services\SmartDecisionService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StressAndResourceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disableQueryLog();
        parent::tearDown();
    }

    /**
     * Skenario 1: Strict Query Budget (<= 15 kueri) untuk matriks /roster 31 hari dengan 25 staf (> 500 jadwal).
     */
    public function test_scenario_1_roster_matrix_with_25_staff_and_over_500_schedules_runs_within_15_queries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $hkDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'HK')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MO')->firstOrFail();
        $shiftAF = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'AF')->firstOrFail();
        $alType = LeaveType::findByCode('AL', $company->id);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'admin_hr',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $scheduleRows = [];
        $nowStr = Carbon::now('Asia/Jakarta')->toDateTimeString();

        for ($i = 1; $i <= 25; $i++) {
            $u = User::factory()->create([
                'company_id' => $company->id,
                'role' => 'user',
                'is_active' => true,
            ]);
            $emp = Employee::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'user_id' => $u->id,
                'department_id' => ($i % 2 === 0) ? $foDept->id : $hkDept->id,
                'position' => 'Operational Staff '.$i,
                'join_date' => '2025-01-01',
                'status' => 'active',
            ]);

            // 22 jadwal kerja per karyawan (25 * 22 = 550 baris work_schedules)
            for ($day = 1; $day <= 22; $day++) {
                $scheduleRows[] = [
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'company_id' => $company->id,
                    'employee_id' => $emp->id,
                    'shift_id' => ($day % 2 === 0) ? $shiftMO->id : $shiftAF->id,
                    'schedule_date' => sprintf('2026-11-%02d', $day),
                    'status' => 'published',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }

            if ($i <= 5) {
                LeaveRequest::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'employee_id' => $emp->id,
                    'leave_type_id' => $alType->id,
                    'start_date' => '2026-11-25',
                    'end_date' => '2026-11-26',
                    'total_days' => 2.0,
                    'reason' => 'Cuti disetujui '.$i,
                    'status' => LeaveRequest::STATUS_APPROVED,
                ]);
            }
        }

        WorkSchedule::insert($scheduleRows);
        $this->assertGreaterThan(500, WorkSchedule::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $conn = DB::connection();
        $conn->flushQueryLog();
        $conn->enableQueryLog();

        $response = $this->actingAs($admin)->get('/roster?month=11&year=2026');

        $queries = $conn->getQueryLog();
        $conn->disableQueryLog();

        $response->assertOk();
        $this->assertLessThanOrEqual(
            15,
            count($queries),
            'GET /roster exceeded query budget ('.count($queries).' queries executed).'
        );
    }

    /**
     * Skenario 2: Strict Query Budget (<= 15 kueri) untuk /leave, /attendance, dan /audit-logs.
     */
    public function test_scenario_2_leave_attendance_and_audit_pages_stay_within_15_query_budget(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MO')->firstOrFail();
        $alType = LeaveType::findByCode('AL', $company->id);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'admin_hr',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $auditService = app(AuditTrailService::class);

        for ($i = 1; $i <= 15; $i++) {
            $u = User::factory()->create(['company_id' => $company->id, 'role' => 'user', 'is_active' => true]);
            $emp = Employee::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'user_id' => $u->id,
                'department_id' => $foDept->id,
                'position' => 'Agent '.$i,
                'join_date' => '2025-01-01',
                'status' => 'active',
            ]);

            $ws = WorkSchedule::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'employee_id' => $emp->id,
                'shift_id' => $shiftMO->id,
                'schedule_date' => '2026-11-18',
                'status' => 'published',
            ]);

            Attendance::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'employee_id' => $emp->id,
                'work_schedule_id' => $ws->id,
                'clock_in_at' => Carbon::create(2026, 11, 18, 6, 55, 0, 'Asia/Jakarta'),
                'status' => 'on_time',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'is_outside_geofence' => false,
            ]);

            LeaveRequest::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'employee_id' => $emp->id,
                'leave_type_id' => $alType->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-21',
                'total_days' => 2.0,
                'reason' => 'Pengajuan '.$i,
                'status' => LeaveRequest::STATUS_PENDING_MANAGER,
            ]);

            $auditService->record(
                'updated',
                'employee',
                ['position' => 'Junior'],
                ['position' => 'Agent '.$i],
                'Audit stress entry '.$i,
                [],
                $admin,
                $company->id
            );
        }

        $conn = DB::connection();

        foreach (['/leave', '/attendance', '/audit-logs'] as $uri) {
            $conn->flushQueryLog();
            $conn->enableQueryLog();

            $resp = $this->actingAs($admin)->get($uri);

            $queries = $conn->getQueryLog();
            $conn->disableQueryLog();

            $resp->assertOk();
            $this->assertLessThanOrEqual(
                15,
                count($queries),
                "GET {$uri} exceeded query budget (".count($queries).' queries executed).'
            );
        }
    }

    /**
     * Skenario 3: Stress Test lonjakan 30 swafoto Clock-In beruntun pada jam pergantian shift & batas delta RAM < 64 MB.
     */
    public function test_scenario_3_burst_30_shift_change_selfie_clock_ins_stay_under_webp_and_memory_ceiling(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 7, 2, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MO')->firstOrFail();

        $staffUsers = [];
        for ($i = 1; $i <= 30; $i++) {
            $u = User::factory()->create(['company_id' => $company->id, 'role' => 'user', 'is_active' => true]);
            $emp = Employee::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'user_id' => $u->id,
                'department_id' => $foDept->id,
                'position' => 'Staff '.$i,
                'join_date' => '2025-01-01',
                'status' => 'active',
            ]);
            WorkSchedule::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'employee_id' => $emp->id,
                'shift_id' => $shiftMO->id,
                'schedule_date' => '2026-11-18',
                'status' => 'published',
            ]);
            $staffUsers[] = $u;
        }

        $memBefore = memory_get_usage(true);

        foreach ($staffUsers as $idx => $staffUser) {
            $photo = UploadedFile::fake()->image("shift_selfie_{$idx}.jpg", 1280, 960);

            $resp = $this->actingAs($staffUser)->postJson('/attendance/clock-in', [
                'latitude' => -7.9048250,
                'longitude' => 110.0572110,
                'photo' => $photo,
            ]);

            $resp->assertOk()->assertJson(['success' => true]);
            $storedPath = $resp->json('data.clock_in_photo_path');
            $this->assertNotEmpty($storedPath);
            Storage::disk('local')->assertExists($storedPath);
            $this->assertLessThanOrEqual(256000, Storage::disk('local')->size($storedPath));
        }

        $memAfter = memory_get_usage(true);
        $memoryDeltaMb = ($memAfter - $memBefore) / 1048576;

        $this->assertSame(30, Attendance::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertLessThan(64.0, $memoryDeltaMb, "Memory delta exceeded 64 MB ceiling ({$memoryDeltaMb} MB).");
    }

    /**
     * Skenario 4: Benchmark evaluasi komparatif SPK SMART Mode A (10 kandidat bentrok serentak < 500 ms).
     */
    public function test_scenario_4_smart_mode_a_comparative_ranking_for_10_candidates_completes_under_500ms(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $alType = LeaveType::findByCode('AL', $company->id);
        $ledgerService = app(LeaveLedgerService::class);

        $requests = [];
        for ($i = 1; $i <= 10; $i++) {
            $u = User::factory()->create(['company_id' => $company->id, 'role' => 'user', 'is_active' => true]);
            $emp = Employee::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'user_id' => $u->id,
                'department_id' => $foDept->id,
                'position' => 'FO Agent '.$i,
                'join_date' => '2024-01-01',
                'status' => 'active',
            ]);

            $ledgerService->recordAccrual($emp, $alType, 12.0, [
                'accrual_year' => 2026,
                'expires_at' => Carbon::today('Asia/Jakarta')->addDays(10 + ($i * 5))->toDateString(),
            ]);

            $requests[] = LeaveRequest::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'employee_id' => $emp->id,
                'leave_type_id' => $alType->id,
                'start_date' => '2026-11-22',
                'end_date' => '2026-11-23',
                'total_days' => 2.0,
                'reason' => 'Bentrok kandidat '.$i,
                'status' => LeaveRequest::STATUS_PENDING_MANAGER,
            ]);
        }

        $smartService = app(SmartDecisionService::class);

        $t0 = hrtime(true);
        $analysis = $smartService->evaluateComparativeForRequest($requests[0]);
        $elapsedMs = (hrtime(true) - $t0) / 1_000_000;

        $this->assertSame('A', $analysis['mode']);
        $this->assertCount(10, $analysis['candidates']);
        $this->assertSame(range(1, 10), array_column($analysis['candidates'], 'rank'));
        $this->assertLessThan(500.0, $elapsedMs, "Mode A comparative analysis took {$elapsedMs} ms (limit 500 ms).");
    }

    /**
     * Skenario 5: Integritas buku besar FIFO di bawah beban 20 kredit/akrual + 15 debit/refund beruntun (0 drift).
     */
    public function test_scenario_5_high_frequency_fifo_ledger_stress_maintains_exact_zero_drift(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $u = User::factory()->create(['company_id' => $company->id, 'role' => 'user', 'is_active' => true]);
        $emp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $u->id,
            'department_id' => $foDept->id,
            'position' => 'Concierge',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $ledger = app(LeaveLedgerService::class);
        $alType = LeaveType::findByCode('AL', $company->id);

        // 20 credit entries (total +40 days)
        for ($i = 1; $i <= 20; $i++) {
            $ledger->recordCredit($emp, $alType, 2, [
                'expires_at' => Carbon::today('Asia/Jakarta')->addDays(30 + $i)->toDateString(),
                'notes' => "Stress credit #{$i}",
            ]);
        }

        // 15 debit / refund operations (10 debits of 2 = -20, 5 credits of 2 = +10 -> net 30)
        for ($i = 1; $i <= 10; $i++) {
            $ledger->recordDebit($emp, $alType, 2, ['notes' => "Stress debit #{$i}"]);
        }
        for ($i = 1; $i <= 5; $i++) {
            $ledger->recordCredit($emp, $alType, 2, [
                'expires_at' => Carbon::today('Asia/Jakarta')->addDays(90)->toDateString(),
                'notes' => "Stress refund credit #{$i}",
            ]);
        }

        $latest = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $emp->id)
            ->where('leave_type_id', $alType->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $sumAmount = (int) LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $emp->id)
            ->where('leave_type_id', $alType->id)
            ->sum('amount');

        $sumRemaining = (int) LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $emp->id)
            ->where('leave_type_id', $alType->id)
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount');

        $this->assertSame(30, (int) $latest->balance_after);
        $this->assertSame(30, $sumAmount);
        $this->assertSame(30, $sumRemaining);
        $this->assertSame(30, $ledger->getBalance($emp, $alType));
    }

    /**
     * Skenario 6: Command oatcake:optimize-cohosting --benchmark sukses dan dokumentasi co-hosting tersedia.
     */
    public function test_scenario_6_optimize_cohosting_benchmark_command_and_guide_exist(): void
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->artisan('oatcake:optimize-cohosting', ['--benchmark' => true])
            ->expectsOutputToContain('OATCAKE Co-Hosting Resource Diagnostics & Optimizer')
            ->assertExitCode(0);

        $docPath = base_path('docs/COHOSTING_OPTIMIZATION.md');
        $this->assertFileExists($docPath);
        $docContent = File::get($docPath);
        $this->assertStringContainsString('ws_company_date_status_idx', $docContent);
        $this->assertStringContainsString('PostgreSQL 18', $docContent);
    }
}
