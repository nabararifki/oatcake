<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SmartDepartmentWeight;
use App\Models\User;
use App\Services\DpCommitService;
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

class SmartDecisionEngineTest extends TestCase
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
    protected User $managerUser;
    protected Employee $managerEmployee;
    protected User $adminUser;
    protected LeaveLedgerService $ledgerService;
    protected LeaveRequestService $leaveRequestService;
    protected SmartDecisionService $smartService;
    protected DpCommitService $dpCommitService;

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
            'join_date' => '2024-02-15',
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
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->ledgerService = app(LeaveLedgerService::class);
        $this->leaveRequestService = app(LeaveRequestService::class);
        $this->smartService = app(SmartDecisionService::class);
        $this->dpCommitService = app(DpCommitService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Auto-Provisioning & Normalisasi Bobot Dinamis (US-22).
     * - Auto-provisioning membuat 5 kriteria C1..C5 dengan bobot default (30, 20, 15, 15, 20).
     * - Mengubah bobot mentah (misal C1=40, C2=30, C3=10, C4=10, C5=10 atau C1=50, C2=50, C3=0, C4=0, C5=0)
     *   menghasilkan normalized_weight yang akurat dengan jumlah tepat 1.0000.
     * - Menolak jika seluruh bobot disetel 0 (HTTP 422).
     */
    public function test_scenario_1_auto_provisioning_and_dynamic_weight_normalization(): void
    {
        // 1. Auto-provisioning default weights
        $defaults = $this->smartService->getOrCreateDepartmentWeights($this->foDept)->keyBy('criteria_code');

        $this->assertCount(5, $defaults);
        $this->assertSame(30, $defaults['C1']->raw_weight);
        $this->assertSame('0.3000', $defaults['C1']->normalized_weight);
        $this->assertSame(20, $defaults['C2']->raw_weight);
        $this->assertSame('0.2000', $defaults['C2']->normalized_weight);
        $this->assertSame(15, $defaults['C3']->raw_weight);
        $this->assertSame(15, $defaults['C4']->raw_weight);
        $this->assertSame(20, $defaults['C5']->raw_weight);

        $sumDefault = $defaults->sum(fn (SmartDepartmentWeight $w): float => (float) $w->normalized_weight);
        $this->assertSame(1.0, round($sumDefault, 4));

        // 2. Update bobot C1=40, C2=30, C3=10, C4=10, C5=10
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 40,
                    'C2' => 30,
                    'C3' => 10,
                    'C4' => 10,
                    'C5' => 10,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('weights.C1.normalized_weight', 0.4)
            ->assertJsonPath('weights.C2.normalized_weight', 0.3)
            ->assertJsonPath('weights.C3.normalized_weight', 0.1)
            ->assertJsonPath('weights.C4.normalized_weight', 0.1)
            ->assertJsonPath('weights.C5.normalized_weight', 0.1);

        // 3. Update bobot C1=35, C2=25, C3=15, C4=10, C5=15 (Total = 100) -> HTTP 200
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 35,
                    'C2' => 25,
                    'C3' => 15,
                    'C4' => 10,
                    'C5' => 15,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('weights.C1.raw_weight', 35)
            ->assertJsonPath('weights.C1.normalized_weight', 0.35)
            ->assertJsonPath('weights.C2.normalized_weight', 0.25)
            ->assertJsonPath('weights.C3.normalized_weight', 0.15)
            ->assertJsonPath('weights.C4.normalized_weight', 0.1)
            ->assertJsonPath('weights.C5.normalized_weight', 0.15);

        $reloaded = SmartDepartmentWeight::withoutGlobalScopes()
            ->where('department_id', $this->foDept->id)
            ->get();
        $this->assertSame(100, $reloaded->sum(fn ($w) => (int) $w->raw_weight));
        $this->assertSame(1.0, round($reloaded->sum(fn ($w) => (float) $w->normalized_weight), 4));

        // 4. Total bobot != 100 (misal 85 atau 110) ditolak HTTP 422 ValidationException
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 30,
                    'C2' => 20,
                    'C3' => 15,
                    'C4' => 10,
                    'C5' => 10, // Total = 85
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);

        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 40,
                    'C2' => 30,
                    'C3' => 15,
                    'C4' => 15,
                    'C5' => 10, // Total = 110
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);

        // 5. Seluruh bobot = 0 ditolak HTTP 422
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 0,
                    'C2' => 0,
                    'C3' => 0,
                    'C4' => 0,
                    'C5' => 0,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);
    }

    /**
     * Skenario 2: Otorisasi RBAC Bobot Departemen.
     * - Manajer berhasil mengubah bobot departemennya sendiri (FO), namun ditolak HTTP 403 saat mencoba mengubah bobot departemen lain (HK).
     * - Admin perusahaan berhasil mengubah bobot departemen mana pun (FO maupun HK).
     * - Staf biasa (role: user) ditolak HTTP 403.
     */
    public function test_scenario_2_department_smart_weights_rbac_and_department_isolation(): void
    {
        $payloadWeights = ['C1' => 25, 'C2' => 25, 'C3' => 20, 'C4' => 10, 'C5' => 20];

        // 1. Staf biasa (role: user) -> HTTP 403
        $this->actingAs($this->staffUserA)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => $payloadWeights,
            ])
            ->assertForbidden();

        // 2. Manajer FO mengubah bobot departemennya sendiri (FO) -> HTTP 200
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => $payloadWeights,
            ])
            ->assertOk();

        // 3. Manajer FO mencoba mengubah bobot departemen lain (HK) -> HTTP 403
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->hkDept->id,
                'weights' => $payloadWeights,
            ])
            ->assertForbidden();

        // 4. Admin perusahaan mengubah bobot departemen HK -> HTTP 200
        $this->actingAs($this->adminUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->hkDept->id,
                'weights' => $payloadWeights,
            ])
            ->assertOk();
    }

    /**
     * Skenario 3: Pengaturan Kedaluwarsa Tipe Cuti oleh Admin+.
     * - Admin berhasil mengubah validity_months tipe cuti DP dari 3 bulan menjadi 6 bulan untuk perusahaannya.
     * - Saat terjadi komitmen DP baru di perusahaan tersebut, expires_at tercatat 6 bulan ke depan
     *   tanpa mengubah pengaturan perusahaan (tenant) lain (tetap 3 bulan).
     * - Manajer dan Staf biasa ditolak HTTP 403 saat mengakses endpoint ini.
     */
    public function test_scenario_3_admin_leave_type_expiry_settings_per_tenant_and_dp_commit_integration(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 15, 17, 0, 0, 'Asia/Jakarta'));

        $dpType = LeaveType::findByCode('DP', $this->company->id);
        $this->assertSame(3, $dpType->getEffectiveValidityMonths($this->company->id));

        // 1. Staf biasa & Manajer ditolak HTTP 403
        $this->actingAs($this->staffUserA)
            ->putJson(route('leave.types.updateSettings', $dpType), [
                'validity_months' => 6,
            ])
            ->assertForbidden();

        $this->actingAs($this->managerUser)
            ->putJson(route('leave.types.updateSettings', $dpType), [
                'validity_months' => 6,
            ])
            ->assertForbidden();

        // 2. Admin mengubah validity_months DP menjadi 6 bulan untuk NOVOTEL-YIA
        $this->actingAs($this->adminUser)
            ->putJson(route('leave.types.updateSettings', $dpType), [
                'validity_months' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('data.validity_months', 6);

        $dpType->refresh();
        $this->assertSame(6, $dpType->getEffectiveValidityMonths($this->company->id));

        // 3. Perusahaan lain (tenant berbeda) tetap menggunakan default 3 bulan
        $otherCompany = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport',
            'latitude' => '-7.9048250',
            'longitude' => '110.0572110',
            'geofence_radius_meters' => 100,
            'storage_limit_mb' => 500,
            'is_active' => true,
        ]);

        $this->assertSame(3, $dpType->getEffectiveValidityMonths($otherCompany->id));

        // 4. Komitmen DP baru dari presensi hari libur di NOVOTEL-YIA otomatis kedaluwarsa 6 bulan ke depan (2026-09-15)
        $holidayAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeA->id,
            'clock_in_at' => Carbon::create(2026, 3, 15, 8, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 3, 15, 16, 30, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'is_provisional_dp' => true,
        ]);

        $ledgerEntry = $this->dpCommitService->attemptCommitFromAttendance($holidayAttendance, $this->adminUser);
        $this->assertNotNull($ledgerEntry);
        $this->assertSame('2026-09-15', $ledgerEntry->expires_at?->toDateString());
    }

    /**
     * Skenario 4: Akurasi Kalkulasi Telemetri Mode B (Mandiri).
     * Pengajuan cuti oleh staf dengan disiplin presensi tinggi (100% on_time),
     * sisa masa berlaku saldo DP yang hampir habis (mendekati expires_at),
     * belum pernah cuti dalam 90 hari terakhir, pengajuan jauh-jauh hari (>= 14 hari),
     * dan ketersediaan staf departemen memadai menghasilkan smart_score tinggi (>= 70.00, recommended)
     * yang tersimpan otomatis di leave_requests.
     */
    public function test_scenario_4_mode_b_single_telemetry_calculation_produces_high_recommended_score(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        // Kredit DP yang akan segera kedaluwarsa dalam 5 hari (2026-06-06) -> C2 sangat prioritas
        $this->ledgerService->recordCredit($this->staffEmployeeA, 'DP', 2.00, [
            'expires_at' => '2026-06-06',
        ]);

        // Riwayat presensi 100% tepat waktu dalam 90 hari terakhir -> C3 = 100.0
        for ($i = 1; $i <= 5; $i++) {
            Attendance::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $this->staffEmployeeA->id,
                'clock_in_at' => Carbon::create(2026, 5, 10 + $i, 8, 0, 0, 'Asia/Jakarta'),
                'clock_out_at' => Carbon::create(2026, 5, 10 + $i, 16, 0, 0, 'Asia/Jakarta'),
                'status' => 'on_time',
                'late_minutes' => 0,
            ]);
        }

        $dpType = LeaveType::findByCode('DP', $this->company->id);

        // Pengajuan jauh-jauh hari (H+15: 2026-06-16 s/d 2026-06-17) -> C5 = 14.0 (maksimum)
        $response = $this->actingAs($this->staffUserA)
            ->postJson(route('leave.store'), [
                'leave_type_id' => $dpType->id,
                'start_date' => '2026-06-16',
                'end_date' => '2026-06-17',
                'reason' => 'Klaim hak DP sebelum masa aktif kuota berakhir.',
            ]);

        $response->assertCreated();

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->staffEmployeeA->id)
            ->firstOrFail();

        $this->assertNotNull($leaveRequest->smart_score);
        $this->assertGreaterThanOrEqual(70.00, (float) $leaveRequest->smart_score);
        $this->assertIsArray($leaveRequest->smart_metrics);
        $this->assertSame('B', $leaveRequest->smart_metrics['mode']);
        $this->assertSame(SmartDecisionService::REC_RECOMMENDED, $leaveRequest->smart_metrics['recommendation']);
        $this->assertSame(100.0, (float) $leaveRequest->smart_metrics['criteria']['C3']['raw_value']);
        $this->assertSame(0.0, (float) $leaveRequest->smart_metrics['criteria']['C4']['raw_value']);
        $this->assertSame(14.0, (float) $leaveRequest->smart_metrics['criteria']['C5']['raw_value']);
    }

    /**
     * Skenario 5: Sensitivitas Kriteria Cost & Benefit.
     * Membuktikan bahwa pengajuan mendadak (H-0), riwayat sering terlambat, sudah sering cuti dalam 90 hari terakhir,
     * dan formasi shift kritis menghasilkan smart_score lebih rendah secara deterministik dibandingkan staf berkinerja baik.
     */
    public function test_scenario_5_cost_and_benefit_criteria_sensitivity_distinguishes_high_vs_low_priority_requests(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $alType = LeaveType::findByCode('AL', $this->company->id);

        // Staf A: saldo akan habis dalam 7 hari, disiplin 100%, belum pernah cuti 90 hari terakhir, pengajuan H+14
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-06-08',
        ]);
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeA->id,
            'clock_in_at' => Carbon::create(2026, 5, 20, 8, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 5, 20, 16, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);

        $reqGood = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-16',
            'reason' => 'Pengajuan terencana staf disiplin tinggi.',
        ], null, $this->staffUserA);

        // Staf B: saldo masih lama (90+ hari), semua presensi terlambat, sudah pernah cuti 10 hari baru-baru ini, pengajuan H-0 mendadak
        $this->ledgerService->recordAccrual($this->staffEmployeeB, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);
        for ($i = 1; $i <= 4; $i++) {
            Attendance::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'employee_id' => $this->staffEmployeeB->id,
                'clock_in_at' => Carbon::create(2026, 5, 10 + $i, 8, 45, 0, 'Asia/Jakarta'),
                'clock_out_at' => Carbon::create(2026, 5, 10 + $i, 16, 0, 0, 'Asia/Jakarta'),
                'status' => 'late',
                'late_minutes' => 45,
            ]);
        }
        // Riwayat cuti approved 10 hari dalam 90 hari terakhir
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeB->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'total_days' => 10.00,
            'reason' => 'Cuti panjang bulan lalu.',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $reqPoor = $this->leaveRequestService->submitRequest($this->staffEmployeeB, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Pengajuan mendadak H-0.',
        ], null, $this->staffUserB);

        $this->assertGreaterThan((float) $reqPoor->smart_score, (float) $reqGood->smart_score);
        $this->assertGreaterThanOrEqual(80.00, (float) $reqGood->smart_score);
        $this->assertLessThan(50.00, (float) $reqPoor->smart_score);
    }

    /**
     * Skenario 6: Evaluasi Komparatif Mode A (Multi-Staf Bentrok Tanggal).
     * Ketika >= 2 staf di departemen yang sama mengajukan cuti pada rentang tanggal yang beririsan,
     * evaluateComparativeForRequest mendeteksi 'mode' => 'A', mengurutkan kandidat berdasarkan smart_score tertinggi
     * (rank = 1, 2, ...), dan menandai batas kuota formasi departemen (can_approve_within_quota).
     */
    public function test_scenario_6_mode_a_comparative_evaluation_ranks_overlapping_candidates_and_simulates_formation_quota(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        // Departemen FO memiliki 4 staf aktif (staffEmployeeA, staffEmployeeB, staffEmployeeC, managerEmployee)
        // Set min_staff_per_shift = 3 sehingga dari 3 kandidat yang bentrok tanggal pada hari yang sama,
        // hanya kandidat peringkat #1 yang masih menjaga sisa staf >= 3 (4 - 1 = 3 >= 3),
        // sedangkan kandidat peringkat #2 dan #3 akan membuat sisa staf < 3 (can_approve_within_quota = false).
        $this->foDept->update(['min_staff_per_shift' => 3]);

        $alType = LeaveType::findByCode('AL', $this->company->id);

        // Kandidat A: disiplin 100%, saldo kedaluwarsa 10 hari lagi -> skor tertinggi (#1)
        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-06-11',
        ]);

        // Kandidat B: disiplin 50%, saldo kedaluwarsa 45 hari lagi -> skor menengah (#2)
        $this->ledgerService->recordAccrual($this->staffEmployeeB, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-07-16',
        ]);
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeB->id,
            'clock_in_at' => Carbon::create(2026, 5, 20, 8, 30, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 5, 20, 16, 0, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 30,
        ]);
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeB->id,
            'clock_in_at' => Carbon::create(2026, 5, 21, 8, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 5, 21, 16, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);

        // Kandidat C: disiplin 0%, sudah pernah cuti 12 hari, saldo kedaluwarsa lama -> skor terendah (#3)
        $this->ledgerService->recordAccrual($this->staffEmployeeC, 'AL', 12.00, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeC->id,
            'clock_in_at' => Carbon::create(2026, 5, 20, 9, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 5, 20, 16, 0, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 60,
        ]);
        LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeC->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-12',
            'total_days' => 12.00,
            'reason' => 'Cuti sebelumnya.',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $reqA = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-22',
            'reason' => 'Cuti Staf A.',
        ], null, $this->staffUserA);

        $reqB = $this->leaveRequestService->submitRequest($this->staffEmployeeB, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-21',
            'end_date' => '2026-06-23',
            'reason' => 'Cuti Staf B beririsan tanggal.',
        ], null, $this->staffUserB);

        $reqC = $this->leaveRequestService->submitRequest($this->staffEmployeeC, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-22',
            'reason' => 'Cuti Staf C beririsan tanggal.',
        ], null, $this->staffUserC);

        $comparative = $this->smartService->evaluateComparativeForRequest($reqB);

        $this->assertSame('A', $comparative['mode']);
        $this->assertCount(3, $comparative['candidates']);

        // Peringkat #1 adalah Staf A, #2 adalah Staf B, #3 adalah Staf C
        $this->assertSame(1, $comparative['candidates'][0]['rank']);
        $this->assertSame($reqA->id, $comparative['candidates'][0]['leave_request_id']);
        $this->assertTrue($comparative['candidates'][0]['can_approve_within_quota']);

        $this->assertSame(2, $comparative['candidates'][1]['rank']);
        $this->assertSame($reqB->id, $comparative['candidates'][1]['leave_request_id']);
        $this->assertFalse($comparative['candidates'][1]['can_approve_within_quota']);

        $this->assertSame(3, $comparative['candidates'][2]['rank']);
        $this->assertSame($reqC->id, $comparative['candidates'][2]['leave_request_id']);
        $this->assertFalse($comparative['candidates'][2]['can_approve_within_quota']);
    }

    /**
     * Skenario 7: Rekalkulasi Otomatis Saat Bobot Diubah.
     * Ketika Manajer/Admin mengubah bobot kriteria departemen, seluruh pengajuan cuti berstatus
     * pending_manager / pending_hr di departemen tersebut otomatis diperbarui nilai smart_score dan smart_metrics-nya.
     */
    public function test_scenario_7_updating_department_weights_automatically_recalculates_pending_requests(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $ulType = LeaveType::findByCode('UL', $this->company->id);

        // Staf B memiliki disiplin presensi 0% (selalu terlambat), tetapi mengajukan H+14 (C5 = 14 hari -> u5 = 100)
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staffEmployeeB->id,
            'clock_in_at' => Carbon::create(2026, 5, 25, 9, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 5, 25, 17, 0, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 60,
        ]);

        $pendingRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeB, [
            'leave_type_id' => $ulType->id,
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-21',
            'reason' => 'Izin tanpa bayar diajukan jauh hari.',
        ], null, $this->staffUserB);

        // Ubah bobot departemen sehingga 100% bertumpu pada C3 (Attendance Discipline, u3 = 0) -> skor menjadi 0.00
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 0,
                    'C2' => 0,
                    'C3' => 100,
                    'C4' => 0,
                    'C5' => 0,
                ],
            ])
            ->assertOk();

        $pendingRequest->refresh();
        $this->assertSame('0.00', $pendingRequest->smart_score);
        $this->assertSame(SmartDecisionService::REC_HIGH_RISK, $pendingRequest->smart_metrics['recommendation']);

        // Sekarang ubah bobot departemen sehingga 100% bertumpu pada C5 (Lead Time, u5 = 100) -> skor menjadi 100.00
        $this->actingAs($this->managerUser)
            ->putJson(route('leave.smartWeights.update'), [
                'department_id' => $this->foDept->id,
                'weights' => [
                    'C1' => 0,
                    'C2' => 0,
                    'C3' => 0,
                    'C4' => 0,
                    'C5' => 100,
                ],
            ])
            ->assertOk();

        $pendingRequest->refresh();
        $this->assertSame('100.00', $pendingRequest->smart_score);
        $this->assertSame(SmartDecisionService::REC_RECOMMENDED, $pendingRequest->smart_metrics['recommendation']);
    }

    /**
     * Skenario 8: Keamanan Endpoint Analisis SPK.
     * Endpoint GET /leave/{leaveRequest}/smart-analysis mengembalikan JSON analisis untuk Manajer departemen terkait
     * dan Admin perusahaan, namun menolak akses dari Staf biasa (user), Manajer departemen lain, atau Admin perusahaan lain (HTTP 403).
     */
    public function test_scenario_8_smart_analysis_endpoint_security_rbac_and_tenant_isolation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        $ulType = LeaveType::findByCode('UL', $this->company->id);

        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $ulType->id,
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-16',
            'reason' => 'Keperluan pribadi.',
        ], null, $this->staffUserA);

        // 1. Staf biasa (bahkan pemilik pengajuan sendiri) -> HTTP 403 Forbidden
        $this->actingAs($this->staffUserA)
            ->getJson(route('leave.smartAnalysis', $leaveRequest))
            ->assertForbidden();

        // 2. Manajer departemen yang sama (FO) -> HTTP 200 OK
        $this->actingAs($this->managerUser)
            ->getJson(route('leave.smartAnalysis', $leaveRequest))
            ->assertOk()
            ->assertJsonPath('mode', 'B')
            ->assertJsonStructure([
                'success',
                'mode',
                'smart_score',
                'recommendation',
                'criteria' => ['C1', 'C2', 'C3', 'C4', 'C5'],
                'candidates',
            ]);

        // 3. Admin perusahaan yang sama -> HTTP 200 OK
        $this->actingAs($this->adminUser)
            ->getJson(route('leave.smartAnalysis', $leaveRequest))
            ->assertOk()
            ->assertJsonPath('target_request_id', $leaveRequest->id);

        // 4. Manajer departemen lain (HK) di perusahaan yang sama -> HTTP 403 Forbidden
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
            ->getJson(route('leave.smartAnalysis', $leaveRequest))
            ->assertForbidden();

        // 5. Admin dari perusahaan lain -> HTTP 403 Forbidden
        $otherCompany = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport',
            'latitude' => '-7.9048250',
            'longitude' => '110.0572110',
            'geofence_radius_meters' => 100,
            'storage_limit_mb' => 500,
            'is_active' => true,
        ]);

        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Admin Ibis',
            'username' => 'admin.ibis',
            'email' => 'admin@ibisyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($otherAdmin)
            ->getJson(route('leave.smartAnalysis', $leaveRequest))
            ->assertForbidden();
    }

    /**
     * Skenario 8: Mode Debug SPK SMART & Simulator What-If (Eksklusif Super Admin).
     * - Hanya super_admin yang dapat mengakses endpoint POST /leave/smart-debug dan melihat panel Mode Debug di /leave.
     * - Role super_user, admin, manager, dan user wajib menerima HTTP 403 Forbidden.
     * - Simulator Mode A (multi-kandidat), Mode B (mandiri), dan inspeksi telemetri database bekerja akurat tanpa mengubah data produksi.
     */
    public function test_scenario_8_super_admin_exclusive_smart_debug_simulator_and_telemetry_inspection(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 9, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $alType = LeaveType::findByCode('AL', $this->company->id);
        $leaveRequest = $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-21',
            'reason' => 'Simulasi inspeksi telemetri debug SPK SMART.',
        ]);

        $superAdmin = User::factory()->create([
            'company_id' => null,
            'name' => 'Root Super Admin',
            'username' => 'root.smart.debug',
            'email' => 'root.smart.debug@oatcake.id',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $superUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'GM Super User',
            'username' => 'gm.smart.debug',
            'email' => 'gm.smart.debug@novotelyia.com',
            'role' => 'super_user',
            'is_active' => true,
        ]);

        $payloadModeA = [
            'weights' => ['C1' => 30, 'C2' => 20, 'C3' => 15, 'C4' => 15, 'C5' => 20],
            'min_staff_per_shift' => 3,
            'total_department_staff' => 4,
            'leave_request_id' => $leaveRequest->id,
            'candidates' => [
                ['name' => 'Kandidat Alpha', 'C1' => 85, 'C2' => 30, 'C3' => 98, 'C4' => 1, 'C5' => 0],
                ['name' => 'Kandidat Beta', 'C1' => 45, 'C2' => 5, 'C3' => 90, 'C4' => 0, 'C5' => 3],
            ],
        ];

        // 1. Non-super_admin (super_user, admin, manager, user) wajib ditolak 403 Forbidden
        foreach ([$superUser, $this->adminUser, $this->managerUser, $this->staffUserA] as $nonSuperAdmin) {
            $this->actingAs($nonSuperAdmin)
                ->postJson(route('leave.smartDebug'), $payloadModeA)
                ->assertForbidden();

            $this->actingAs($nonSuperAdmin)
                ->get(route('leave.index'))
                ->assertOk()
                ->assertDontSee('smart-debug-modal', false)
                ->assertDontSee(__('leave.btn_smart_debug'));
        }

        // 2. Super Admin melihat tombol & modal Mode Debug di GET /leave
        $this->actingAs($superAdmin)
            ->withSession(['active_company_id' => $this->company->id])
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('smart-debug-modal', false)
            ->assertSee(__('leave.btn_smart_debug'));

        // 3. Super Admin menjalankan simulasi Mode A + inspeksi telemetri DB -> HTTP 200 OK
        $modeAResponse = $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), $payloadModeA);

        $modeAResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mode', 'A')
            ->assertJsonPath('weights.normalized.C1', 0.3)
            ->assertJsonPath('results.0.name', 'Kandidat Alpha')
            ->assertJsonPath('results.0.rank', 1)
            ->assertJsonPath('results.0.can_approve_within_quota', true)
            ->assertJsonPath('results.1.name', 'Kandidat Beta')
            ->assertJsonPath('results.1.can_approve_within_quota', false)
            ->assertJsonStructure([
                'results' => [
                    ['rank', 'name', 'smart_score', 'recommendation', 'can_approve_within_quota', 'criteria' => ['C1', 'C2', 'C3', 'C4', 'C5']],
                ],
                'telemetry' => ['employee_id', 'employee_name', 'department', 'attendance_90d', 'leave_frequency_90d'],
            ]);

        // 4. Super Admin menjalankan simulasi Mode B langsung dari DB telemetry tanpa kandidat manual
        $modeBResponse = $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 30, 'C2' => 20, 'C3' => 15, 'C4' => 15, 'C5' => 20],
                'leave_request_id' => $leaveRequest->id,
                'candidates' => [],
            ]);

        $modeBResponse->assertOk()
            ->assertJsonPath('mode', 'B')
            ->assertJsonCount(1, 'results');
    }

    /**
     * Skenario 9: Keterangan Kriteria C1–C5, Coupled Lockable Sliders 100%, & Paritas Bahasa pada Mode Debug SPK SMART.
     * - Halaman /leave untuk super_admin merender slider terkopel C1–C5, tombol lock/unlock, keterangan kriteria C1–C5,
     *   lencana Benefit/Cost, skala satuan, serta nama karyawan asli tanpa atribut nik.
     * - Role non-super_admin (super_user, admin, manager, user) tidak pernah melihat elemen debug slider/lock.
     * - Endpoint POST /leave/smart-debug menegakkan invarian total bobot wajib tepat 100%.
     */
    public function test_scenario_9_smart_debug_console_renders_criteria_descriptions_coupled_lockable_sliders_and_enforces_100_percent_invariant(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 9, 0, 0, 'Asia/Jakarta'));

        $this->ledgerService->recordAccrual($this->staffEmployeeA, 'AL', 12, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        $alType = LeaveType::findByCode('AL', $this->company->id);
        $this->leaveRequestService->submitRequest($this->staffEmployeeA, [
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-21',
            'reason' => 'Verifikasi tampilan deskripsi kriteria dan slider terkopel 100%.',
        ], null, $this->staffUserA);

        $superAdmin = User::factory()->create([
            'company_id' => null,
            'name' => 'Root Debug Architect',
            'username' => 'root.debug.sliders',
            'email' => 'root.debug.sliders@oatcake.id',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $superUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'GM Non Debug',
            'username' => 'gm.non.debug',
            'email' => 'gm.non.debug@novotelyia.com',
            'role' => 'super_user',
            'is_active' => true,
        ]);

        $prevLocale = app()->getLocale();

        try {
            // 1. Verifikasi render Bahasa Indonesia (id) untuk super_admin
            $superAdmin->forceFill(['locale' => 'id'])->save();
            app()->setLocale('id');
            $idResponse = $this->actingAs($superAdmin)
                ->withSession(['active_company_id' => $this->company->id, 'locale' => 'id'])
                ->get(route('leave.index'))
                ->assertOk()
                ->assertSee('smart-debug-modal', false)
                ->assertSee('debug_department_select', false)
                ->assertSee('debug_request_select', false)
                ->assertSee('onDebugSliderChange', false)
                ->assertSee('toggleDebugWeightLock', false)
                ->assertSee('debugNormalizedPreview', false)
                ->assertSee('rebalanceCoupledWeights', false)
                ->assertSee('loadDebugDeptWeights', false)
                ->assertSee('onDebugRequestChange', false)
                ->assertSee('resetDebugWeights', false)
                ->assertSee('Rina Kusuma')
                ->assertDontSee('"nik":', false);

            foreach (['C1', 'C2', 'C3', 'C4', 'C5'] as $code) {
                $lower = strtolower($code);
                $idResponse
                    ->assertSee("debug_weight_slider_{$code}", false)
                    ->assertSee("debug_weight_lock_{$code}", false)
                    ->assertSee(__("leave.debug_criteria_{$lower}_title"))
                    ->assertSee(__("leave.debug_criteria_{$lower}_scale"))
                    ->assertSee(__("leave.debug_criteria_{$lower}_desc"));
            }

            $idResponse
                ->assertSee(__('leave.debug_badge_benefit'))
                ->assertSee(__('leave.debug_badge_cost'))
                ->assertSee(__('leave.smart_lock_criterion'))
                ->assertSee(__('leave.smart_weight_total_required'));

            // 2. Verifikasi render Bahasa Inggris (en) untuk super_admin
            $superAdmin->forceFill(['locale' => 'en'])->save();
            app()->setLocale('en');
            $enResponse = $this->actingAs($superAdmin)
                ->withSession(['active_company_id' => $this->company->id, 'locale' => 'en'])
                ->get(route('leave.index'))
                ->assertOk()
                ->assertSee('Lock Weight')
                ->assertSee('Total Weight (Must Equal 100%)')
                ->assertSee('0–90 days')
                ->assertSee('0–12 days')
                ->assertSee('0–14 days');

            foreach (['C1', 'C2', 'C3', 'C4', 'C5'] as $code) {
                $lower = strtolower($code);
                $enResponse
                    ->assertSee(__("leave.debug_criteria_{$lower}_title"))
                    ->assertSee(__("leave.debug_criteria_{$lower}_desc"));
            }
        } finally {
            app()->setLocale($prevLocale);
        }

        // 3. Isolasi ketat: non-super_admin tidak boleh melihat modal/slider/lock debug
        foreach ([$superUser, $this->adminUser, $this->managerUser, $this->staffUserA] as $nonSuperAdmin) {
            $this->actingAs($nonSuperAdmin)
                ->get(route('leave.index'))
                ->assertOk()
                ->assertDontSee('smart-debug-modal', false)
                ->assertDontSee('debug_weight_slider_C1', false)
                ->assertDontSee('debug_weight_lock_C1', false);
        }

        $baseCandidates = [
            ['name' => 'Kandidat Alpha', 'C1' => 85, 'C2' => 30, 'C3' => 98, 'C4' => 1, 'C5' => 0],
            ['name' => 'Kandidat Beta', 'C1' => 45, 'C2' => 5, 'C3' => 90, 'C4' => 0, 'C5' => 3],
        ];

        // 4. Penegakan invarian 100% di backend POST /leave/smart-debug
        $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 30, 'C2' => 20, 'C3' => 15, 'C4' => 10, 'C5' => 10], // Sum = 85
                'candidates' => $baseCandidates,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);

        $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 40, 'C2' => 30, 'C3' => 15, 'C4' => 15, 'C5' => 10], // Sum = 110
                'candidates' => $baseCandidates,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);

        $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 0, 'C2' => 0, 'C3' => 0, 'C4' => 0, 'C5' => 0], // Sum = 0
                'candidates' => $baseCandidates,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weights']);

        // Preset Critical Staff (50/15/15/10/10) -> Sum = 100 -> HTTP 200
        $presetResp = $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 50, 'C2' => 15, 'C3' => 15, 'C4' => 10, 'C5' => 10],
                'min_staff_per_shift' => 3,
                'total_department_staff' => 4,
                'candidates' => $baseCandidates,
            ])
            ->assertOk()
            ->assertJsonPath('weights.normalized.C1', 0.5)
            ->assertJsonPath('weights.normalized.C2', 0.15)
            ->assertJsonPath('weights.normalized.C3', 0.15)
            ->assertJsonPath('weights.normalized.C4', 0.1)
            ->assertJsonPath('weights.normalized.C5', 0.1);

        $normSumPreset = array_sum($presetResp->json('weights.normalized'));
        $this->assertSame(1.0, round($normSumPreset, 4));

        // Distribusi hasil penguncian kriteria (65/20/15/0/0) -> Sum = 100 -> HTTP 200
        $lockedResp = $this->actingAs($superAdmin)
            ->postJson(route('leave.smartDebug'), [
                'weights' => ['C1' => 65, 'C2' => 20, 'C3' => 15, 'C4' => 0, 'C5' => 0],
                'candidates' => $baseCandidates,
            ])
            ->assertOk()
            ->assertJsonPath('weights.normalized.C1', 0.65)
            ->assertJsonPath('weights.normalized.C2', 0.2)
            ->assertJsonPath('weights.normalized.C3', 0.15)
            ->assertJsonPath('weights.normalized.C4', 0)
            ->assertJsonPath('weights.normalized.C5', 0);

        $normSumLocked = array_sum($lockedResp->json('weights.normalized'));
        $this->assertSame(1.0, round($normSumLocked, 4));
    }
}
