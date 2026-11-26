<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Department $hkDept;
    protected User $staffUser;
    protected Employee $staffEmployee;
    protected User $managerUser;
    protected Employee $managerEmployee;
    protected User $adminUser;
    protected LeaveLedgerService $ledgerService;
    protected LeaveRequestService $leaveRequestService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

        $this->hkDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'HK')
            ->firstOrFail();

        $this->staffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina.fo',
            'email' => 'rina.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->staffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->staffUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2024-03-15',
            'status' => 'active',
        ]);

        $this->managerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Hendra Wijaya',
            'username' => 'hendra.fom',
            'email' => 'hendra.fom@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->managerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->managerUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2023-01-10',
            'status' => 'active',
        ]);

        $this->adminUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Dewi Sartika',
            'username' => 'dewi.hr',
            'email' => 'dewi.hr@novotelyia.com',
            'role' => 'admin_hr',
            'is_active' => true,
        ]);

        $this->ledgerService = app(LeaveLedgerService::class);
        $this->leaveRequestService = app(LeaveRequestService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Kalkulasi Hari Kerja Efektif Berbasis Roster (Roster-Aware Effective Days).
     * Mengajukan cuti 5 hari kalender, di mana 2 hari di antaranya memiliki jadwal published OFF
     * (satu shift_id = null, satu lagi shift berkode OFF), dan 1 hari memiliki split shift (2 segmen pada tanggal yang sama).
     * Pastikan menghasilkan total_days = 3.00.
     */
    public function test_scenario_1_roster_aware_effective_days_calculation_and_submission(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployee, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', '!=', 'OFF')
            ->firstOrFail();

        $eveningShift = Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'FO-SPLIT-2',
            'name' => 'Split Evening Segment',
            'start_time' => '17:00:00',
            'end_time' => '21:00:00',
            'cross_day' => false,
            'grace_period_minutes' => 15,
            'color_badge' => '#3B82F6',
            'is_active' => true,
        ]);

        $offShift = Shift::withoutGlobalScopes()->firstOrCreate(
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

        // Rentang 5 hari kalender: 2026-06-08 s/d 2026-06-12
        // 2026-06-08: Working shift (effective)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-06-08',
            'status' => 'published',
        ]);

        // 2026-06-09: OFF via shift_id = null (excluded)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'shift_id' => null,
            'schedule_date' => '2026-06-09',
            'status' => 'published',
        ]);

        // 2026-06-10: Split shift (2 working shifts on the same date -> still 1.00 effective day)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-06-10',
            'status' => 'published',
        ]);
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'shift_id' => $eveningShift->id,
            'schedule_date' => '2026-06-10',
            'status' => 'published',
        ]);

        // 2026-06-11: OFF via shift code OFF (excluded)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'shift_id' => $offShift->id,
            'schedule_date' => '2026-06-11',
            'status' => 'published',
        ]);

        // 2026-06-12: Belum ada roster published -> default 1.00 hari kerja efektif

        $calc = $this->leaveRequestService->calculateEffectiveDays(
            $this->staffEmployee,
            '2026-06-08',
            '2026-06-12'
        );

        $this->assertSame(5, $calc['calendar_days']);
        $this->assertSame(2, $calc['excluded_off_days']);
        $this->assertSame(3, $calc['effective_days']);
        $this->assertSame(['2026-06-09', '2026-06-11'], $calc['excluded_dates']);

        // Uji juga via JSON endpoint GET /leave/calculate-days
        $jsonResponse = $this->actingAs($this->staffUser)
            ->getJson(route('leave.calculateDays', [
                'start_date' => '2026-06-08',
                'end_date' => '2026-06-12',
            ]));

        $jsonResponse->assertOk()
            ->assertJson([
                'calendar_days' => 5,
                'excluded_off_days' => 2,
                'effective_days' => 3,
                'excluded_dates' => ['2026-06-09', '2026-06-11'],
            ]);

        // Submit permohonan cuti via POST /leave
        $alType = LeaveType::findByCode('AL', $this->company->id);

        $postResponse = $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-06-08',
                'end_date' => '2026-06-12',
                'reason' => 'Liburan keluarga tahunan ke Yogyakarta dan sekitarnya.',
            ]);

        $postResponse->assertRedirect(route('leave.index'));
        $postResponse->assertSessionHas('success');

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployee->id)
            ->firstOrFail();

        $this->assertSame(3, $leaveRequest->total_days);
        $this->assertSame(LeaveRequest::STATUS_PENDING_MANAGER, $leaveRequest->status);
    }

    /**
     * Skenario 2: Validasi Kuota & Anti Double-Spending (Reservation Guard).
     * Karyawan memiliki saldo AL = 4 hari.
     * Mengajukan cuti pertama 3 hari (pending_manager).
     * Pengajuan kedua 2 hari pada tanggal berbeda ditolak (HTTP 422) karena available_balance = 1,
     * sementara saldo di leave_ledger_entries tetap 4 (tidak ada debit buku besar prematur).
     */
    public function test_scenario_2_quota_reservation_guard_prevents_double_spending_without_premature_ledger_debit(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployee, 'AL', 4, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $alType = LeaveType::findByCode('AL', $this->company->id);

        // Pengajuan pertama: 3 hari efektif (2026-06-15 s/d 2026-06-17)
        $firstResponse = $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-17',
                'reason' => 'Keperluan keluarga tahap pertama selama tiga hari.',
            ]);

        $firstResponse->assertRedirect(route('leave.index'));

        // Pastikan saldo buku besar tetap 4 (belum ada debit di Fase 2)
        $this->assertSame(4, $this->ledgerService->getBalance($this->staffEmployee, 'AL'));
        $this->assertSame(
            0,
            LeaveLedgerEntry::withoutGlobalScopes()
                ->where('employee_id', $this->staffEmployee->id)
                ->where('transaction_type', LeaveLedgerEntry::TYPE_DEBIT)
                ->count()
        );

        // Periksa ringkasan kuota: ledger = 4, reserved = 3, available = 1
        $quotaSummary = $this->leaveRequestService->getQuotaSummary($this->staffEmployee, 'AL');
        $this->assertSame(4, $quotaSummary['ledger_balance']);
        $this->assertSame(3, $quotaSummary['reserved_days']);
        $this->assertSame(1, $quotaSummary['available_balance']);

        // Pengajuan kedua: 2 hari efektif (2026-06-22 s/d 2026-06-23) -> harus ditolak HTTP 422
        $secondResponse = $this->actingAs($this->staffUser)
            ->postJson(route('leave.store'), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-06-22',
                'end_date' => '2026-06-23',
                'reason' => 'Keperluan keluarga tahap kedua melebihi sisa kuota tersedia.',
            ]);

        $secondResponse->assertStatus(422)
            ->assertJsonValidationErrors(['leave_type_id']);

        $this->assertSame(
            1,
            LeaveRequest::withoutGlobalScopes()
                ->where('employee_id', $this->staffEmployee->id)
                ->count()
        );
    }

    /**
     * Skenario 3: Pencegahan Irisan Tanggal (Anti-Overlap Guard).
     * Pengajuan cuti baru yang beririsan tanggal dengan pengajuan berstatus
     * pending_manager, pending_hr, atau approved milik karyawan yang sama ditolak (HTTP 422).
     */
    public function test_scenario_3_anti_overlap_guard_blocks_overlapping_pending_or_approved_requests(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployee, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $alType = LeaveType::findByCode('AL', $this->company->id);
        $ulType = LeaveType::findByCode('UL', $this->company->id);

        foreach ([
            LeaveRequest::STATUS_PENDING_MANAGER,
            LeaveRequest::STATUS_PENDING_HR,
            LeaveRequest::STATUS_APPROVED,
        ] as $index => $existingStatus) {
            $baseStart = Carbon::create(2026, 7, 1 + ($index * 10))->toDateString();
            $baseEnd = Carbon::create(2026, 7, 4 + ($index * 10))->toDateString();
            $overlapStart = Carbon::create(2026, 7, 3 + ($index * 10))->toDateString();
            $overlapEnd = Carbon::create(2026, 7, 6 + ($index * 10))->toDateString();

            LeaveRequest::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $this->staffEmployee->id,
                'leave_type_id' => $alType->id,
                'start_date' => $baseStart,
                'end_date' => $baseEnd,
                'total_days' => 4.00,
                'reason' => "Pengajuan eksisting dengan status {$existingStatus}",
                'status' => $existingStatus,
            ]);

            $response = $this->actingAs($this->staffUser)
                ->postJson(route('leave.store'), [
                    'leave_type_id' => $ulType->id,
                    'start_date' => $overlapStart,
                    'end_date' => $overlapEnd,
                    'reason' => 'Pengajuan baru yang beririsan tanggal dengan pengajuan aktif.',
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['start_date']);
        }
    }

    /**
     * Skenario 4: Kewajiban Lampiran & Kompresi WebP + Storage Limit Guard.
     * - Pengajuan SL (requires_attachment = true) tanpa berkas lampiran ditolak (HTTP 422).
     * - Pengajuan SL dengan berkas gambar berhasil dikompresi menjadi .webp di storage/app/private/leave_attachments/...
     * - Pengajuan SL saat storage_limit_mb perusahaan terlampaui ditolak (HTTP 422).
     */
    public function test_scenario_4_required_attachment_webp_compression_and_company_storage_limit_guard(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $slType = LeaveType::findByCode('SL', $this->company->id);
        $this->assertTrue($slType->requires_attachment);

        // 1. Tanpa lampiran -> gagal HTTP 422
        $noAttachmentResponse = $this->actingAs($this->staffUser)
            ->postJson(route('leave.store'), [
                'leave_type_id' => $slType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Istirahat medis sesuai anjuran dokter klinik.',
            ]);

        $noAttachmentResponse->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);

        // 2. Dengan lampiran gambar PNG -> dikompresi menjadi .webp di disk privat local
        $imageFile = UploadedFile::fake()->image('medical_certificate.png', 1600, 1200);

        $withImageResponse = $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $slType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Istirahat medis sesuai surat keterangan dokter terlampir.',
                'attachment' => $imageFile,
            ]);

        $withImageResponse->assertRedirect(route('leave.index'));

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployee->id)
            ->firstOrFail();

        $this->assertNotNull($leaveRequest->attachment_path);
        $this->assertStringStartsWith("leave_attachments/{$this->company->id}/2026-06/", $leaveRequest->attachment_path);
        $this->assertStringEndsWith('.webp', $leaveRequest->attachment_path);
        Storage::disk('local')->assertExists($leaveRequest->attachment_path);

        // 3. Saat storage_limit_mb perusahaan terlampaui -> ditolak HTTP 422
        $this->company->update(['storage_limit_mb' => 1]);
        // Isi disk perusahaan dengan berkas dummy sebesar 1.1 MB
        Storage::disk('local')->put(
            "leave_attachments/{$this->company->id}/2026-06/existing_quota_fill.bin",
            str_repeat('A', 1100 * 1024)
        );

        $exceededResponse = $this->actingAs($this->staffUser)
            ->postJson(route('leave.store'), [
                'leave_type_id' => $slType->id,
                'start_date' => '2026-06-18',
                'end_date' => '2026-06-19',
                'reason' => 'Pengajuan sakit kedua saat kuota penyimpanan penuh.',
                'attachment' => UploadedFile::fake()->image('doctor_note_2.jpg', 800, 600),
            ]);

        $exceededResponse->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);
    }

    /**
     * Skenario 5: Routing Status Awal Berbasis Peran (Role-Based Initial Status).
     * - Staf biasa (role: user) mendapatkan status awal pending_manager.
     * - Manajer departemen (role: manager) langsung mendapatkan status awal pending_hr.
     */
    public function test_scenario_5_role_based_initial_status_routing_for_user_and_manager(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $ulType = LeaveType::findByCode('UL', $this->company->id);

        // Staf biasa (role: user) -> pending_manager
        $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $ulType->id,
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-16',
                'reason' => 'Keperluan pribadi mendesak staf operasional.',
            ])
            ->assertRedirect(route('leave.index'));

        $staffRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployee->id)
            ->firstOrFail();

        $this->assertSame(LeaveRequest::STATUS_PENDING_MANAGER, $staffRequest->status);

        // Manajer departemen (role: manager) -> pending_hr
        $this->actingAs($this->managerUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $ulType->id,
                'start_date' => '2026-06-20',
                'end_date' => '2026-06-21',
                'reason' => 'Keperluan pribadi manajer departemen langsung ke HR.',
            ])
            ->assertRedirect(route('leave.index'));

        $managerRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->managerEmployee->id)
            ->firstOrFail();

        $this->assertSame(LeaveRequest::STATUS_PENDING_HR, $managerRequest->status);
    }

    /**
     * Skenario 6: Pembatalan Pengajuan Mandiri (Self-Service Cancellation Flow).
     * - Karyawan membatalkan pengajuan pending_manager -> status menjadi cancelled, cancelled_at terisi,
     *   dan kuota tereservasi langsung kembali tersedia.
     * - Percobaan membatalkan pengajuan yang sudah approved ditolak (HTTP 422).
     * - Percobaan membatalkan pengajuan milik karyawan lain ditolak (HTTP 403).
     */
    public function test_scenario_6_self_service_cancellation_releases_reserved_quota_and_enforces_status_and_ownership_guards(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployee, 'AL', 5.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $alType = LeaveType::findByCode('AL', $this->company->id);

        // Buat pengajuan 3 hari efektif
        $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-17',
                'reason' => 'Rencana perjalanan keluarga yang akan dibatalkan.',
            ]);

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployee->id)
            ->firstOrFail();

        // Sebelum dibatalkan: reserved = 3, available = 2
        $summaryBefore = $this->leaveRequestService->getQuotaSummary($this->staffEmployee, 'AL');
        $this->assertSame(3, $summaryBefore['reserved_days']);
        $this->assertSame(2, $summaryBefore['available_balance']);

        // Karyawan lain (managerUser) mencoba membatalkan pengajuan milik staffEmployee -> 403 Forbidden
        $this->actingAs($this->managerUser)
            ->post(route('leave.cancel', $leaveRequest), [
                'cancellation_reason' => 'Mencoba membatalkan pengajuan staf lain.',
            ])
            ->assertForbidden();

        // Pemilik membatalkan pengajuan pending_manager -> berhasil
        $this->actingAs($this->staffUser)
            ->post(route('leave.cancel', $leaveRequest), [
                'cancellation_reason' => 'Jadwal acara keluarga diundur ke bulan depan.',
            ])
            ->assertRedirect(route('leave.index'));

        $leaveRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $leaveRequest->status);
        $this->assertNotNull($leaveRequest->cancelled_at);
        $this->assertSame('Jadwal acara keluarga diundur ke bulan depan.', $leaveRequest->cancellation_reason);

        // Setelah dibatalkan: reserved = 0, available = 5 kembali pulih
        $summaryAfter = $this->leaveRequestService->getQuotaSummary($this->staffEmployee, 'AL');
        $this->assertSame(0, $summaryAfter['reserved_days']);
        $this->assertSame(5, $summaryAfter['available_balance']);

        // Pengajuan yang sudah rejected tidak dapat dibatalkan mandiri -> HTTP 422
        $rejectedRequest = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployee->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-23',
            'total_days' => 2.00,
            'reason' => 'Pengajuan cuti yang sudah ditolak.',
            'status' => LeaveRequest::STATUS_REJECTED,
        ]);

        $this->actingAs($this->staffUser)
            ->postJson(route('leave.cancel', $rejectedRequest), [
                'cancellation_reason' => 'Ingin membatalkan cuti yang sudah rejected.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Skenario 7: Keamanan Streaming Lampiran Privat & Isolasi Tenant (Attachment RBAC & Tenant Isolation).
     * - Pemilik pengajuan, Manajer satu departemen, dan Admin satu perusahaan mendapatkan HTTP 200.
     * - Manajer departemen berbeda atau Admin dari perusahaan lain mendapatkan HTTP 403 Forbidden.
     */
    public function test_scenario_7_private_attachment_streaming_rbac_and_tenant_isolation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'Asia/Jakarta'));

        $slType = LeaveType::findByCode('SL', $this->company->id);

        $this->actingAs($this->staffUser)
            ->post(route('leave.store'), [
                'leave_type_id' => $slType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Surat keterangan sakit dari rumah sakit.',
                'attachment' => UploadedFile::fake()->image('surat_dokter.jpg', 900, 700),
            ]);

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployee->id)
            ->firstOrFail();

        // 1. Pemilik pengajuan -> HTTP 200
        $this->actingAs($this->staffUser)
            ->get(route('leave.attachment', $leaveRequest))
            ->assertOk();

        // 2. Manajer satu departemen (FO) -> HTTP 200
        $this->actingAs($this->managerUser)
            ->get(route('leave.attachment', $leaveRequest))
            ->assertOk();

        // 3. Admin/HR satu perusahaan -> HTTP 200
        $this->actingAs($this->adminUser)
            ->get(route('leave.attachment', $leaveRequest))
            ->assertOk();

        // 4. Manajer departemen berbeda (HK) di perusahaan yang sama -> HTTP 403 Forbidden
        $hkManagerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.hkm',
            'email' => 'budi.hkm@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $hkManagerUser->id,
            'department_id' => $this->hkDept->id,
            'position' => 'Executive Housekeeper',
            'join_date' => '2023-05-01',
            'status' => 'active',
        ]);

        $this->actingAs($hkManagerUser)
            ->get(route('leave.attachment', $leaveRequest))
            ->assertForbidden();

        // 5. Admin dari perusahaan lain (isolasi multi-tenant) -> HTTP 403 Forbidden
        $otherCompany = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport',
            'latitude' => '-7.9048250',
            'longitude' => '110.0572110',
            'geofence_radius_meters' => 100,
            'storage_limit_mb' => 500,
            'is_active' => true,
        ]);

        $otherCompanyAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Admin Ibis',
            'username' => 'admin.ibis',
            'email' => 'admin@ibisyia.com',
            'role' => 'admin_hr',
            'is_active' => true,
        ]);

        $this->actingAs($otherCompanyAdmin)
            ->get(route('leave.attachment', $leaveRequest))
            ->assertForbidden();
    }

    /**
     * Skenario 8: Paritas Bahasa (LangParityTest) & Render Halaman Portal Cuti Mandiri.
     */
    public function test_scenario_8_leave_portal_page_renders_and_language_dictionaries_have_strict_parity(): void
    {
        $this->ledgerService->recordAccrual($this->staffEmployee, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $response = $this->actingAs($this->staffUser)
            ->get(route('leave.index'));

        $response->assertOk()
            ->assertSee(__('leave.title'))
            ->assertSee('12')
            ->assertDontSee('12.00');

        $idKeys = $this->flattenKeys(require base_path('lang/id/leave.php'));
        $enKeys = $this->flattenKeys(require base_path('lang/en/leave.php'));

        $this->assertSame($idKeys, $enKeys);
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<int, string>
     */
    private function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }
        sort($keys);

        return $keys;
    }
}
