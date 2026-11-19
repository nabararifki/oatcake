<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyLeaveTypeSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\PushSubscription;
use App\Models\Shift;
use App\Models\SmartDepartmentWeight;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkSchedule;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class CompanyBackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Track created company IDs for private storage cleanup in tearDown.
     *
     * @var list<int>
     */
    private array $trackedCompanyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->trackedCompanyIds) as $cid) {
            File::deleteDirectory(Storage::disk('local')->path("companies/{$cid}"));
            File::deleteDirectory(Storage::disk('local')->path("attendance_proofs/{$cid}"));
            File::deleteDirectory(Storage::disk('local')->path("leave_attachments/{$cid}"));
        }

        foreach (glob(Storage::disk('local')->path('temp/backup_*')) ?: [] as $dir) {
            File::deleteDirectory($dir);
        }
        foreach (glob(Storage::disk('local')->path('temp/restore_*')) ?: [] as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * Build a complete two-tenant fixture with full relational graph and private physical files.
     *
     * @return array<string, mixed>
     */
    private function seedTwoTenantGraph(): array
    {
        $this->seed(LeaveTypeSeeder::class);

        $companyA = Company::create([
            'name' => 'Novotel Yogyakarta Airport',
            'code' => 'NVT-YIA',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->trackedCompanyIds[] = (int) $companyA->id;

        $companyB = Company::create([
            'name' => 'Mercure Surabaya Grand',
            'code' => 'MRC-SUB',
            'latitude' => -7.2891660,
            'longitude' => 112.7343980,
            'geofence_radius' => 120,
            'timezone' => 'Asia/Jakarta',
        ]);
        $this->trackedCompanyIds[] = (int) $companyB->id;

        $deptA = Department::create([
            'company_id' => $companyA->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_operational' => true,
            'is_24_hours' => true,
            'min_staff_per_shift' => 2,
        ]);

        $deptB = Department::create([
            'company_id' => $companyB->id,
            'code' => 'HK',
            'name' => 'Housekeeping B',
            'is_operational' => true,
            'is_24_hours' => false,
            'min_staff_per_shift' => 1,
        ]);

        $shiftA = Shift::create([
            'company_id' => $companyA->id,
            'code' => 'PG',
            'name' => 'Shift Pagi',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'cross_day' => false,
            'grace_period_minutes' => 15,
            'color_badge' => '#2563EB',
            'is_active' => true,
        ]);

        $tagModA = Tag::create([
            'company_id' => $companyA->id,
            'code' => 'MOD_ELIGIBLE',
            'name' => 'Manager on Duty',
            'badge_color' => '#D97706',
        ]);

        $holidayA = PublicHoliday::create([
            'company_id' => $companyA->id,
            'holiday_date' => '2026-12-28',
            'name' => 'Hari Jadi Properti A',
            'is_national' => false,
        ]);

        PublicHoliday::create([
            'company_id' => $companyB->id,
            'holiday_date' => '2026-12-26',
            'name' => 'Holiday Exclusive Company B',
            'is_national' => false,
        ]);

        $superAdmin = User::factory()->create([
            'company_id' => null,
            'role' => 'super_admin',
            'email' => 'root@oatcake.test',
            'username' => 'root_superadmin',
        ]);

        $superUserA = User::factory()->create([
            'company_id' => $companyA->id,
            'role' => 'super_user',
            'email' => 'superuser.a@oatcake.test',
            'username' => 'superuser_a',
        ]);

        $adminA = User::factory()->create([
            'company_id' => $companyA->id,
            'role' => 'admin',
            'email' => 'admin.a@oatcake.test',
            'username' => 'admin_a',
        ]);

        $managerUserA = User::factory()->create([
            'company_id' => $companyA->id,
            'role' => 'manager',
            'email' => 'manager.a@oatcake.test',
            'username' => 'manager_a',
        ]);

        $staffUserA = User::factory()->create([
            'company_id' => $companyA->id,
            'role' => 'user',
            'email' => 'staff.a@oatcake.test',
            'username' => 'staff_a',
        ]);

        $superUserB = User::factory()->create([
            'company_id' => $companyB->id,
            'role' => 'super_user',
            'email' => 'superuser.b@oatcake.test',
            'username' => 'superuser_b',
        ]);

        $managerEmpA = Employee::create([
            'company_id' => $companyA->id,
            'user_id' => $managerUserA->id,
            'department_id' => $deptA->id,
            'manager_id' => null,
            'position' => 'Front Office Manager',
            'join_date' => '2023-01-10',
            'status' => 'active',
        ]);

        $staffEmpA = Employee::create([
            'company_id' => $companyA->id,
            'user_id' => $staffUserA->id,
            'department_id' => $deptA->id,
            'manager_id' => $managerEmpA->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2024-03-01',
            'status' => 'active',
        ]);

        $managerEmpA->tags()->attach($tagModA->id);

        $empB = Employee::create([
            'company_id' => $companyB->id,
            'user_id' => $superUserB->id,
            'department_id' => $deptB->id,
            'manager_id' => null,
            'position' => 'Director B',
            'join_date' => '2022-05-01',
            'status' => 'active',
        ]);

        $alType = LeaveType::where('code', 'AL')->firstOrFail();

        CompanyLeaveTypeSetting::create([
            'company_id' => $companyA->id,
            'leave_type_id' => $alType->id,
            'validity_months' => 12,
            'default_annual_quota' => 14,
            'updated_by' => $superUserA->id,
        ]);

        SmartDepartmentWeight::create([
            'company_id' => $companyA->id,
            'department_id' => $deptA->id,
            'criteria_code' => 'C1_MANPOWER',
            'raw_weight' => 40,
            'normalized_weight' => 0.4000,
            'updated_by' => $adminA->id,
        ]);

        $workScheduleA = WorkSchedule::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => $shiftA->id,
            'schedule_date' => '2026-11-18',
            'duty_tag_id' => $tagModA->id,
            'status' => 'published',
            'notes' => 'Duty MOD Pagi',
            'created_by' => $managerUserA->id,
        ]);

        $creditLedger = LeaveLedgerEntry::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'leave_type_id' => $alType->id,
            'transaction_type' => 'credit',
            'amount' => 12,
            'remaining_amount' => 10,
            'balance_after' => 12,
            'accrual_year' => 2026,
            'expires_at' => '2027-12-31',
            'notes' => 'Akrual AL 2026',
            'created_by' => $adminA->id,
        ]);

        $attachmentPathA = "leave_attachments/{$companyA->id}/2026-11/surat-cuti.pdf";
        Storage::disk('local')->put($attachmentPathA, '%PDF-1.4 COMPANY A ATTACHMENT');

        $leaveRequestA = LeaveRequest::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-21',
            'total_days' => 2,
            'reason' => 'Keperluan keluarga terencana',
            'attachment_path' => $attachmentPathA,
            'status' => 'approved',
            'smart_score' => 0.8750,
            'smart_metrics' => ['c1' => 100, 'c5' => 0],
            'ledger_entry_id' => null,
            'manager_approved_by' => $managerUserA->id,
            'manager_approved_at' => '2026-11-17 09:00:00',
            'hr_approved_by' => $adminA->id,
            'hr_approved_at' => '2026-11-17 10:00:00',
        ]);

        $debitLedger = LeaveLedgerEntry::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'leave_type_id' => $alType->id,
            'transaction_type' => 'debit',
            'amount' => -2,
            'remaining_amount' => 0,
            'balance_after' => 10,
            'source_entry_id' => $creditLedger->id,
            'reference_type' => 'leave_request',
            'reference_id' => $leaveRequestA->id,
            'accrual_year' => 2026,
            'notes' => 'Pemakaian Cuti 2 Hari',
            'created_by' => $adminA->id,
        ]);

        $leaveRequestA->update(['ledger_entry_id' => $debitLedger->id]);

        $proofPathA = "attendance_proofs/{$companyA->id}/2026-11/selfie-a.webp";
        Storage::disk('local')->put($proofPathA, 'RIFFWEBP_BINARY_COMPANY_A');

        $proofPathB = "attendance_proofs/{$companyB->id}/2026-11/selfie-b.webp";
        Storage::disk('local')->put($proofPathB, 'RIFFWEBP_BINARY_COMPANY_B');

        $attendanceA = Attendance::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'work_schedule_id' => $workScheduleA->id,
            'clock_in_at' => '2026-11-18 07:02:00',
            'clock_out_at' => '2026-11-18 15:05:00',
            'status' => 'on_time',
            'proof_photo_path' => $proofPathA,
            'clock_in_photo_path' => $proofPathA,
            'verified_by' => $managerUserA->id,
            'verified_at' => '2026-11-18 08:00:00',
            'dp_ledger_entry_id' => $creditLedger->id,
        ]);

        $leaveScheduleA = WorkSchedule::create([
            'company_id' => $companyA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => null,
            'schedule_date' => '2026-11-20',
            'status' => 'published',
            'notes' => "LEAVE:{$leaveRequestA->id}:AL",
            'created_by' => $adminA->id,
        ]);

        $approvalA = LeaveRequestApproval::create([
            'company_id' => $companyA->id,
            'leave_request_id' => $leaveRequestA->id,
            'approver_id' => $managerUserA->id,
            'tier' => 1,
            'action' => 'approved',
            'notes' => 'Formasi FO aman',
            'smart_score_snapshot' => 0.8750,
            'acted_at' => '2026-11-17 09:00:00',
        ]);

        PushSubscription::create([
            'company_id' => $companyA->id,
            'user_id' => $staffUserA->id,
            'endpoint' => 'https://push.example.test/sub-a',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/sub-a'),
            'public_key' => 'pub_key_a',
            'auth_token' => 'auth_tok_a',
            'content_encoding' => 'aes128gcm',
        ]);

        UserNotification::create([
            'company_id' => $companyA->id,
            'user_id' => $staffUserA->id,
            'category' => 'leave_approved',
            'title' => 'Cuti Disetujui',
            'body' => 'Pengajuan cuti disetujui final.',
            'meta' => ['leave_request_id' => $leaveRequestA->id],
        ]);

        return compact(
            'companyA',
            'companyB',
            'deptA',
            'deptB',
            'shiftA',
            'tagModA',
            'holidayA',
            'superAdmin',
            'superUserA',
            'adminA',
            'managerUserA',
            'staffUserA',
            'superUserB',
            'managerEmpA',
            'staffEmpA',
            'empB',
            'alType',
            'workScheduleA',
            'leaveScheduleA',
            'creditLedger',
            'debitLedger',
            'attendanceA',
            'leaveRequestA',
            'approvalA',
            'proofPathA',
            'proofPathB',
            'attachmentPathA'
        );
    }

    public function test_strict_rbac_blocks_admin_manager_user_and_cross_tenant_super_user_and_hides_ui_for_admin(): void
    {
        $fx = $this->seedTwoTenantGraph();

        // Admin, Manager, Regular User receive 403 on every backup/restore route
        foreach ([$fx['staffUserA'], $fx['managerUserA'], $fx['adminA']] as $unauthorized) {
            $this->actingAs($unauthorized)->getJson(route('settings.company.backups.index'))->assertForbidden();
            $this->actingAs($unauthorized)->postJson(route('settings.company.backups.store'))->assertForbidden();
            $this->actingAs($unauthorized)->get(route('settings.company.backups.download', ['filename' => 'backup-test.zip']))->assertForbidden();
            $this->actingAs($unauthorized)->deleteJson(route('settings.company.backups.destroy', ['filename' => 'backup-test.zip']))->assertForbidden();

            $dummyZip = UploadedFile::fake()->create('dummy.zip', 10, 'application/zip');
            $this->actingAs($unauthorized)->postJson(route('settings.company.backups.restore'), [
                'backup_file' => $dummyZip,
            ])->assertForbidden();
        }

        // UI Blade guard: Admin does NOT see Backup section, while Super User & Super Admin DO see it
        $this->actingAs($fx['adminA'])->get(route('settings.company'))
            ->assertOk()
            ->assertDontSee('id="company-backup-console"', false)
            ->assertDontSee('confirm-restore-backup-modal', false);

        $this->actingAs($fx['superUserA'])->get(route('settings.company'))
            ->assertOk()
            ->assertSee('id="company-backup-console"', false)
            ->assertSee('confirm-restore-backup-modal', false);

        $this->actingAs($fx['superAdmin'])
            ->withSession(['active_company_id' => $fx['companyA']->id])
            ->get(route('settings.company'))
            ->assertOk()
            ->assertSee('id="company-backup-console"', false);

        // Cross-tenant isolation: super_user A cannot manage or restore Company B
        $backupB = $this->actingAs($fx['superUserB'])
            ->postJson(route('settings.company.backups.store'))
            ->assertCreated()
            ->json('backup');

        $this->actingAs($fx['superUserA'])
            ->getJson(route('settings.company.backups.index', ['company_id' => $fx['companyB']->id]))
            ->assertForbidden();

        $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.store'), ['company_id' => $fx['companyB']->id])
            ->assertForbidden();

        $this->actingAs($fx['superUserA'])
            ->get(route('settings.company.backups.download', [
                'filename' => $backupB['filename'],
                'company_id' => $fx['companyB']->id,
            ]))
            ->assertForbidden();

        $this->actingAs($fx['superUserA'])
            ->deleteJson(route('settings.company.backups.destroy', [
                'filename' => $backupB['filename'],
                'company_id' => $fx['companyB']->id,
            ]))
            ->assertForbidden();

        $companyBZipPath = Storage::disk('local')->path('companies/'.$fx['companyB']->id.'/backups/'.$backupB['filename']);
        $uploadedB = new UploadedFile($companyBZipPath, $backupB['filename'], 'application/zip', null, true);

        $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.restore'), ['backup_file' => $uploadedB])
            ->assertForbidden();
    }

    public function test_backup_exports_tenant_isolated_streaming_jsonl_manifest_and_physical_storage(): void
    {
        $fx = $this->seedTwoTenantGraph();

        $storeResp = $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.store'))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $backup = $storeResp->json('backup');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.zip$/', $backup['filename']);

        $this->actingAs($fx['superUserA'])
            ->getJson(route('settings.company.backups.index'))
            ->assertOk()
            ->assertJsonCount(1, 'backups')
            ->assertJsonPath('backups.0.filename', $backup['filename']);

        $this->actingAs($fx['superUserA'])
            ->get(route('settings.company.backups.download', ['filename' => $backup['filename']]))
            ->assertOk();

        $zipPath = Storage::disk('local')->path('companies/'.$fx['companyA']->id.'/backups/'.$backup['filename']);
        $this->assertFileExists($zipPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        try {
            $manifestRaw = $zip->getFromName('manifest.json');
            $this->assertNotFalse($manifestRaw);
            $manifest = json_decode($manifestRaw, true);

            $this->assertSame('1.0', $manifest['version']);
            $this->assertSame((string) $fx['companyA']->uuid, $manifest['company_uuid']);
            $this->assertSame('Novotel Yogyakarta Airport', $manifest['company_name']);
            $this->assertSame(1, $manifest['row_counts']['companies']);
            $this->assertSame(2, $manifest['row_counts']['employees']);
            $this->assertSame(1, $manifest['row_counts']['attendances']);
            $this->assertSame(1, $manifest['row_counts']['leave_requests']);
            $this->assertSame(2, $manifest['storage_files_count']);

            // Verify SHA-256 checksums & zero Company B leakage in JSONL streams
            foreach ($manifest['checksums'] as $relPath => $expectedHash) {
                $content = $zip->getFromName($relPath);
                $this->assertNotFalse($content, "Missing ZIP entry {$relPath}");
                $this->assertSame($expectedHash, hash('sha256', $content));
                $this->assertStringNotContainsString('Holiday Exclusive Company B', $content);
                $this->assertStringNotContainsString('Housekeeping B', $content);
                $this->assertStringNotContainsString('superuser.b@oatcake.test', $content);
            }

            // Verify physical storage entries are bundled for Company A only
            $this->assertSame('RIFFWEBP_BINARY_COMPANY_A', $zip->getFromName('storage/attendance_proofs/2026-11/selfie-a.webp'));
            $this->assertSame('%PDF-1.4 COMPANY A ATTACHMENT', $zip->getFromName('storage/leave_attachments/2026-11/surat-cuti.pdf'));
            $this->assertFalse($zip->getFromName('storage/attendance_proofs/2026-11/selfie-b.webp'));
        } finally {
            $zip->close();
        }
    }

    public function test_cross_server_restore_remaps_all_dag_ids_suppresses_audit_logs_and_swaps_storage(): void
    {
        $fx = $this->seedTwoTenantGraph();

        $backup = $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.store'))
            ->assertCreated()
            ->json('backup');

        $oldCompanyId = (int) $fx['companyA']->id;
        $companyUuid = (string) $fx['companyA']->uuid;
        $oldManagerEmpId = (int) $fx['managerEmpA']->id;
        $oldCreditLedgerId = (int) $fx['creditLedger']->id;
        $oldLeaveReqId = (int) $fx['leaveRequestA']->id;
        $expectedRestoredAuditCount = AuditLog::withoutGlobalScopes()->where('company_id', $oldCompanyId)->count();

        // Copy ZIP to temp test path, wipe Company A completely (simulating target server import)
        $portableZip = Storage::disk('local')->path('temp/portable-copy-'.Str::uuid().'.zip');
        File::ensureDirectoryExists(dirname($portableZip), 0755, true);
        File::copy(
            Storage::disk('local')->path("companies/{$oldCompanyId}/backups/{$backup['filename']}"),
            $portableZip
        );

        try {
            // Respect RESTRICT FKs while simulating a clean fresh destination server
            DB::table('leave_request_approvals')->where('company_id', $oldCompanyId)->delete();
            DB::table('leave_requests')->where('company_id', $oldCompanyId)->delete();
            DB::table('attendances')->where('company_id', $oldCompanyId)->delete();
            DB::table('leave_ledger_entries')->where('company_id', $oldCompanyId)->update(['source_entry_id' => null]);
            DB::table('leave_ledger_entries')->where('company_id', $oldCompanyId)->delete();
            DB::table('work_schedules')->where('company_id', $oldCompanyId)->delete();
            DB::table('employees')->where('company_id', $oldCompanyId)->update(['manager_id' => null]);
            DB::table('employees')->where('company_id', $oldCompanyId)->delete();
            DB::table('users')->where('company_id', $oldCompanyId)->delete();
            DB::table('audit_logs')->where('company_id', $oldCompanyId)->delete();
            DB::table('companies')->where('id', $oldCompanyId)->delete();

            File::deleteDirectory(Storage::disk('local')->path("companies/{$oldCompanyId}"));
            File::deleteDirectory(Storage::disk('local')->path("attendance_proofs/{$oldCompanyId}"));
            File::deleteDirectory(Storage::disk('local')->path("leave_attachments/{$oldCompanyId}"));

            // Insert filler rows on destination server so restored company & entities MUST receive shifted PKs
            $filler = Company::create([
                'name' => 'Filler Resort',
                'code' => 'FILLER',
                'latitude' => -8.0,
                'longitude' => 115.0,
                'geofence_radius' => 100,
                'timezone' => 'Asia/Jakarta',
            ]);
            $this->trackedCompanyIds[] = (int) $filler->id;

            $fillerDept = Department::create([
                'company_id' => $filler->id,
                'code' => 'FD',
                'name' => 'Filler Dept',
                'is_operational' => true,
                'min_staff_per_shift' => 1,
            ]);
            for ($k = 0; $k < 3; $k++) {
                $fu = User::factory()->create(['company_id' => $filler->id, 'role' => 'user']);
                Employee::create([
                    'company_id' => $filler->id,
                    'user_id' => $fu->id,
                    'department_id' => $fillerDept->id,
                    'position' => 'Filler Staff',
                    'join_date' => '2025-01-01',
                    'status' => 'active',
                ]);
            }

            $uploaded = new UploadedFile($portableZip, 'portable-backup.zip', 'application/zip', null, true);

            $restoreResp = $this->actingAs($fx['superAdmin'])
                ->postJson(route('settings.company.backups.restore'), ['backup_file' => $uploaded])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('summary.in_place_overwrite', false);

            $newCompanyId = (int) $restoreResp->json('summary.company_id');
            $this->trackedCompanyIds[] = $newCompanyId;

            $this->assertGreaterThan($oldCompanyId, $newCompanyId);
            $restoredCompany = Company::findOrFail($newCompanyId);
            $this->assertSame($companyUuid, (string) $restoredCompany->uuid);
            $this->assertSame('NVT-YIA', $restoredCompany->code);

            // Verify DAG remapping across every child tier
            $restoredStaffUser = User::where('email', 'staff.a@oatcake.test')->firstOrFail();
            $restoredManagerUser = User::where('email', 'manager.a@oatcake.test')->firstOrFail();

            $restoredManagerEmp = Employee::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->where('user_id', $restoredManagerUser->id)
                ->firstOrFail();
            $restoredStaffEmp = Employee::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->where('user_id', $restoredStaffUser->id)
                ->firstOrFail();

            $this->assertNotSame($oldManagerEmpId, (int) $restoredManagerEmp->id);
            $this->assertSame((int) $restoredManagerEmp->id, (int) $restoredStaffEmp->manager_id);
            $this->assertTrue($restoredManagerEmp->tags()->where('code', 'MOD_ELIGIBLE')->exists());

            // Verify Self-Ref FIFO Ledger source_entry_id + polymorphic reference_id
            $restoredCredit = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->where('transaction_type', 'credit')
                ->firstOrFail();
            $restoredDebit = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->where('transaction_type', 'debit')
                ->firstOrFail();
            $restoredLeave = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->firstOrFail();

            $this->assertNotSame($oldCreditLedgerId, (int) $restoredCredit->id);
            $this->assertNotSame($oldLeaveReqId, (int) $restoredLeave->id);
            $this->assertSame((int) $restoredCredit->id, (int) $restoredDebit->source_entry_id);
            $this->assertSame((int) $restoredLeave->id, (int) $restoredDebit->reference_id);
            $this->assertSame((int) $restoredDebit->id, (int) $restoredLeave->ledger_entry_id);
            $this->assertSame("leave_attachments/{$newCompanyId}/2026-11/surat-cuti.pdf", $restoredLeave->attachment_path);

            // Verify WorkSchedule LEAVE:{id}:AL note remapped
            $remappedLeaveSchedule = WorkSchedule::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->whereNull('shift_id')
                ->firstOrFail();
            $this->assertSame("LEAVE:{$restoredLeave->id}:AL", $remappedLeaveSchedule->notes);

            // Verify Attendance FKs & rewritten photo path
            $restoredAttendance = Attendance::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->firstOrFail();
            $this->assertSame((int) $restoredStaffEmp->id, (int) $restoredAttendance->employee_id);
            $this->assertSame((int) $restoredCredit->id, (int) $restoredAttendance->dp_ledger_entry_id);
            $this->assertSame("attendance_proofs/{$newCompanyId}/2026-11/selfie-a.webp", $restoredAttendance->proof_photo_path);

            // Verify LeaveRequestApproval FK
            $restoredApproval = LeaveRequestApproval::withoutGlobalScopes()
                ->where('company_id', $newCompanyId)
                ->firstOrFail();
            $this->assertSame((int) $restoredLeave->id, (int) $restoredApproval->leave_request_id);
            $this->assertSame((int) $restoredManagerUser->id, (int) $restoredApproval->approver_id);

            // Verify Physical Storage Swap under new company ID
            $this->assertSame(
                'RIFFWEBP_BINARY_COMPANY_A',
                Storage::disk('local')->get("attendance_proofs/{$newCompanyId}/2026-11/selfie-a.webp")
            );
            $this->assertSame(
                '%PDF-1.4 COMPANY A ATTACHMENT',
                Storage::disk('local')->get("leave_attachments/{$newCompanyId}/2026-11/surat-cuti.pdf")
            );

            // Verify Audit Trail suppression prevented synthetic restore audit spam
            $restoredAuditCount = AuditLog::withoutGlobalScopes()->where('company_id', $newCompanyId)->count();
            $this->assertSame($expectedRestoredAuditCount, $restoredAuditCount);
            $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);
        } finally {
            @unlink($portableZip);
        }
    }

    public function test_in_place_restore_overwrites_existing_company_and_preserves_backup_zips(): void
    {
        $fx = $this->seedTwoTenantGraph();

        $backup = $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.store'))
            ->assertCreated()
            ->json('backup');

        $zipPath = Storage::disk('local')->path('companies/'.$fx['companyA']->id.'/backups/'.$backup['filename']);

        // Mutate live Company A state after backup was taken
        $fx['companyA']->update(['name' => 'Mutated After Backup']);
        Department::create([
            'company_id' => $fx['companyA']->id,
            'code' => 'SPURIOUS',
            'name' => 'Temporary Department',
            'is_operational' => false,
            'min_staff_per_shift' => 1,
        ]);
        Storage::disk('local')->put("companies/{$fx['companyA']->id}/stale-file.txt", 'STALE');

        $uploaded = new UploadedFile($zipPath, $backup['filename'], 'application/zip', null, true);

        $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.restore'), ['backup_file' => $uploaded])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.in_place_overwrite', true);

        $this->assertSame('Novotel Yogyakarta Airport', $fx['companyA']->fresh()->name);
        $this->assertDatabaseMissing('departments', [
            'company_id' => $fx['companyA']->id,
            'code' => 'SPURIOUS',
        ]);

        // Existing .zip archive in backups/ directory must remain preserved
        $this->assertFileExists($zipPath);
    }

    public function test_restore_rolls_back_atomically_and_cleans_temp_dirs_on_errors(): void
    {
        $fx = $this->seedTwoTenantGraph();
        $initialCompaniesCount = Company::count();

        // A. Invalid / corrupted non-zip binary
        $badZip = UploadedFile::fake()->createWithContent('corrupt.zip', 'NOT_A_REAL_ZIP_ARCHIVE');
        $this->actingAs($fx['superAdmin'])
            ->postJson(route('settings.company.backups.restore'), ['backup_file' => $badZip])
            ->assertStatus(422);
        $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);

        // B. Valid ZIP missing manifest.json
        $noManifestPath = Storage::disk('local')->path('temp/test-no-manifest.zip');
        File::ensureDirectoryExists(dirname($noManifestPath), 0755, true);
        $z = new ZipArchive;
        $z->open($noManifestPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('database/companies.jsonl', "{}\n");
        $z->close();

        $this->actingAs($fx['superAdmin'])
            ->postJson(route('settings.company.backups.restore'), [
                'backup_file' => new UploadedFile($noManifestPath, 'no-manifest.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422);
        @unlink($noManifestPath);
        $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);

        // C. Zip-Slip traversal entry (../evil.txt)
        $zipSlipPath = Storage::disk('local')->path('temp/test-zipslip.zip');
        $z = new ZipArchive;
        $z->open($zipSlipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('../evil-escape.txt', 'pwnd');
        $z->close();

        $this->actingAs($fx['superAdmin'])
            ->postJson(route('settings.company.backups.restore'), [
                'backup_file' => new UploadedFile($zipSlipPath, 'zipslip.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422);
        @unlink($zipSlipPath);
        $this->assertFileDoesNotExist(Storage::disk('local')->path('temp/evil-escape.txt'));
        $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);

        // D. Mid-transaction DB failure (duplicate department code in dummy ZIP) -> atomic rollback!
        $companiesLine = json_encode([
            'id' => 999,
            'uuid' => (string) Str::uuid(),
            'code' => 'ROLLBACK-TEST',
            'name' => 'Should Not Persist Co',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'geofence_radius' => 100,
            'geofence_radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => 1,
        ])."\n";

        $departmentsLines = json_encode([
            'id' => 10,
            'uuid' => (string) Str::uuid(),
            'company_id' => 999,
            'code' => 'DUP_CODE',
            'name' => 'First Dept',
        ])."\n".json_encode([
            'id' => 11,
            'uuid' => (string) Str::uuid(),
            'company_id' => 999,
            'code' => 'DUP_CODE',
            'name' => 'Duplicate Code Violation',
        ])."\n";

        $manifest = [
            'version' => '1.0',
            'format' => 'oatcake-jsonl-v1',
            'company_id' => 999,
            'company_uuid' => json_decode($companiesLine, true)['uuid'],
            'company_name' => 'Should Not Persist Co',
            'created_at' => now('Asia/Jakarta')->toIso8601String(),
            'tables' => ['companies', 'departments'],
            'checksums' => [
                'database/companies.jsonl' => hash('sha256', $companiesLine),
                'database/departments.jsonl' => hash('sha256', $departmentsLines),
            ],
        ];

        $rollbackZipPath = Storage::disk('local')->path('temp/test-rollback.zip');
        $z = new ZipArchive;
        $z->open($rollbackZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('manifest.json', json_encode($manifest));
        $z->addFromString('database/companies.jsonl', $companiesLine);
        $z->addFromString('database/departments.jsonl', $departmentsLines);
        $z->close();

        $this->actingAs($fx['superAdmin'])
            ->postJson(route('settings.company.backups.restore'), [
                'backup_file' => new UploadedFile($rollbackZipPath, 'rollback.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422);
        @unlink($rollbackZipPath);

        $this->assertDatabaseMissing('companies', ['code' => 'ROLLBACK-TEST']);
        $this->assertSame($initialCompaniesCount, Company::count());
        $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);

        // E. Tampered checksum mismatch rejected before DB mutation & temp dir cleaned up
        $manifest['checksums']['database/companies.jsonl'] = str_repeat('0', 64);
        $tamperZipPath = Storage::disk('local')->path('temp/test-tamper.zip');
        $z = new ZipArchive;
        $z->open($tamperZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('manifest.json', json_encode($manifest));
        $z->addFromString('database/companies.jsonl', $companiesLine);
        $z->addFromString('database/departments.jsonl', $departmentsLines);
        $z->close();

        $this->actingAs($fx['superAdmin'])
            ->postJson(route('settings.company.backups.restore'), [
                'backup_file' => new UploadedFile($tamperZipPath, 'tamper.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
        @unlink($tamperZipPath);
        $this->assertSame([], glob(Storage::disk('local')->path('temp/restore_*')) ?: []);
    }

    public function test_delete_backup_endpoint_removes_archive_and_download_returns_404(): void
    {
        $fx = $this->seedTwoTenantGraph();

        $backup = $this->actingAs($fx['superUserA'])
            ->postJson(route('settings.company.backups.store'))
            ->assertCreated()
            ->json('backup');

        $this->actingAs($fx['superUserA'])
            ->deleteJson(route('settings.company.backups.destroy', ['filename' => $backup['filename']]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($fx['superUserA'])
            ->getJson(route('settings.company.backups.index'))
            ->assertOk()
            ->assertJsonCount(0, 'backups');

        $this->actingAs($fx['superUserA'])
            ->get(route('settings.company.backups.download', ['filename' => $backup['filename']]))
            ->assertNotFound();
    }
}
