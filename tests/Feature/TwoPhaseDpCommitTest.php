<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\DpCommitService;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TwoPhaseDpCommitTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Employee $manager;
    protected Employee $employee;
    protected Shift $morningShift;
    protected LeaveLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            PublicHolidaySeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $this->morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $managerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Arif Nugroho',
            'username' => 'arif.fo.dp',
            'email' => 'arif.fo.dp@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->manager = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $managerUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-05-01',
            'status' => 'active',
        ]);

        $staffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.fo.dp',
            'email' => 'budi.fo.dp@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $staffUser->id,
            'department_id' => $this->foDept->id,
            'manager_id' => $this->manager->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $this->ledgerService = app(LeaveLedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Clock-in pada hari libur nasional hanya berstatus is_provisional_dp = true (saldo DP masih 0).
     * Saat staf Clock-Out dengan durasi kerja >= 4 jam (8 jam), sistem otomatis mengkomit +1.00 DP
     * ke leave_ledger_entries dan mengisi dp_committed_at secara idempoten.
     */
    public function test_scenario_1_holiday_clock_in_and_qualifying_clock_out_commits_one_dp_to_ledger(): void
    {
        // Phase 1: Clock-In pada Hari Kemerdekaan RI (17 Agustus 2026 07:00 WIB)
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 7, 0, 0, 'Asia/Jakarta'));

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $this->morningShift->id,
            'schedule_date' => '2026-08-17',
            'status' => 'published',
        ]);

        $clockInResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('clockin.jpg'),
        ]);

        $clockInResponse->assertOk()
            ->assertJsonPath('data.is_provisional_dp', true);

        // Pastikan saat baru Clock-In, saldo DP masih 0.0
        $this->assertSame(0.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Phase 2: Clock-Out 8 jam kemudian (17 Agustus 2026 15:00 WIB)
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 15, 0, 0, 'Asia/Jakarta'));

        $clockOutResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('clockout.jpg'),
        ]);

        $clockOutResponse->assertOk()
            ->assertJsonPath('data.work_duration_hours', 8);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertNotNull($attendance->dp_committed_at);
        $this->assertNotNull($attendance->dp_ledger_entry_id);
        $this->assertSame(1.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        $ledgerEntry = LeaveLedgerEntry::withoutGlobalScopes()->findOrFail($attendance->dp_ledger_entry_id);
        $this->assertSame(LeaveLedgerEntry::TYPE_CREDIT, $ledgerEntry->transaction_type);
        $this->assertSame('1.00', $ledgerEntry->amount);
        $this->assertSame('1.00', $ledgerEntry->remaining_amount);
        $this->assertSame('1.00', $ledgerEntry->balance_after);
        $this->assertSame('attendance', $ledgerEntry->reference_type);
        $this->assertSame($attendance->id, $ledgerEntry->reference_id);
        // 3 bulan sejak 2026-08-17 -> 2026-11-17
        $this->assertSame('2026-11-17', $ledgerEntry->expires_at?->toDateString());

        // Uji Idempotensi: memanggil ulang attemptCommitFromAttendance tidak pernah menggandakan DP
        $duplicateAttempt = app(DpCommitService::class)->attemptCommitFromAttendance($attendance);
        $this->assertNull($duplicateAttempt);
        $this->assertSame(1.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Pastikan UI Riwayat Presensi menampilkan badge DP Committed ✦ +1.0
        $historyView = $this->actingAs($this->employee->user)->get(route('attendance.index'));
        $historyView->assertOk()
            ->assertSee(__('attendance.badge_dp_committed'));
    }

    /**
     * Skenario 2: Clock-out pada hari libur nasional dengan durasi kerja < 4 jam (misal hanya 2 jam)
     * TIDAK mengkomit saldo DP ke buku besar (saldo DP tetap 0).
     */
    public function test_scenario_2_holiday_clock_out_under_four_hours_does_not_commit_dp(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 7, 0, 0, 'Asia/Jakarta'));

        $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('clockin_short.jpg'),
        ])->assertOk();

        // Clock-Out hanya 2 jam kemudian (09:00 WIB < 4.0 jam)
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 9, 0, 0, 'Asia/Jakarta'));

        $clockOutResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('clockout_short.jpg'),
        ]);

        $clockOutResponse->assertOk()
            ->assertJsonPath('data.work_duration_hours', 2);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertTrue($attendance->is_provisional_dp);
        $this->assertNull($attendance->dp_committed_at);
        $this->assertNull($attendance->dp_ledger_entry_id);
        $this->assertSame(0.0, $this->ledgerService->getBalance($this->employee, 'DP'));
        $this->assertDatabaseCount('leave_ledger_entries', 0);
    }

    /**
     * Skenario 3: Presensi dinas luar pada hari libur nasional yang sudah Clock-Out (>= 4 jam)
     * belum menambah saldo DP selama statusnya masih pending_verification, dan baru otomatis
     * mengkomit +1.00 DP sesaat setelah disetujui oleh Manajer (approve).
     */
    public function test_scenario_3_outside_duty_on_holiday_commits_dp_only_after_manager_approval(): void
    {
        // Hari Raya Natal: 25 Desember 2026 08:00 WIB
        Carbon::setTestNow(Carbon::create(2026, 12, 25, 8, 0, 0, 'Asia/Jakarta'));

        $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9001200,
            'longitude' => 110.0598500,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Bandara YIA Terminal Kedatangan',
            'notes' => 'Penjemputan VIP Natal pagi hingga sore.',
            'photo' => UploadedFile::fake()->image('outside_in.jpg'),
        ])->assertCreated();

        // Staf kembali ke hotel dan melakukan Clock-Out pukul 14:30 WIB (durasi 6.5 jam >= 4 jam)
        Carbon::setTestNow(Carbon::create(2026, 12, 25, 14, 30, 0, 'Asia/Jakarta'));

        $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('outside_out.jpg'),
        ])->assertOk();

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        // Karena status masih pending_verification, saldo DP wajib masih 0!
        $this->assertSame('pending_verification', $attendance->status);
        $this->assertNull($attendance->dp_committed_at);
        $this->assertNull($attendance->dp_ledger_entry_id);
        $this->assertSame(0.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Manajer menyetujui presensi dinas luar tersebut
        Carbon::setTestNow(Carbon::create(2026, 12, 25, 16, 0, 0, 'Asia/Jakarta'));

        $approveResponse = $this->actingAs($this->manager->user)
            ->postJson(route('attendance.verifications.approve', $attendance));

        $approveResponse->assertOk()
            ->assertJsonPath('success', true);

        $attendance->refresh();
        $this->assertSame('on_time', $attendance->status);
        $this->assertNotNull($attendance->dp_committed_at);
        $this->assertNotNull($attendance->dp_ledger_entry_id);
        $this->assertSame(1.0, $this->ledgerService->getBalance($this->employee, 'DP'));
    }

    /**
     * Skenario 4: Command leave:prune-expired memindai saldo DP/cuti yang melewati expires_at,
     * mencatat mutasi negatif bertipe expired, mengosongkan remaining_amount pada bucket asal,
     * dan menurunkan saldo aktif karyawan secara akurat.
     */
    public function test_scenario_4_prune_expired_command_sweeps_expired_buckets_and_records_negative_ledger_entries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 1, 8, 0, 0, 'Asia/Jakarta'));

        // Bucket 1: DP +1.00 hari yang kedaluwarsa pada 2026-11-01
        $expiredBucket = $this->ledgerService->recordCredit($this->employee, 'DP', 1.00, [
            'expires_at' => '2026-11-01',
            'notes' => 'DP Agustus',
        ]);

        // Bucket 2: DP +1.00 hari yang masih aktif hingga 2026-12-15
        $activeBucket = $this->ledgerService->recordCredit($this->employee, 'DP', 1.00, [
            'expires_at' => '2026-12-15',
            'notes' => 'DP September',
        ]);

        $this->assertSame(2.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Majukan waktu ke 10 November 2026 (setelah Bucket 1 kedaluwarsa, tetapi sebelum Bucket 2 kedaluwarsa)
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 1, 0, 0, 'Asia/Jakarta'));

        $this->artisan('leave:prune-expired')
            ->assertExitCode(0);

        // Bucket 1 harus dikosongkan remaining_amount-nya menjadi 0.00
        $this->assertSame('0.00', $expiredBucket->fresh()->remaining_amount);
        // Bucket 2 tetap memiliki remaining_amount 1.00
        $this->assertSame('1.00', $activeBucket->fresh()->remaining_amount);

        // Pastikan baris transaksi bertipe 'expired' tercatat dengan amount -1.00 dan balance_after 1.00
        $sweepEntry = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->where('transaction_type', LeaveLedgerEntry::TYPE_EXPIRED)
            ->firstOrFail();

        $this->assertSame('-1.00', $sweepEntry->amount);
        $this->assertSame('0.00', $sweepEntry->remaining_amount);
        $this->assertSame('1.00', $sweepEntry->balance_after);
        $this->assertSame($expiredBucket->id, $sweepEntry->source_entry_id);
        $this->assertSame('expiry_sweep', $sweepEntry->reference_type);
        $this->assertSame($expiredBucket->id, $sweepEntry->reference_id);

        // Saldo akhir DP karyawan kini harus 1.00 hari
        $this->assertSame(1.0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Eksekusi ulang command leave:prune-expired pada hari yang sama tidak boleh menyapu ulang bucket yang sudah 0
        $this->artisan('leave:prune-expired')
            ->assertExitCode(0);

        $this->assertSame(1.0, $this->ledgerService->getBalance($this->employee, 'DP'));
    }
}
