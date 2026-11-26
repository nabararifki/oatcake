<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyLeaveTypeSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveApprovalService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class RemediationBatch2Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Department $smDept;
    protected Shift $shiftMo;
    protected Shift $shiftOff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $this->smDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'SM')
            ->firstOrFail();

        $this->shiftMo = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $this->shiftOff = Shift::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'OFF'],
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createEmployee(
        string $role = 'user',
        ?Department $department = null,
        ?Company $company = null,
        string $name = 'Staff Member',
        string $usernamePrefix = 'staff'
    ): Employee {
        $targetCompany = $company ?? $this->company;
        $targetDept = $department ?? $this->foDept;
        $unique = $usernamePrefix.'_'.str_replace('.', '', uniqid('', true));

        $user = User::factory()->create([
            'company_id' => $targetCompany->id,
            'name' => $name,
            'username' => $unique,
            'email' => $unique.'@novotelyia.com',
            'role' => $role,
            'is_active' => true,
        ]);

        return Employee::withoutGlobalScopes()->create([
            'company_id' => $targetCompany->id,
            'user_id' => $user->id,
            'department_id' => $targetDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
    }

    public function test_block_1a_foreign_keys_enforce_on_delete_restrict_for_ledger_and_audit_logs(): void
    {
        $creator = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin_hr',
            'is_active' => true,
        ]);
        $emp = $this->createEmployee('user', $this->foDept);
        $alType = LeaveType::findByCode('AL', $this->company->id);
        $ledger = app(LeaveLedgerService::class);

        $ledger->recordAccrual($emp, $alType, 12, [
            'accrual_year' => 2026,
            'created_by' => $creator->id,
        ]);

        AuditLog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'actor_id' => $creator->id,
            'event_type' => 'created',
            'auditable_type' => 'employee',
            'auditable_id' => $emp->id,
            'description' => 'Audit FK restrict check',
            'created_at' => Carbon::now('Asia/Jakarta'),
        ]);

        foreach ([
            ['table' => 'employees', 'id' => $emp->id],
            ['table' => 'leave_types', 'id' => $alType->id],
            ['table' => 'users', 'id' => $creator->id],
            ['table' => 'companies', 'id' => $this->company->id],
        ] as $target) {
            $threw = false;
            try {
                DB::table($target['table'])->where('id', $target['id'])->delete();
            } catch (QueryException) {
                $threw = true;
            }
            $this->assertTrue($threw, "Expected ON DELETE RESTRICT QueryException when deleting from {$target['table']}.");
        }
    }

    public function test_block_1b_leave_ledger_entry_enforces_strict_mutable_allowlist(): void
    {
        $emp = $this->createEmployee('user', $this->foDept);
        $ledger = app(LeaveLedgerService::class);

        $entry = $ledger->recordAccrual($emp, 'AL', 12, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
            'notes' => 'Initial accrual',
        ]);

        // Allowlisted remaining_amount update succeeds
        $entry->update(['remaining_amount' => 9]);
        $this->assertSame(9, $entry->fresh()->remaining_amount);

        // Non-allowlisted attributes must throw LogicException
        foreach ([
            ['notes' => 'Tampered notes'],
            ['expires_at' => '2030-12-31'],
            ['accrual_year' => 2099],
            ['amount' => 50],
            ['balance_after' => 99],
        ] as $tamperPayload) {
            $threw = false;
            try {
                $entry->fresh()->update($tamperPayload);
            } catch (LogicException $e) {
                $threw = true;
                $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
            }
            $this->assertTrue($threw, 'Expected LogicException for payload: '.json_encode($tamperPayload));
        }
    }

    public function test_block_2_performance_indexes_and_jsonb_path_ops_migration_are_present(): void
    {
        $this->assertTrue(Schema::hasIndex('employees', 'employees_user_id_idx'));
        $this->assertTrue(Schema::hasIndex('employees', 'employees_manager_id_idx'));
        $this->assertTrue(Schema::hasIndex('employee_tags', 'employee_tags_tag_id_idx'));
        $this->assertTrue(Schema::hasIndex('leave_ledger_entries', 'leave_ledger_balance_lookup_idx'));
        $this->assertTrue(Schema::hasIndex('attendances', 'attendances_created_at_idx'));

        $migrationContent = File::get(database_path('migrations/2026_11_13_000003_add_missing_performance_indexes.php'));
        $this->assertStringContainsString('USING gin (old_values jsonb_path_ops)', $migrationContent);
        $this->assertStringContainsString('USING gin (new_values jsonb_path_ops)', $migrationContent);
        $this->assertStringContainsString('USING gin (metadata jsonb_path_ops)', $migrationContent);
    }

    public function test_block_3_pagination_case_insensitive_search_and_full_smart_recalculation(): void
    {
        $admin = $this->createEmployee('admin_hr', $this->foDept, null, 'Admin Pelatihan', 'adm_pag');
        $staffMina = $this->createEmployee('user', $this->foDept, null, 'Mina Kusuma', 'mina_fo');
        $staffBudi = $this->createEmployee('user', $this->foDept, null, 'Budi Santoso', 'budi_fo');

        // Seed 28 outside-duty attendance records to verify 25-per-page pagination
        for ($i = 0; $i < 28; $i++) {
            Attendance::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $i === 0 ? $staffMina->id : $staffBudi->id,
                'clock_in_at' => Carbon::create(2026, 11, 1, 8, 0, 0, 'Asia/Jakarta')->addMinutes($i),
                'status' => 'pending_verification',
                'is_outside_geofence' => true,
                'outside_category' => 'client_visit',
                'outside_location_name' => 'Site '.$i,
            ]);
        }

        $verifPage1 = $this->actingAs($admin->user)->get(route('attendance.verifications.index'));
        $verifPage1->assertOk();
        $verifPaginator = $verifPage1->viewData('attendances');
        $this->assertInstanceOf(LengthAwarePaginator::class, $verifPaginator);
        $this->assertSame(25, $verifPaginator->perPage());
        $this->assertCount(25, $verifPaginator->items());
        $verifPage1->assertSee('?page=2', false);

        $verifPage2 = $this->actingAs($admin->user)->get(route('attendance.verifications.index', ['page' => 2]));
        $verifPage2->assertOk();
        $this->assertCount(3, $verifPage2->viewData('attendances')->items());

        // Attendance index uses 25-per-page pagination & search works across mixed case
        $attResp = $this->actingAs($admin->user)->get(route('attendance.index', [
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-30',
            'search' => 'mInA',
        ]));
        $attResp->assertOk();
        $attPaginator = $attResp->viewData('attendances');
        $this->assertInstanceOf(LengthAwarePaginator::class, $attPaginator);
        $this->assertSame(25, $attPaginator->perPage());
        $this->assertCount(1, $attPaginator->items());

        // Verify Controllers contain dynamic pgsql ilike branch
        $this->assertStringContainsString("getDriverName() === 'pgsql' ? 'ilike' : 'like'", File::get(app_path('Http/Controllers/AttendanceController.php')));
        $this->assertStringContainsString("getDriverName() === 'pgsql' ? 'ilike' : 'like'", File::get(app_path('Http/Controllers/LeaveRequestController.php')));

        // SmartDecisionService recalculates ALL overlapping pending requests (not just ->first())
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 9, 0, 0, 'Asia/Jakarta'));
        $staffC = $this->createEmployee('user', $this->foDept, null, 'Citra Putri', 'citra_fo');
        $alType = LeaveType::findByCode('AL', $this->company->id);
        $ledger = app(LeaveLedgerService::class);
        foreach ([$staffMina, $staffBudi, $staffC] as $emp) {
            $ledger->recordAccrual($emp, $alType, 12, ['accrual_year' => 2026]);
        }

        $reqService = app(LeaveRequestService::class);
        $approvalService = app(LeaveApprovalService::class);
        $mgr = $this->createEmployee('manager', $this->foDept, null, 'FO Manager', 'mgr_smart');

        $reqA = $reqService->submitRequest($staffMina, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'Approved baseline leave',
        ], null, $staffMina->user);
        $reqA = $approvalService->approveTier1($reqA, $mgr->user);
        $reqA = $approvalService->approveTier2($reqA, $admin->user);

        $reqB = $reqService->submitRequest($staffBudi, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'Competing request B',
        ], null, $staffBudi->user);

        $reqC = $reqService->submitRequest($staffC, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'Competing request C',
        ], null, $staffC->user);

        // Set sentinel scores on BOTH overlapping requests to prove both get recalculated on cancel
        LeaveRequest::withoutGlobalScopes()->whereKey($reqB->id)->update(['smart_score' => 1.23]);
        LeaveRequest::withoutGlobalScopes()->whereKey($reqC->id)->update(['smart_score' => 1.23]);

        $reqService->cancelRequest($reqA, 'Plans changed', $staffMina->user);

        $this->assertNotEquals(1.23, (float) $reqB->fresh()->smart_score);
        $this->assertNotEquals(1.23, (float) $reqC->fresh()->smart_score);
    }

    public function test_block_4_roster_anti_spoofing_scoped_leave_delete_and_safe_discard_publish(): void
    {
        $admin = $this->createEmployee('admin_hr', $this->foDept, null, 'Admin Roster', 'adm_ros');
        $staff = $this->createEmployee('user', $this->foDept, null, 'Staf Roster', 'stf_ros');

        // Manual notes spoofing 'LEAVE:' must be rejected with 422 on assign and updateShift
        $this->actingAs($admin->user)
            ->postJson(route('roster.assign'), [
                'employee_id' => $staff->id,
                'schedule_date' => '2026-12-05',
                'shift_id' => $this->shiftMo->id,
                'notes' => 'LEAVE:AL:999|Spoofed off',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);

        $this->actingAs($admin->user)
            ->putJson(route('roster.shifts.update', $this->shiftMo), [
                'start_time' => '07:00',
                'end_time' => '15:00',
                'notes' => 'LEAVE:AL:999',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);

        // Cancelling request A only removes LEAVE:%:{reqA->id} and preserves request B's LEAVE:%:{reqB->id}
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 9, 0, 0, 'Asia/Jakarta'));
        $alType = LeaveType::findByCode('AL', $this->company->id);
        app(LeaveLedgerService::class)->recordAccrual($staff, $alType, 12, ['accrual_year' => 2026]);

        $reqService = app(LeaveRequestService::class);
        $approvalService = app(LeaveApprovalService::class);

        $reqA = $reqService->submitRequest($staff, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'reason' => 'Request A',
        ], null, $staff->user);
        $reqA = $approvalService->approveTier1($reqA, $admin->user);
        $reqA = $approvalService->approveTier2($reqA, $admin->user);

        // Simulate a distinct request B owning another date within edit window or adjacent date
        $reqB = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-02',
            'end_date' => '2026-12-02',
            'total_days' => 1,
            'reason' => 'Request B preserved',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        // Re-tag Dec 02 schedule cleanly as belonging to reqB to assert strict ID isolation
        WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $staff->id)
            ->whereDate('schedule_date', '2026-12-02')
            ->update(['notes' => "LEAVE:AL:{$reqB->id}|Annual Leave: Request B preserved"]);

        $reqService->cancelRequest($reqA, 'Cancel A only', $staff->user);

        // Dec 01 belonging to reqA must be deleted; Dec 02 belonging to reqB must remain intact
        $this->assertDatabaseMissing('work_schedules', [
            'employee_id' => $staff->id,
            'schedule_date' => '2026-12-01',
        ]);
        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $staff->id,
            'schedule_date' => '2026-12-02',
            'notes' => "LEAVE:AL:{$reqB->id}|Annual Leave: Request B preserved",
        ]);

        // discardDrafts & publish must NEVER delete LEAVE:% schedules even if marked draft/pending_delete
        WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $staff->id)
            ->whereDate('schedule_date', '2026-12-02')
            ->update(['status' => 'draft']);

        $this->actingAs($admin->user)
            ->postJson(route('roster.discard'), ['month' => 12, 'year' => 2026, 'department_id' => $this->foDept->id])
            ->assertOk();

        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $staff->id,
            'schedule_date' => '2026-12-02',
            'notes' => "LEAVE:AL:{$reqB->id}|Annual Leave: Request B preserved",
        ]);

        WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $staff->id)
            ->whereDate('schedule_date', '2026-12-02')
            ->update(['status' => 'pending_delete']);

        $this->actingAs($admin->user)
            ->postJson(route('roster.publish'), ['month' => 12, 'year' => 2026, 'department_id' => $this->foDept->id])
            ->assertOk();

        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $staff->id,
            'schedule_date' => '2026-12-02',
            'notes' => "LEAVE:AL:{$reqB->id}|Annual Leave: Request B preserved",
        ]);
    }

    public function test_block_5_spaced_non_overlapping_cron_and_fault_tolerant_accrual_command(): void
    {
        $schedule = $this->app->make(ConsoleSchedule::class);
        $events = collect($schedule->events());

        $findCommand = fn (string $name) => $events->first(
            fn ($event) => str_contains((string) $event->command, $name)
        );

        $prunePhotos = $findCommand('attendance:prune-photos');
        $accrueAnnual = $findCommand('leave:accrue-annual');
        $pruneExpired = $findCommand('leave:prune-expired');

        $this->assertNotNull($prunePhotos);
        $this->assertSame('5 0 * * *', $prunePhotos->expression);
        $this->assertSame('Asia/Jakarta', $prunePhotos->timezone);
        $this->assertTrue($prunePhotos->withoutOverlapping);

        $this->assertNotNull($accrueAnnual);
        $this->assertSame('15 0 * * *', $accrueAnnual->expression);
        $this->assertSame('Asia/Jakarta', $accrueAnnual->timezone);
        $this->assertTrue($accrueAnnual->withoutOverlapping);

        $this->assertNotNull($pruneExpired);
        $this->assertSame('30 0 * * *', $pruneExpired->expression);
        $this->assertSame('Asia/Jakarta', $pruneExpired->timezone);
        $this->assertTrue($pruneExpired->withoutOverlapping);

        // Tenant A has default_annual_quota = 0 (invalid/zero), Tenant B has valid default 12
        $alType = LeaveType::findByCode('AL', $this->company->id);
        CompanyLeaveTypeSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->id, 'leave_type_id' => $alType->id],
            ['default_annual_quota' => 0]
        );
        $empA = $this->createEmployee('user', $this->foDept, $this->company, 'Tenant A Staff', 't_a');

        $companyB = Company::create([
            'name' => 'Ibis Styles YIA',
            'code' => 'IBIS-YIA-2',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);
        $deptB = Department::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'code' => 'FO',
            'name' => 'Front Office Ibis',
            'is_operational' => true,
            'min_staff_per_shift' => 1,
        ]);
        $empB = $this->createEmployee('user', $deptB, $companyB, 'Tenant B Staff', 't_b');

        $this->artisan('leave:accrue-annual', ['--year' => 2027])->assertExitCode(0);

        $ledger = app(LeaveLedgerService::class);
        $this->assertSame(0, $ledger->getBalance($empA, 'AL'));
        $this->assertSame(12, $ledger->getBalance($empB, 'AL'));
    }

    public function test_block_6_service_worker_bumps_cache_version_and_excludes_sensitive_routes(): void
    {
        $sw = File::get(public_path('sw.js'));
        $pwaJs = File::get(resource_path('js/pwa.js'));

        $this->assertStringContainsString('oatcake-pwa-v3', $sw);
        $this->assertStringContainsString('oatcake-static-v3', $sw);
        $this->assertStringContainsString('oatcake-pages-v3', $sw);
        $this->assertStringContainsString('oatcake-data-v3', $sw);

        foreach (['/api', '/sanctum', '/audit-logs', '/attendance/verifications', '/settings', '/profile'] as $route) {
            $this->assertStringContainsString("'{$route}'", $sw);
        }

        $this->assertStringContainsString('/sw.js?v=oatcake-v3', $pwaJs);
        $this->assertStringContainsString('oatcake-data-v3', $pwaJs);
    }
}
