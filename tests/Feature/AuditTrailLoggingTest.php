<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AuditTrailService;
use App\Services\LeaveApprovalService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class AuditTrailLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Verifikasi skema kolom JSONB & indeks GIN PostgreSQL 18 pada tabel audit_logs.
     */
    public function test_scenario_1_audit_logs_table_has_jsonb_columns_and_postgresql_gin_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id',
            'uuid',
            'company_id',
            'actor_id',
            'actor_name',
            'actor_role',
            'event_type',
            'auditable_type',
            'auditable_id',
            'description',
            'old_values',
            'new_values',
            'metadata',
            'created_at',
        ]));

        $migrationContent = File::get(database_path('migrations/2026_11_12_000003_create_audit_logs_table.php'));
        $this->assertStringContainsString("jsonb('old_values')", $migrationContent);
        $this->assertStringContainsString("jsonb('new_values')", $migrationContent);
        $this->assertStringContainsString("jsonb('metadata')", $migrationContent);
        $this->assertStringContainsString('audit_logs_old_values_gin_idx ON audit_logs USING gin (old_values)', $migrationContent);
        $this->assertStringContainsString('audit_logs_new_values_gin_idx ON audit_logs USING gin (new_values)', $migrationContent);
        $this->assertStringContainsString('audit_logs_metadata_gin_idx ON audit_logs USING gin (metadata)', $migrationContent);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $columns = DB::table('information_schema.columns')
                ->where('table_name', 'audit_logs')
                ->whereIn('column_name', ['old_values', 'new_values', 'metadata'])
                ->pluck('udt_name', 'column_name')
                ->all();

            $this->assertSame('jsonb', $columns['old_values'] ?? null);
            $this->assertSame('jsonb', $columns['new_values'] ?? null);
            $this->assertSame('jsonb', $columns['metadata'] ?? null);

            $indexes = DB::table('pg_indexes')
                ->where('tablename', 'audit_logs')
                ->pluck('indexdef', 'indexname')
                ->all();

            $this->assertArrayHasKey('audit_logs_old_values_gin_idx', $indexes);
            $this->assertArrayHasKey('audit_logs_new_values_gin_idx', $indexes);
            $this->assertArrayHasKey('audit_logs_metadata_gin_idx', $indexes);
            $this->assertStringContainsString('USING gin', strtolower($indexes['audit_logs_old_values_gin_idx']));
        }
    }

    /**
     * Skenario 2: Perekaman otomatis siklus hidup model (created, updated, deleted) & akurasi diff.
     */
    public function test_scenario_2_records_audit_trail_captures_accurate_created_updated_and_deleted_diffs(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'HR Audit Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        // 1. Created
        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'ENG',
            'name' => 'Engineering',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $createdLog = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', 'department')
            ->where('auditable_id', $dept->id)
            ->where('event_type', 'created')
            ->first();

        $this->assertNotNull($createdLog);
        $this->assertNull($createdLog->old_values);
        $this->assertSame('ENG', $createdLog->new_values['code'] ?? null);
        $this->assertSame('Engineering', $createdLog->new_values['name'] ?? null);
        $this->assertSame($admin->id, $createdLog->actor_id);

        // 2. Updated (hanya atribut yang benar-benar berubah)
        $dept->update([
            'name' => 'Engineering & Maintenance',
            'min_staff_per_shift' => 4,
        ]);

        $updatedLog = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', 'department')
            ->where('auditable_id', $dept->id)
            ->where('event_type', 'updated')
            ->first();

        $this->assertNotNull($updatedLog);
        $this->assertSame(['name', 'min_staff_per_shift'], array_keys($updatedLog->new_values ?? []));
        $this->assertSame(['name', 'min_staff_per_shift'], array_keys($updatedLog->old_values ?? []));
        $this->assertSame('Engineering', $updatedLog->old_values['name']);
        $this->assertSame('Engineering & Maintenance', $updatedLog->new_values['name']);
        $this->assertEquals(2, $updatedLog->old_values['min_staff_per_shift']);
        $this->assertEquals(4, $updatedLog->new_values['min_staff_per_shift']);

        // 3. Deleted
        $deptId = $dept->id;
        $dept->delete();

        $deletedLog = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', 'department')
            ->where('auditable_id', $deptId)
            ->where('event_type', 'deleted')
            ->first();

        $this->assertNotNull($deletedLog);
        $this->assertNull($deletedLog->new_values);
        $this->assertSame('Engineering & Maintenance', $deletedLog->old_values['name'] ?? null);
    }

    /**
     * Skenario 3: Perekaman audit lintas modul operasional (cuti berjenjang, buku besar, bobot SMART & publikasi roster).
     */
    public function test_scenario_3_operational_modules_record_rich_audit_logs_with_http_metadata(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $hotel = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'FO')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'MO')->firstOrFail();
        $alType = LeaveType::findByCode('AL', $hotel->id);

        $admin = User::factory()->create(['company_id' => $hotel->id, 'role' => 'admin', 'is_active' => true]);
        $manager = User::factory()->create(['company_id' => $hotel->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'user_id' => $manager->id,
            'department_id' => $foDept->id,
            'position' => 'FO Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $staffUser = User::factory()->create(['company_id' => $hotel->id, 'role' => 'user', 'is_active' => true]);
        $staffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'user_id' => $staffUser->id,
            'department_id' => $foDept->id,
            'position' => 'GSA',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // 1. Penyesuaian manual buku besar via HTTP endpoint
        $this->actingAs($admin)->postJson('/leave/ledger/adjust', [
            'employee_id' => $staffEmp->id,
            'leave_type_id' => $alType->id,
            'direction' => 'credit',
            'amount' => 10,
            'notes' => 'Saldo awal tahun operasional',
        ])->assertOk();

        $ledgerAudit = AuditLog::withoutGlobalScopes()
            ->where('company_id', $hotel->id)
            ->where('auditable_type', 'leave_ledger_entry')
            ->where('event_type', 'ledger_adjusted')
            ->first();
        $this->assertNotNull($ledgerAudit);
        $this->assertSame('leave.ledger.adjust', $ledgerAudit->metadata['route_name'] ?? null);
        $this->assertNotEmpty($ledgerAudit->metadata['ip_address'] ?? null);

        // 2. Perubahan bobot SPK SMART via HTTP endpoint
        $this->actingAs($admin)->putJson('/leave/smart-weights', [
            'department_id' => $foDept->id,
            'weights' => [
                'C1' => 25,
                'C2' => 15,
                'C3' => 30,
                'C4' => 20,
                'C5' => 10,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $hotel->id,
            'auditable_type' => 'smart_department_weight',
        ]);

        // 3. Buat jadwal draft lalu publikasikan via POST /roster/publish
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'employee_id' => $staffEmp->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => Carbon::today('Asia/Jakarta')->addDays(2)->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAs($admin)->postJson('/roster/publish', [
            'month' => 11,
            'year' => 2026,
            'department_id' => $foDept->id,
        ])->assertOk();

        $publishAudit = AuditLog::withoutGlobalScopes()
            ->where('company_id', $hotel->id)
            ->where('auditable_type', 'work_schedule')
            ->where('event_type', 'published')
            ->first();
        $this->assertNotNull($publishAudit);
        $this->assertSame('roster.publish', $publishAudit->metadata['route_name'] ?? null);
        $this->assertSame(1, $publishAudit->new_values['published_count'] ?? null);

        // 4. Persetujuan cuti berjenjang (Tier 1 & Tier 2)
        $leaveRequest = app(LeaveRequestService::class)->submitRequest($staffEmp, [
            'leave_type_id' => $alType->id,
            'start_date' => Carbon::today('Asia/Jakarta')->addDays(2)->toDateString(),
            'end_date' => Carbon::today('Asia/Jakarta')->addDays(2)->toDateString(),
            'reason' => 'Keperluan pribadi',
        ], null, $staffUser);

        $approvalService = app(LeaveApprovalService::class);
        $leaveRequest = $approvalService->approveTier1($leaveRequest, $manager, 'OK dari FO');
        $approvalService->approveTier2($leaveRequest, $admin, 'OK dari HR');

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $hotel->id,
            'auditable_type' => 'leave_request',
            'auditable_id' => $leaveRequest->id,
            'event_type' => 'approved_tier1',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $hotel->id,
            'auditable_type' => 'leave_request',
            'auditable_id' => $leaveRequest->id,
            'event_type' => 'approved_tier2',
        ]);
    }

    /**
     * Skenario 4: Strict Append-Only Immutability Guard menolak update() dan delete() dengan LogicException.
     */
    public function test_scenario_4_audit_log_model_is_strictly_immutable_against_update_and_delete(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $log = app(AuditTrailService::class)->record(
            'created',
            'department',
            null,
            ['code' => 'SEC', 'name' => 'Security'],
            'Created Security department',
            [],
            null,
            $company->id
        );

        $this->assertNotNull($log);

        $this->expectException(LogicException::class);
        $log->update(['description' => 'Tampered description']);
    }

    /**
     * Skenario 4b: Memastikan delete() pada AuditLog juga melempar LogicException.
     */
    public function test_scenario_4b_audit_log_model_rejects_delete_with_logic_exception(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $log = app(AuditTrailService::class)->record(
            'created',
            'department',
            null,
            ['code' => 'FB', 'name' => 'Food & Beverage'],
            'Created F&B department',
            [],
            null,
            $company->id
        );

        $this->assertNotNull($log);

        $this->expectException(LogicException::class);
        $log->delete();
    }

    /**
     * Skenario 5: Sanitasi atribut sensitif & Strict Zero NIK pada old_values, new_values, dan metadata.
     */
    public function test_scenario_5_sensitive_credentials_and_nik_are_strictly_redacted_from_all_jsonb_columns(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $log = app(AuditTrailService::class)->record(
            'updated',
            'employee',
            [
                'position' => 'Waiter',
                'nik' => '3401010101900001',
                'password' => '$2y$12$secretoldhash',
                'remember_token' => 'old-remember',
            ],
            [
                'position' => 'Captain',
                'nik' => '3401010101900002',
                'password' => '$2y$12$secretnewhash',
                'auth_token' => 'secret-auth-token',
                'public_key' => 'secret-pub-key',
                'nested' => ['nik' => '9999999999999999', 'safe' => 'yes'],
            ],
            'Updated employee position',
            [
                'nik' => '3401010101900003',
                'two_factor_secret' => 'TOTP-SECRET',
                'two_factor_recovery_codes' => 'RECOVERY',
                'context' => 'hr_console',
            ],
            null,
            $company->id
        );

        $this->assertNotNull($log);

        $serialized = json_encode([
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
        ]);

        $this->assertStringNotContainsString('"nik"', $serialized);
        $this->assertStringNotContainsString('3401010101900001', $serialized);
        $this->assertStringNotContainsString('9999999999999999', $serialized);
        $this->assertStringNotContainsString('"password"', $serialized);
        $this->assertStringNotContainsString('"remember_token"', $serialized);
        $this->assertStringNotContainsString('"auth_token"', $serialized);
        $this->assertStringNotContainsString('"public_key"', $serialized);
        $this->assertStringNotContainsString('two_factor_secret', $serialized);
        $this->assertSame('Captain', $log->new_values['position'] ?? null);
        $this->assertSame('yes', $log->new_values['nested']['safe'] ?? null);
        $this->assertSame('hr_console', $log->metadata['context'] ?? null);
    }

    /**
     * Skenario 6: Pencarian & filter operator containment JSONB (@>) serta filter tanggal/modul/event.
     */
    public function test_scenario_6_audit_console_supports_jsonb_containment_and_multi_attribute_filters(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 10, 0, 0, 'Asia/Jakarta'));

        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'is_active' => true]);

        $service = app(AuditTrailService::class);
        $service->record(
            'approved_tier2',
            'leave_request',
            ['status' => 'pending_hr'],
            ['status' => 'approved', 'total_days' => 2],
            'Disetujui pengajuan cuti tahunan Budi',
            [],
            $admin,
            $company->id
        );
        $service->record(
            'rejected',
            'leave_request',
            ['status' => 'pending_manager'],
            ['status' => 'rejected'],
            'Ditolak pengajuan cuti Sinta',
            [],
            $admin,
            $company->id
        );

        // Filter JSONB key:value -> status:approved
        $resp = $this->actingAs($admin)->get('/audit-logs?json_filter=status:approved&auditable_type=leave_request&event_type=approved_tier2&date_from=2026-11-18&date_to=2026-11-18');
        $resp->assertOk();
        $resp->assertSee('Disetujui pengajuan cuti tahunan Budi');
        $resp->assertDontSee('Ditolak pengajuan cuti Sinta');
    }

    /**
     * Skenario 7: Isolasi RBAC & Multi-Tenant (HTTP 403 Forbidden bagi manager/user dan lintas perusahaan).
     */
    public function test_scenario_7_audit_logs_enforce_admin_above_rbac_and_strict_tenant_isolation(): void
    {
        $this->seed([CompanySeeder::class]);
        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $hotelB = Company::create([
            'code' => 'MERCURE-SBY',
            'name' => 'Mercure Surabaya',
            'latitude' => '-7.2856000',
            'longitude' => '112.7421000',
            'geofence_radius_meters' => 150,
            'accent_color' => '#7C3AED',
            'is_active' => true,
        ]);

        $superAdmin = User::factory()->create(['company_id' => null, 'role' => 'super_admin', 'is_active' => true]);
        $superUserA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'super_user', 'is_active' => true]);
        $adminA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'admin', 'is_active' => true]);
        $managerA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        $userA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        $adminB = User::factory()->create(['company_id' => $hotelB->id, 'role' => 'admin', 'is_active' => true]);

        $logA = app(AuditTrailService::class)->record(
            'created',
            'shift',
            null,
            ['code' => 'A-MORNING', 'name' => 'Shift A'],
            'Shift Hotel A dibuat',
            [],
            $adminA,
            $hotelA->id
        );

        $logB = app(AuditTrailService::class)->record(
            'created',
            'shift',
            null,
            ['code' => 'B-NIGHT', 'name' => 'Shift B'],
            'Shift Hotel B dibuat',
            [],
            $adminB,
            $hotelB->id
        );

        // Manager & Staff biasa ditolak 403
        $this->actingAs($managerA)->get('/audit-logs')->assertForbidden();
        $this->actingAs($userA)->get('/audit-logs')->assertForbidden();
        $this->actingAs($managerA)->getJson("/audit-logs/{$logA->uuid}")->assertForbidden();
        $this->actingAs($userA)->getJson("/audit-logs/{$logA->uuid}")->assertForbidden();

        // Admin A, Super User A, Super Admin diizinkan HTTP 200
        $this->actingAs($adminA)->get('/audit-logs')
            ->assertOk()
            ->assertSee('Shift Hotel A dibuat')
            ->assertDontSee('Shift Hotel B dibuat');

        $this->actingAs($superUserA)->get('/audit-logs')->assertOk();
        $this->actingAs($superAdmin)->get('/audit-logs')->assertOk();

        $this->actingAs($adminA)->getJson("/audit-logs/{$logA->uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $logA->uuid)
            ->assertJsonStructure(['diff_rows', 'old_values', 'new_values', 'metadata']);

        // Admin A ditolak 403 saat mencoba melihat detail log milik Perusahaan B
        $this->actingAs($adminA)->getJson("/audit-logs/{$logB->uuid}")->assertForbidden();
        $this->actingAs($adminB)->getJson("/audit-logs/{$logA->uuid}")->assertForbidden();
    }

    /**
     * Skenario 8: Kamus dwibahasa audit.php tersedia & simetris di ID dan EN.
     */
    public function test_scenario_8_audit_language_dictionaries_have_strict_parity(): void
    {
        $idFile = lang_path('id/audit.php');
        $enFile = lang_path('en/audit.php');

        $this->assertFileExists($idFile);
        $this->assertFileExists($enFile);

        $idKeys = array_keys(require $idFile);
        $enKeys = array_keys(require $enFile);

        sort($idKeys);
        sort($enKeys);

        $this->assertSame($idKeys, $enKeys);
        $this->assertNotEmpty($idKeys);
    }
}
