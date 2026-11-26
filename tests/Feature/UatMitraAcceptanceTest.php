<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Database\Seeders\UatMitraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UatMitraAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Seeder & command oatcake:prepare-uat idempoten, 8 departemen, 6 shift, 5 peran RBAC, Zero NIK.
     */
    public function test_scenario_1_prepare_uat_command_is_idempotent_and_complies_with_zero_nik(): void
    {
        $this->artisan('oatcake:prepare-uat')->assertExitCode(0);
        $this->artisan('oatcake:prepare-uat')->assertExitCode(0);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $this->assertSame('Novotel Yogyakarta International Airport Kulon Progo', $company->name);
        $this->assertSame(100, (int) $company->geofence_radius_meters);

        $this->assertSame(8, Department::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertGreaterThanOrEqual(6, Shift::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertGreaterThanOrEqual(13, PublicHoliday::withoutGlobalScopes()->where('company_id', $company->id)->count());

        // Verifikasi 5 peran RBAC tersedia
        foreach ([
            'superadmin.uat@oatcake.test' => 'super_admin',
            'gm.uat@oatcake.test' => 'super_user',
            'hr.uat@oatcake.test' => 'admin_hr',
            'fom.uat@oatcake.test' => 'manager',
            'fo.staff1@oatcake.test' => 'user',
        ] as $email => $expectedRole) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user);
            $this->assertSame($expectedRole, $user->role);
        }

        // Strict Zero NIK pada seeder & laporan UAT
        $this->assertStringNotContainsString("'nik'", File::get(database_path('seeders/UatMitraSeeder.php')));
        $reportPath = base_path('docs/UAT_MITRA_REPORT.md');
        $this->assertFileExists($reportPath);
        $this->assertStringContainsString('US-29', File::get($reportPath));
    }

    /**
     * Skenario UAT 1: Siklus penjadwalan Roster, publikasi, notifikasi push & snapshot PWA staf.
     */
    public function test_uat_1_roster_assignment_publish_notification_and_pwa_offline_snapshot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));
        $this->seed(UatMitraSeeder::class);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();
        $shiftAF = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'AF')->firstOrFail();

        $fomUser = User::where('email', 'fom.uat@oatcake.test')->firstOrFail();
        $foStaff1 = User::where('email', 'fo.staff1@oatcake.test')->with('employee')->firstOrFail();
        $assignDate = Carbon::today('Asia/Jakarta')->addDays(12)->toDateString();

        // Bersihkan jadwal tanggal +12 agar assign menuliskan draf baru yang bersih
        WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $foStaff1->employee->id)
            ->whereDate('schedule_date', $assignDate)
            ->delete();

        $this->actingAs($fomUser)->postJson('/roster/assign', [
            'employee_id' => $foStaff1->employee->id,
            'shift_id' => $shiftAF->id,
            'schedule_date' => $assignDate,
        ])->assertOk();

        $this->actingAs($fomUser)->postJson('/roster/publish', [
            'month' => (int) Carbon::parse($assignDate)->month,
            'year' => (int) Carbon::parse($assignDate)->year,
            'department_id' => $foDept->id,
        ])->assertOk();

        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $foStaff1->employee->id,
            'schedule_date' => $assignDate,
            'status' => 'published',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaff1->id,
            'category' => 'roster_published',
        ]);

        $snapshotResp = $this->actingAs($foStaff1)->getJson('/pwa/offline-snapshot');
        $snapshotResp->assertOk();
        $dates = array_column($snapshotResp->json('upcoming_schedules'), 'date');
        $this->assertContains($assignDate, $dates);
    }

    /**
     * Skenario UAT 2: Siklus presensi Hari Libur Nasional & Two-Phase DP Commit (+1.00 DP).
     */
    public function test_uat_2_holiday_attendance_two_phase_dp_commit_lifecycle(): void
    {
        Storage::fake('local');
        $this->seed(UatMitraSeeder::class);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $holidayDate = Carbon::today('Asia/Jakarta')->addDays(10); // Anniversary Novotel YIA dari seeder
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MO')->firstOrFail();
        $fbsStaff = User::where('email', 'fbs.staff1@oatcake.test')->with('employee')->firstOrFail();

        WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $fbsStaff->employee->id)
            ->whereDate('schedule_date', $holidayDate->toDateString())
            ->delete();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'employee_id' => $fbsStaff->employee->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => $holidayDate->toDateString(),
            'status' => 'published',
        ]);

        $ledgerService = app(LeaveLedgerService::class);
        $this->assertSame(0, $ledgerService->getBalance($fbsStaff->employee, 'DP'));

        // Phase 1: Clock-In pada tanggal merah jam 07:05 WIB -> provisional DP saja, saldo belum naik
        Carbon::setTestNow($holidayDate->copy()->setTime(7, 5, 0));
        $clockInResp = $this->actingAs($fbsStaff)->postJson('/attendance/clock-in', [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('holiday_in.jpg', 640, 480),
        ]);
        $clockInResp->assertOk();
        $this->assertTrue((bool) $clockInResp->json('data.is_provisional_dp'));
        $this->assertSame(0, $ledgerService->getBalance($fbsStaff->employee, 'DP'));

        // Phase 2: Clock-Out setelah >= 8 jam (15:10 WIB) -> +1 DP terkomit otomatis
        Carbon::setTestNow($holidayDate->copy()->setTime(15, 10, 0));
        $clockOutResp = $this->actingAs($fbsStaff)->postJson('/attendance/clock-out', [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('holiday_out.jpg', 640, 480),
        ]);
        $clockOutResp->assertOk();

        $this->assertSame(1, $ledgerService->getBalance($fbsStaff->employee, 'DP'));
        $attendance = Attendance::withoutGlobalScopes()->where('employee_id', $fbsStaff->employee->id)->latest('id')->firstOrFail();
        $this->assertNotNull($attendance->dp_committed_at);
    }

    /**
     * Skenario UAT 3: Presensi dinas luar & verifikasi spasial oleh Manajer + Audit Log.
     */
    public function test_uat_3_outside_duty_attendance_and_manager_verification_lifecycle(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 8, 30, 0, 'Asia/Jakarta'));
        $this->seed(UatMitraSeeder::class);

        $fomUser = User::where('email', 'fom.uat@oatcake.test')->firstOrFail();
        $foStaff2 = User::where('email', 'fo.staff2@oatcake.test')->with('employee')->firstOrFail();

        $outsideResp = $this->actingAs($foStaff2)->postJson('/attendance/outside-clock-in', [
            'latitude' => -7.795580,
            'longitude' => 110.369490,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Terminal Kedatangan YIA',
            'notes' => 'Penjemputan tamu VIP grup korporat',
            'photo' => UploadedFile::fake()->image('outside_yia.jpg', 640, 480),
        ]);

        $outsideResp->assertCreated();
        $attUuid = $outsideResp->json('data.uuid');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $fomUser->id,
            'category' => 'attendance_outside_pending',
        ]);

        $this->actingAs($fomUser)->postJson("/attendance/verifications/{$attUuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'on_time');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaff2->id,
            'category' => 'attendance_verified',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'attendance',
            'event_type' => 'verified',
        ]);
    }

    /**
     * Skenario UAT 4: Cuti bentrok, SPK SMART Mode A, persetujuan 2-Tier, pemotongan FIFO, Roster Lock & Conflict Guard.
     */
    public function test_uat_4_conflicting_leave_smart_mode_a_two_tier_approval_and_roster_lock(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));
        $this->seed(UatMitraSeeder::class);

        $fomUser = User::where('email', 'fom.uat@oatcake.test')->firstOrFail();
        $hrUser = User::where('email', 'hr.uat@oatcake.test')->firstOrFail();
        $foStaff1 = User::where('email', 'fo.staff1@oatcake.test')->with('employee')->firstOrFail();
        $foStaff2 = User::where('email', 'fo.staff2@oatcake.test')->with('employee')->firstOrFail();
        $shiftAF = Shift::withoutGlobalScopes()->where('company_id', $foStaff1->company_id)->where('code', 'AF')->firstOrFail();

        $req1 = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $foStaff1->employee->id)
            ->where('status', LeaveRequest::STATUS_PENDING_MANAGER)
            ->firstOrFail();
        $req2 = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $foStaff2->employee->id)
            ->where('status', LeaveRequest::STATUS_PENDING_MANAGER)
            ->firstOrFail();

        // Manajer FO memeriksa SPK SMART Mode A
        $smartResp = $this->actingAs($fomUser)->getJson("/leave/{$req1->uuid}/smart-analysis");
        $smartResp->assertOk()
            ->assertJsonPath('data.mode', 'A')
            ->assertJsonPath('data.total_candidates', 2);

        $topCandidateUuid = $smartResp->json('data.candidates.0.leave_request_uuid');
        $topRequest = LeaveRequest::withoutGlobalScopes()->where('uuid', $topCandidateUuid)->firstOrFail();
        $otherRequest = $topRequest->id === $req1->id ? $req2 : $req1;
        $otherInitialScore = (float) $otherRequest->smart_score;

        // Tier 1 Approve oleh Manajer FO
        $this->actingAs($fomUser)->postJson("/leave/{$topRequest->uuid}/approve-tier1", [
            'notes' => 'Prioritas peringkat #1 SPK SMART',
        ])->assertOk()->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING_HR);

        // Tier 2 Approve oleh HR Admin -> Saldo terpotong FIFO, Roster terkunci LEAVE:, skor kandidat ke-2 dihitung ulang
        $this->actingAs($hrUser)->postJson("/leave/{$topRequest->uuid}/approve-tier2", [
            'notes' => 'Disahkan HR UAT',
        ])->assertOk()->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);

        $topEmployee = Employee::withoutGlobalScopes()->findOrFail($topRequest->employee_id);
        $this->assertSame(10, app(LeaveLedgerService::class)->getBalance($topEmployee, 'AL'));

        $lockedDate = Carbon::parse($topRequest->start_date, 'Asia/Jakarta')->toDateString();
        $lockedWs = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $topEmployee->id)
            ->whereDate('schedule_date', $lockedDate)
            ->firstOrFail();
        $this->assertTrue($lockedWs->isLockedLeave());

        // Roster Conflict Guard menolak penimpaan shift pada tanggal cuti disetujui dengan HTTP 422
        $this->actingAs($fomUser)->postJson('/roster/assign', [
            'employee_id' => $topEmployee->id,
            'shift_id' => $shiftAF->id,
            'schedule_date' => $lockedDate,
        ])->assertStatus(422);

        // Kandidat kedua tetap tercatat dan evaluasi ulang berjalan
        $this->assertNotNull($otherRequest->fresh()->smart_score);
        $this->assertLessThanOrEqual($otherInitialScore, (float) $otherRequest->fresh()->smart_score);
    }

    /**
     * Skenario UAT 5: Pembatalan cuti approved -> refund buku besar append-only, buka kunci roster, & inspeksi log audit JSONB.
     */
    public function test_uat_5_approved_leave_cancellation_refunds_ledger_unlocks_roster_and_records_jsonb_audit(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 10, 0, 0, 'Asia/Jakarta'));
        $this->seed(UatMitraSeeder::class);

        $hrUser = User::where('email', 'hr.uat@oatcake.test')->firstOrFail();
        $foStaff3 = User::where('email', 'fo.staff3@oatcake.test')->with('employee')->firstOrFail();

        $pendingHrReq = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $foStaff3->employee->id)
            ->where('status', LeaveRequest::STATUS_PENDING_HR)
            ->firstOrFail();

        // HR menyetujui Tier 2 terlebih dahulu (saldo 12 -> 11)
        $this->actingAs($hrUser)->postJson("/leave/{$pendingHrReq->uuid}/approve-tier2", [
            'notes' => 'Approved sebelum uji pembatalan',
        ])->assertOk();

        $ledgerService = app(LeaveLedgerService::class);
        $this->assertSame(11, $ledgerService->getBalance($foStaff3->employee, 'AL'));

        // Staf membatalkan cuti approved -> saldo kembali ke 12 melalui entri refund append-only
        $this->actingAs($foStaff3)->postJson("/leave/{$pendingHrReq->uuid}/cancel", [
            'cancellation_reason' => 'Acara keluarga ditunda ke bulan depan',
        ])->assertOk()->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELLED);

        $this->assertSame(12, $ledgerService->getBalance($foStaff3->employee, 'AL'));
        $this->assertDatabaseHas('leave_ledger_entries', [
            'employee_id' => $foStaff3->employee->id,
            'reference_type' => 'leave_cancellation',
            'reference_id' => $pendingHrReq->id,
        ]);

        // Kunci LEAVE: pada tanggal terkait telah dilepas
        $leaveDate = Carbon::parse($pendingHrReq->start_date, 'Asia/Jakarta')->toDateString();
        $this->assertFalse(
            WorkSchedule::withoutGlobalScopes()
                ->where('employee_id', $foStaff3->employee->id)
                ->whereDate('schedule_date', $leaveDate)
                ->where('notes', 'like', 'LEAVE:%')
                ->exists()
        );

        // HR Admin memeriksa log audit JSONB dan diff side-by-side
        $auditResp = $this->actingAs($hrUser)->get('/audit-logs?auditable_type=leave_request&event_type=approved_tier2');
        $auditResp->assertOk();

        $approvedAudit = AuditLog::withoutGlobalScopes()
            ->where('company_id', $hrUser->company_id)
            ->where('auditable_type', 'leave_request')
            ->where('auditable_id', $pendingHrReq->id)
            ->where('event_type', 'approved_tier2')
            ->firstOrFail();

        $this->actingAs($hrUser)->getJson("/audit-logs/{$approvedAudit->uuid}")
            ->assertOk()
            ->assertJsonPath('new_values.status', LeaveRequest::STATUS_APPROVED)
            ->assertJsonPath('old_values.status', LeaveRequest::STATUS_PENDING_HR);
    }
}
