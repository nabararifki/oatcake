<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveApprovalService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use App\Services\SmartDecisionService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Department $hkDept;
    protected User $staffUserA;
    protected Employee $staffEmployeeA;
    protected User $staffUserB;
    protected Employee $staffEmployeeB;
    protected User $staffUserC;
    protected Employee $staffEmployeeC;
    protected User $hkStaffUser;
    protected Employee $hkStaffEmployee;
    protected User $foManagerUser;
    protected Employee $foManagerEmployee;
    protected User $hkManagerUser;
    protected Employee $hkManagerEmployee;
    protected User $adminUser;
    protected LeaveLedgerService $ledgerService;
    protected LeaveRequestService $leaveRequestService;
    protected LeaveApprovalService $approvalService;
    protected SmartDecisionService $smartService;

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

        $this->foDept->update(['min_staff_per_shift' => 2]);

        $this->hkDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'HK')
            ->firstOrFail();

        $this->staffUserA = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina.fo',
            'email' => 'rina.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->staffEmployeeA = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->staffUserA->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2024-01-10',
            'status' => 'active',
        ]);

        $this->staffUserB = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Dimas Pratama',
            'username' => 'dimas.fo',
            'email' => 'dimas.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->staffEmployeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->staffUserB->id,
            'department_id' => $this->foDept->id,
            'position' => 'Bell Driver',
            'join_date' => '2024-03-01',
            'status' => 'active',
        ]);

        $this->staffUserC = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Siti Rahma',
            'username' => 'siti.fo',
            'email' => 'siti.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->staffEmployeeC = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->staffUserC->id,
            'department_id' => $this->foDept->id,
            'position' => 'Night Auditor',
            'join_date' => '2024-05-01',
            'status' => 'active',
        ]);

        $this->hkStaffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Eko Saputra',
            'username' => 'eko.hk',
            'email' => 'eko.hk@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->hkStaffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->hkStaffUser->id,
            'department_id' => $this->hkDept->id,
            'position' => 'Room Attendant',
            'join_date' => '2024-02-01',
            'status' => 'active',
        ]);

        $this->foManagerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Hendra Wijaya',
            'username' => 'hendra.fo.mgr',
            'email' => 'hendra.mgr@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->foManagerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->foManagerUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2023-01-10',
            'status' => 'active',
        ]);

        $this->hkManagerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Dewi Lestari',
            'username' => 'dewi.hk.mgr',
            'email' => 'dewi.mgr@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->hkManagerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->hkManagerUser->id,
            'department_id' => $this->hkDept->id,
            'position' => 'Executive Housekeeper',
            'join_date' => '2023-02-15',
            'status' => 'active',
        ]);

        $this->adminUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Kartika HR',
            'username' => 'kartika.hr',
            'email' => 'kartika.hr@novotelyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->ledgerService = app(LeaveLedgerService::class);
        $this->leaveRequestService = app(LeaveRequestService::class);
        $this->approvalService = app(LeaveApprovalService::class);
        $this->smartService = app(SmartDecisionService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1 — Persetujuan Tier 1 (Manajer Departemen):
     * - Manajer departemen menyetujui pengajuan staf berstatus pending_manager via POST /leave/{leaveRequest}/approve-tier1.
     * - Status berubah menjadi pending_hr, baris leave_request_approvals (tier = 1, action = 'approved') terbentuk beserta smart_score_snapshot,
     *   dan saldo buku besar (leave_ledger_entries) BELUM terpotong.
     */
    public function test_scenario_1_tier_1_approval_by_department_manager_transitions_to_pending_hr_without_ledger_debit(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'reason' => 'Cuti keluarga tahunan ke luar kota',
        ]);

        $this->assertSame(LeaveRequest::STATUS_PENDING_MANAGER, $leaveRequest->status);
        $this->assertNotNull($leaveRequest->smart_score);

        $response = $this->actingAs($this->foManagerUser)->postJson(
            route('leave.approveTier1', $leaveRequest),
            ['notes' => 'Operasional FO aman, disetujui ke HR.']
        );

        $response->assertOk()->assertJsonPath('status', 'ok');

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_HR, $leaveRequest->status);
        $this->assertSame($this->foManagerUser->id, $leaveRequest->manager_approved_by);
        $this->assertNotNull($leaveRequest->manager_approved_at);
        $this->assertNull($leaveRequest->ledger_entry_id);

        // Verify leave_request_approvals audit record
        $approval = LeaveRequestApproval::withoutGlobalScopes()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tier', LeaveRequestApproval::TIER_MANAGER)
            ->first();

        $this->assertNotNull($approval);
        $this->assertSame(LeaveRequestApproval::ACTION_APPROVED, $approval->action);
        $this->assertSame($this->foManagerUser->id, $approval->approver_id);
        $this->assertSame('Operasional FO aman, disetujui ke HR.', $approval->notes);
        $this->assertEquals((float) $leaveRequest->smart_score, (float) $approval->smart_score_snapshot);

        // Verify ledger has NO debit yet (active balance still 12.00, reserved 3.00, available 9.00)
        $debitCount = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->where('transaction_type', LeaveLedgerEntry::TYPE_DEBIT)
            ->count();
        $this->assertSame(0, $debitCount);

        $quota = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(12, $quota['ledger_balance']);
        $this->assertSame(3, $quota['reserved_days']);
        $this->assertSame(9, $quota['available_balance']);
    }

    /**
     * Skenario 2 — Isolasi RBAC Tier 1 & Self-Approval Guard:
     * - Manajer dari departemen berbeda (HK) mencoba menyetujui pengajuan staf FO -> ditolak HTTP 403.
     * - Staf biasa mencoba memanggil endpoint Tier 1 -> ditolak HTTP 403.
     * - Manajer mengajukan cuti dan mencoba menyetujui pengajuannya sendiri pada Tier 1 -> ditolak HTTP 403.
     */
    public function test_scenario_2_tier_1_rbac_isolation_and_self_approval_guard(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $this->ledgerService->recordAccrual($this->foManagerEmployee, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $staffRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'reason' => 'Pengajuan cuti staf FO',
        ]);

        // 1. Manager from another department (HK) cannot approve FO staff's request -> 403
        $this->actingAs($this->hkManagerUser)
            ->postJson(route('leave.approveTier1', $staffRequest), ['notes' => 'Cross-dept attempt'])
            ->assertForbidden();

        // 2. Regular staff user cannot call Tier 1 endpoint -> 403
        $this->actingAs($this->staffUserB)
            ->postJson(route('leave.approveTier1', $staffRequest), ['notes' => 'Peer staff attempt'])
            ->assertForbidden();

        // 3. Manager submits their own leave request and tries to self-approve on Tier 1 -> 403
        $managerRequest = $this->leaveRequestService->submitRequest($this->foManagerEmployee, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-06',
            'reason' => 'Pengajuan cuti manajer FO pribadi',
        ]);
        $managerRequest->update(['status' => LeaveRequest::STATUS_PENDING_MANAGER]);

        $this->actingAs($this->foManagerUser)
            ->postJson(route('leave.approveTier1', $managerRequest), ['notes' => 'Self-approve attempt'])
            ->assertForbidden();

        // Admin/HR can approve the manager's Tier 1 request
        $this->actingAs($this->adminUser)
            ->postJson(route('leave.approveTier1', $managerRequest), ['notes' => 'Approved by HR on Tier 1'])
            ->assertOk();
    }

    /**
     * Skenario 3 — Persetujuan Final Tier 2 (HR/Admin) & Pemotongan Saldo Buku Besar Atomik (FIFO):
     * - Admin/HR menyetujui pengajuan berstatus pending_hr (3 hari cuti AL) via POST /leave/{leaveRequest}/approve-tier2.
     * - Status berubah menjadi approved, baris leave_request_approvals (tier = 2, action = 'approved') terbentuk.
     * - Buku besar leave_ledger_entries mengalami deplesi FIFO (transaction_type = 'debit', amount = -3.00,
     *   remaining_amount bucket akrual berkurang dari 12.00 menjadi 9.00), dan leave_requests.ledger_entry_id terisi dengan ID entri debit.
     * - Manajer biasa mencoba memanggil endpoint Tier 2 -> ditolak HTTP 403.
     */
    public function test_scenario_3_tier_2_final_approval_and_atomic_fifo_ledger_debit(): void
    {
        $accrualEntry = $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-09',
            'reason' => 'Cuti tahunan 3 hari kerja efektif',
        ]);

        // Tier 1 approval by FO Manager
        $this->approvalService->approveTier1($leaveRequest, $this->foManagerUser, 'Disetujui Tier 1');
        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_HR, $leaveRequest->status);

        // Manager cannot call Tier 2 endpoint -> 403
        $this->actingAs($this->foManagerUser)
            ->postJson(route('leave.approveTier2', $leaveRequest), ['notes' => 'Manager trying Tier 2'])
            ->assertForbidden();

        // Admin/HR approves Tier 2
        $response = $this->actingAs($this->adminUser)->postJson(
            route('leave.approveTier2', $leaveRequest),
            ['notes' => 'Disetujui final oleh HR.']
        );

        $response->assertOk()->assertJsonPath('status', 'ok');

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leaveRequest->status);
        $this->assertSame($this->adminUser->id, $leaveRequest->hr_approved_by);
        $this->assertNotNull($leaveRequest->hr_approved_at);
        $this->assertNotNull($leaveRequest->ledger_entry_id);

        // Verify Tier 2 approval record
        $tier2Approval = LeaveRequestApproval::withoutGlobalScopes()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tier', LeaveRequestApproval::TIER_HR)
            ->first();
        $this->assertNotNull($tier2Approval);
        $this->assertSame(LeaveRequestApproval::ACTION_APPROVED, $tier2Approval->action);
        $this->assertSame($this->adminUser->id, $tier2Approval->approver_id);
        $this->assertSame('Disetujui final oleh HR.', $tier2Approval->notes);

        // Verify FIFO Ledger Debit
        $debitEntry = LeaveLedgerEntry::withoutGlobalScopes()->find($leaveRequest->ledger_entry_id);
        $this->assertNotNull($debitEntry);
        $this->assertSame(LeaveLedgerEntry::TYPE_DEBIT, $debitEntry->transaction_type);
        $this->assertSame(-3, (int) $debitEntry->amount);
        $this->assertSame('leave_request', $debitEntry->reference_type);
        $this->assertSame($leaveRequest->id, $debitEntry->reference_id);

        // Verify accrual bucket remaining_amount decreased from 12 to 9
        $accrualEntry->refresh();
        $this->assertSame(9, (int) $accrualEntry->remaining_amount);

        // Verify quota summary: ledger 9, reserved 0, available 9
        $quota = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(9, $quota['ledger_balance']);
        $this->assertSame(0, $quota['reserved_days']);
        $this->assertSame(9, $quota['available_balance']);
    }

    /**
     * Skenario 4 — Penolakan Cuti (Rejection) & Pelepasan Kuota Tereservasi:
     * - Penolakan tanpa alasan atau alasan < 5 karakter gagal validasi (HTTP 422).
     * - Penolakan dengan alasan valid mengubah status menjadi rejected, mengisi rejection_reason,
     *   tidak memotong buku besar, dan mengembalikan available_balance ke nilai semula (reserved_days berkurang).
     */
    public function test_scenario_4_rejection_validation_and_reserved_quota_release(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'Cuti liburan akhir tahun',
        ]);

        // Before rejection: ledger 12, reserved 2, available 10
        $quotaBefore = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(12, $quotaBefore['ledger_balance']);
        $this->assertSame(2, $quotaBefore['reserved_days']);
        $this->assertSame(10, $quotaBefore['available_balance']);

        // 1. Rejection without reason -> 422
        $this->actingAs($this->foManagerUser)
            ->postJson(route('leave.reject', $leaveRequest), ['reason' => ''])
            ->assertStatus(422);

        // 2. Rejection with < 5 chars -> 422
        $this->actingAs($this->foManagerUser)
            ->postJson(route('leave.reject', $leaveRequest), ['reason' => 'No'])
            ->assertStatus(422);

        // 3. Valid rejection by department manager
        $this->actingAs($this->foManagerUser)
            ->postJson(route('leave.reject', $leaveRequest), [
                'reason' => 'Peak season event konferensi nasional di hotel.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_REJECTED, $leaveRequest->status);
        $this->assertSame('Peak season event konferensi nasional di hotel.', $leaveRequest->rejection_reason);
        $this->assertSame($this->foManagerUser->id, $leaveRequest->rejected_by);
        $this->assertNotNull($leaveRequest->rejected_at);
        $this->assertNull($leaveRequest->ledger_entry_id);

        // Verify rejection audit record in leave_request_approvals
        $rejectionLog = LeaveRequestApproval::withoutGlobalScopes()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('action', LeaveRequestApproval::ACTION_REJECTED)
            ->first();
        $this->assertNotNull($rejectionLog);
        $this->assertSame(LeaveRequestApproval::TIER_MANAGER, $rejectionLog->tier);

        // Verify available_balance restored to 12 and reserved_days back to 0
        $quotaAfter = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(12, $quotaAfter['ledger_balance']);
        $this->assertSame(0, $quotaAfter['reserved_days']);
        $this->assertSame(12, $quotaAfter['available_balance']);
    }

    /**
     * Skenario 5 — Sinkronisasi Kalender Roster Dua Arah & Roster Conflict Guard:
     * - Staf memiliki jadwal shift pagi (MO) pada tanggal 2026-12-14 dan 2026-12-15.
     * - Saat pengajuan cutinya pada tanggal tersebut disetujui final (Tier 2), jadwal roster pada kedua tanggal tersebut
     *   otomatis berubah menjadi shift OFF dengan notes = 'LEAVE:AL'.
     * - Ketika Manajer mencoba menjadwalkan ulang shift kerja (MO) kepada staf tersebut pada tanggal 2026-12-14
     *   melalui POST /roster/assign, sistem menolak dengan HTTP 422 karena staf sedang berstatus cuti disetujui.
     */
    public function test_scenario_5_two_way_roster_sync_and_roster_conflict_guard(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        foreach (['2026-12-14', '2026-12-15'] as $dateStr) {
            WorkSchedule::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $this->staffEmployeeA->id,
                'shift_id' => $morningShift->id,
                'schedule_date' => $dateStr,
                'status' => 'scheduled',
                'published_at' => now(),
            ]);
        }

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-14',
            'end_date' => '2026-12-15',
            'reason' => 'Cuti terjadwal sinkronisasi roster',
        ]);

        // Approve Tier 1 & Tier 2
        $this->approvalService->approveTier1($leaveRequest, $this->foManagerUser, 'OK Tier 1');
        $this->approvalService->approveTier2($leaveRequest->fresh(), $this->adminUser, 'OK Tier 2');

        $offShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'OFF')
            ->firstOrFail();

        // Verify both dates in work_schedules are now synced to OFF shift with structured LEAVE:AL:{id}|... notes
        $lockedSchedules = [];
        foreach (['2026-12-14', '2026-12-15'] as $dateStr) {
            $schedule = WorkSchedule::withoutGlobalScopes()
                ->with('shift')
                ->where('employee_id', $this->staffEmployeeA->id)
                ->whereDate('schedule_date', $dateStr)
                ->firstOrFail();

            $this->assertSame($offShift->id, $schedule->shift_id);
            $this->assertTrue($schedule->isLockedLeave());
            $this->assertStringStartsWith("LEAVE:AL:{$leaveRequest->id}|", (string) $schedule->notes);
            $this->assertStringContainsString('Cuti terjadwal sinkronisasi roster', (string) $schedule->notes);

            $payload = $schedule->toMatrixPayload();
            $this->assertTrue($payload['is_leave_locked']);
            $this->assertSame('OFF • AL', $payload['leave_label']);
            $this->assertStringContainsString('Cuti terjadwal sinkronisasi roster', (string) $payload['leave_remarks']);

            $lockedSchedules[$dateStr] = $schedule;
        }

        $targetSchedule = $lockedSchedules['2026-12-14'];

        // 1. Attempt to assign Morning Shift (MO) on 2026-12-14 via POST /roster/assign -> rejected with HTTP 422
        $this->actingAs($this->foManagerUser)->postJson(route('roster.assign'), [
            'employee_id' => $this->staffEmployeeA->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-12-14',
        ])->assertStatus(422)->assertJsonValidationErrors(['schedule_date']);

        // 2. Attempt to resize locked leave schedule via PATCH /roster/{workSchedule}/resize -> rejected with HTTP 422
        $this->actingAs($this->foManagerUser)->patchJson(route('roster.resize', $targetSchedule), [
            'custom_start_time' => '08:00',
            'custom_end_time' => '16:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['schedule_date']);

        // 3. Attempt to split locked leave schedule via POST /roster/{workSchedule}/split -> rejected with HTTP 422
        $this->actingAs($this->foManagerUser)->postJson(route('roster.split', $targetSchedule))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_date']);

        // 4. Attempt to delete locked leave schedule via DELETE /roster/{workSchedule} -> rejected with HTTP 422
        $this->actingAs($this->foManagerUser)->deleteJson(route('roster.destroy', $targetSchedule))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_date']);
    }

    /**
     * Skenario 6 — Rekalkulasi Otomatis SPK SMART bagi Pengajuan Beririsan Pasca-Persetujuan:
     * - Dua staf (Staf A dan Staf B) di departemen yang sama mengajukan cuti pada tanggal yang sama (2026-12-21).
     * - Saat pengajuan Staf A disetujui final hingga approved (dan rosternya berubah menjadi OFF),
     *   skor SPK SMART Staf B yang masih pending_manager otomatis ter-rekalkulasi dengan nilai kriteria C1
     *   (Manpower Coverage) yang lebih rendah karena jumlah staf aktif yang tersisa di shift tersebut telah berkurang.
     */
    public function test_scenario_6_automatic_smart_recalculation_for_overlapping_pending_requests_after_approval(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $this->ledgerService->recordAccrual($this->staffEmployeeB, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        // Schedule 3 FO employees (A, B, C) on Morning Shift on 2026-12-21
        foreach ([$this->staffEmployeeA, $this->staffEmployeeB, $this->staffEmployeeC] as $emp) {
            WorkSchedule::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $emp->id,
                'shift_id' => $morningShift->id,
                'schedule_date' => '2026-12-21',
                'status' => 'scheduled',
                'published_at' => now(),
            ]);
        }

        $requestA = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-21',
            'end_date' => '2026-12-21',
            'reason' => 'Cuti Staf A tanggal 21 Desember',
        ]);

        $requestB = $this->leaveRequestService->submitRequest($this->staffEmployeeB, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-21',
            'end_date' => '2026-12-21',
            'reason' => 'Cuti Staf B tanggal 21 Desember',
        ]);

        // Initially for Request B: 3 scheduled staff -> if B takes leave, 2 remain >= min_staff_per_shift (2) -> C1 = 100.0
        $c1Before = (float) ($requestB->smart_metrics['criteria']['C1']['utility'] ?? 0.0);
        $this->assertSame(100.0, $c1Before);

        // Approve Request A through Tier 1 and Tier 2 (which turns Staff A's schedule on 2026-12-21 into OFF)
        $this->approvalService->approveTier1($requestA, $this->foManagerUser, 'Approve A Tier 1');
        $this->approvalService->approveTier2($requestA->fresh(), $this->adminUser, 'Approve A Tier 2');

        // Reload Request B (still pending_manager) and verify its C1 utility automatically dropped
        // because Staff A's schedule on 2026-12-21 was synced to OFF
        $requestB->refresh();
        $c1After = (float) ($requestB->smart_metrics['criteria']['C1']['utility'] ?? 0.0);
        $this->assertLessThan($c1Before, $c1After);
        $this->assertSame(75.0, $c1After);
    }

    /**
     * Skenario 7 — Penyesuaian Manual Buku Besar oleh HR (POST /leave/ledger/adjust):
     * - Admin berhasil menambahkan kuota manual (+2.00 hari DP) dan mengurangi kuota (-1.00 hari AL) beserta alasan wajib,
     *   tercatat sebagai transaksi adjustment di leave_ledger_entries.
     * - Manajer atau Staf biasa mencoba mengakses endpoint penyesuaian manual -> ditolak HTTP 403.
     */
    public function test_scenario_7_hr_manual_ledger_adjustment_and_rbac_protection(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();
        $dpType = LeaveType::withoutGlobalScopes()->where('code', 'DP')->firstOrFail();

        // 1. Positive manual integer adjustment (+2 DP) by Admin
        $this->actingAs($this->adminUser)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $dpType->id,
            'direction' => 'credit',
            'amount' => 2,
            'reason' => 'Kompensasi tugas luar kota akhir pekan.',
            'expires_at' => '2027-03-31',
        ])->assertOk()->assertJsonPath('status', 'ok');

        $dpSummary = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'DP');
        $this->assertSame(2, $dpSummary['ledger_balance']);

        // 2. Negative manual integer adjustment (-1 AL) by Admin
        $this->actingAs($this->adminUser)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $alType->id,
            'direction' => 'debit',
            'amount' => -1,
            'reason' => 'Koreksi saldo cuti bersama tahun berjalan.',
        ])->assertOk()->assertJsonPath('status', 'ok');

        $alSummary = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(11, $alSummary['ledger_balance']);

        $adjustmentCount = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->where('transaction_type', LeaveLedgerEntry::TYPE_ADJUSTMENT)
            ->count();
        $this->assertSame(2, $adjustmentCount);

        // 3. Decimal adjustment values (0.5 or -1.5) are strictly rejected with HTTP 422
        $this->actingAs($this->adminUser)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $dpType->id,
            'amount' => 0.5,
            'reason' => 'Percobaan angka desimal setengah hari.',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($this->adminUser)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $alType->id,
            'amount' => -1.5,
            'reason' => 'Percobaan angka desimal negatif.',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // 4. Manager & Regular Staff blocked from manual ledger adjustment -> 403
        $this->actingAs($this->foManagerUser)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $dpType->id,
            'direction' => 'credit',
            'amount' => 1,
            'reason' => 'Percobaan oleh manajer departemen.',
        ])->assertForbidden();

        $this->actingAs($this->staffUserA)->postJson(route('leave.ledger.adjust'), [
            'employee_id' => $this->staffEmployeeA->id,
            'leave_type_id' => $dpType->id,
            'direction' => 'credit',
            'amount' => 1,
            'reason' => 'Percobaan oleh staf sendiri.',
        ])->assertForbidden();
    }

    /**
     * Skenario 8 — Keamanan Riwayat Buku Besar (GET /leave/ledger/{employee}):
     * - Staf dapat melihat riwayat buku besar miliknya sendiri (HTTP 200).
     * - Manajer dapat melihat riwayat buku besar staf di departemennya (HTTP 200), namun ditolak (HTTP 403) untuk staf lintas departemen.
     * - Admin dari tenant lain ditolak (HTTP 403).
     */
    public function test_scenario_8_leave_ledger_history_security_and_isolation(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $this->ledgerService->recordAccrual($this->hkStaffEmployee, 'AL', 12.00, ['accrual_year' => 2026]);

        // 1. Staff can view their own ledger history -> 200
        $this->actingAs($this->staffUserA)
            ->getJson(route('leave.ledger.history', $this->staffEmployeeA))
            ->assertOk()
            ->assertJsonPath('employee.id', $this->staffEmployeeA->id);

        // Staff cannot view another employee's ledger -> 403
        $this->actingAs($this->staffUserA)
            ->getJson(route('leave.ledger.history', $this->staffEmployeeB))
            ->assertForbidden();

        // 2. FO Manager can view FO staff's ledger -> 200, but gets 403 for HK staff
        $this->actingAs($this->foManagerUser)
            ->getJson(route('leave.ledger.history', $this->staffEmployeeA))
            ->assertOk();

        $this->actingAs($this->foManagerUser)
            ->getJson(route('leave.ledger.history', $this->hkStaffEmployee))
            ->assertForbidden();

        // 3. Cross-tenant Admin gets 403
        $otherCompany = Company::create([
            'code' => 'OTHER-HOTEL',
            'name' => 'Other Hotel Tenant',
            'latitude' => '-7.9048250',
            'longitude' => '110.0572110',
            'geofence_radius_meters' => 100,
            'storage_limit_mb' => 500,
            'is_active' => true,
        ]);

        $otherTenantAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Admin',
            'username' => 'other.admin',
            'email' => 'admin@otherhotel.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($otherTenantAdmin)
            ->getJson(route('leave.ledger.history', $this->staffEmployeeA))
            ->assertForbidden();
    }

    /**
     * Skenario 9 — Pembatalan Cuti Approved, Refund Buku Besar Append-Only, & Pelepasan Kunci Roster:
     * - Staf memiliki saldo awal 12.00 hari, mengajukan cuti 3.00 hari, dan disetujui hingga Tier 2 (approved)
     *   sehingga saldo buku besar menjadi 9.00 dan jadwal Roster terkunci (LEAVE:AL:...).
     * - Saat staf membatalkan cuti tersebut (POST /leave/{leaveRequest}/cancel), status berubah menjadi cancelled,
     *   terbentuk entri baru credit positif (+3.00) pada leave_ledger_entries (tanpa menghapus entri debit -3.00 sebelumnya)
     *   sehingga saldo aktif kembali menjadi 12.00, serta jadwal LEAVE:AL:... pada Roster otomatis terhapus/terlepas.
     */
    public function test_scenario_9_cancelling_approved_leave_refunds_ledger_append_only_and_releases_roster_lock(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-16',
            'end_date' => '2026-12-18',
            'reason' => 'Liburan keluarga ke Bali',
        ]);

        // Approve Tier 1 & Tier 2 -> balance becomes 9.00 and Roster has 3 locked LEAVE:AL schedules
        $this->approvalService->approveTier1($leaveRequest, $this->foManagerUser, 'Tier 1 OK');
        $this->approvalService->approveTier2($leaveRequest->fresh(), $this->adminUser, 'Tier 2 OK');

        $leaveRequest->refresh();
        $originalDebitEntryId = $leaveRequest->ledger_entry_id;
        $this->assertNotNull($originalDebitEntryId);
        $this->assertSame(9, $this->ledgerService->getBalance($this->staffEmployeeA, 'AL'));

        $lockedCountBefore = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->whereBetween('schedule_date', ['2026-12-16', '2026-12-18'])
            ->where('notes', 'like', 'LEAVE:%')
            ->count();
        $this->assertSame(3, $lockedCountBefore);

        // Staff cancels the approved leave request
        $this->actingAs($this->staffUserA)
            ->postJson(route('leave.cancel', $leaveRequest), [
                'cancellation_reason' => 'Penerbangan dibatalkan karena cuaca buruk.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELLED);

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $leaveRequest->status);
        $this->assertNull($leaveRequest->ledger_entry_id);

        // Historical debit row (-3) is preserved untouched (append-only invariant)
        $historicalDebit = LeaveLedgerEntry::withoutGlobalScopes()->find($originalDebitEntryId);
        $this->assertNotNull($historicalDebit);
        $this->assertSame(LeaveLedgerEntry::TYPE_DEBIT, $historicalDebit->transaction_type);
        $this->assertSame(-3, (int) $historicalDebit->amount);

        // A new positive credit refund entry (+3) was appended, restoring active balance to 12
        $refundEntry = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->where('reference_type', 'leave_cancellation')
            ->where('reference_id', $leaveRequest->id)
            ->first();
        $this->assertNotNull($refundEntry);
        $this->assertSame(LeaveLedgerEntry::TYPE_CREDIT, $refundEntry->transaction_type);
        $this->assertSame(3, (int) $refundEntry->amount);
        $this->assertSame(3, (int) $refundEntry->remaining_amount);
        $this->assertSame(12, (int) $refundEntry->balance_after);
        $this->assertSame($originalDebitEntryId, $refundEntry->source_entry_id);

        $this->assertSame(12, $this->ledgerService->getBalance($this->staffEmployeeA, 'AL'));

        // Locked LEAVE: schedules on Roster have been released, so Manager can assign a work shift on 2026-12-16
        $lockedCountAfter = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->whereBetween('schedule_date', ['2026-12-16', '2026-12-18'])
            ->where('notes', 'like', 'LEAVE:%')
            ->count();
        $this->assertSame(0, $lockedCountAfter);

        $this->actingAs($this->foManagerUser)->postJson(route('roster.assign'), [
            'employee_id' => $this->staffEmployeeA->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-12-16',
        ])->assertOk();
    }

    /**
     * Skenario 10 — Pengubahan (Edit) Cuti Mandiri (Pending & Approved) Reset ke Pending:
     * - Staf mengubah pengajuan cuti yang masih pending_hr (sudah disetujui Manajer Tier 1) via PUT /leave/{leaveRequest}
     *   dengan tanggal baru -> total_days dan smart_score terhitung ulang, serta status kembali di-reset ke pending_manager
     *   (manager_approved_by dan manager_approved_at menjadi null).
     * - Staf mengubah pengajuan cuti yang sudah approved (3 hari) menjadi rentang tanggal baru (2 hari)
     *   -> saldo lama (+3.00) dikembalikan ke buku besar, jadwal Roster lama dilepas, total_days menjadi 2.00,
     *   dan status kembali menjadi pending_manager (menunggu persetujuan Tier 1 & Tier 2 dari awal).
     */
    public function test_scenario_10_editing_pending_and_approved_leave_refunds_ledger_releases_roster_and_resets_to_pending(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, ['accrual_year' => 2026]);
        $alType = LeaveType::withoutGlobalScopes()->where('code', 'AL')->firstOrFail();

        // 1. Submit 3-day request and approve Tier 1 (status = pending_hr)
        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'reason' => 'Rencana awal 3 hari awal Desember',
        ]);

        $this->approvalService->approveTier1($leaveRequest, $this->foManagerUser, 'Approved Tier 1');
        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_HR, $leaveRequest->status);
        $this->assertNotNull($leaveRequest->manager_approved_by);

        // Edit while pending_hr -> resets back to pending_manager and clears manager_approved_by / manager_approved_at
        $this->actingAs($this->staffUserA)
            ->putJson(route('leave.update', $leaveRequest), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-12-02',
                'end_date' => '2026-12-04',
                'reason' => 'Diubah menjadi 2-4 Desember (3 hari)',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING_MANAGER)
            ->assertJsonPath('data.total_days', 3);

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_MANAGER, $leaveRequest->status);
        $this->assertNull($leaveRequest->manager_approved_by);
        $this->assertNull($leaveRequest->manager_approved_at);

        // 2. Now approve through Tier 1 and Tier 2 (status = approved, ledger = 9.00, Roster locked on Dec 2-4)
        $this->approvalService->approveTier1($leaveRequest, $this->foManagerUser, 'Re-approved Tier 1');
        $this->approvalService->approveTier2($leaveRequest->fresh(), $this->adminUser, 'Approved Tier 2');

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leaveRequest->status);
        $this->assertSame(9, $this->ledgerService->getBalance($this->staffEmployeeA, 'AL'));

        // Staff edits the approved 3-day leave request to a 2-day range (2026-12-08 to 2026-12-09)
        $this->actingAs($this->staffUserA)
            ->putJson(route('leave.update', $leaveRequest), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-12-08',
                'end_date' => '2026-12-09',
                'reason' => 'Perubahan jadwal cuti menjadi 2 hari saja.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING_MANAGER)
            ->assertJsonPath('data.total_days', 2);

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_MANAGER, $leaveRequest->status);
        $this->assertSame(2, (int) $leaveRequest->total_days);
        $this->assertNull($leaveRequest->ledger_entry_id);
        $this->assertNull($leaveRequest->manager_approved_by);
        $this->assertNull($leaveRequest->hr_approved_by);

        // Verify old 3-day debit was refunded to ledger (ledger balance back to 12, reserved = 2, available = 10)
        $quotaSummary = $this->leaveRequestService->getQuotaSummary($this->staffEmployeeA, 'AL');
        $this->assertSame(12, $quotaSummary['ledger_balance']);
        $this->assertSame(2, $quotaSummary['reserved_days']);
        $this->assertSame(10, $quotaSummary['available_balance']);

        // Verify old Roster lock on 2026-12-02..2026-12-04 was released
        $oldRosterLocks = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->whereBetween('schedule_date', ['2026-12-02', '2026-12-04'])
            ->where('notes', 'like', 'LEAVE:%')
            ->count();
        $this->assertSame(0, $oldRosterLocks);
    }
}
