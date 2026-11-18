<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LeaveLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Employee $employee;
    protected LeaveLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $staffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina.fo',
            'email' => 'rina.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $staffUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2024-03-15',
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
     * Skenario 1: Pencatatan akrual dan kredit menambah saldo balance_after dan remaining_amount secara presisi.
     */
    public function test_scenario_1_accrual_and_credit_entries_accurately_increment_balance_and_remaining_amount(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 8, 0, 0, 'Asia/Jakarta'));

        $this->assertSame(0, $this->ledgerService->getBalance($this->employee, 'AL'));
        $this->assertSame(0, $this->ledgerService->getBalance($this->employee, 'DP'));

        // 1. Akrual Cuti Tahunan (AL) +12 hari (Integer)
        $accrualEntry = $this->ledgerService->recordAccrual($this->employee, 'AL', 12, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
            'notes' => 'Akrual cuti tahunan 2026',
        ]);

        $this->assertSame(LeaveLedgerEntry::TYPE_ACCRUAL, $accrualEntry->transaction_type);
        $this->assertSame(12, $accrualEntry->amount);
        $this->assertSame(12, $accrualEntry->remaining_amount);
        $this->assertSame(12, $accrualEntry->balance_after);
        $this->assertSame('2026-12-31', $accrualEntry->expires_at?->toDateString());
        $this->assertSame(12, $this->ledgerService->getBalance($this->employee, 'AL'));

        // 2. Kredit DP pertama (+1 hari) dengan validity_months default (3 bulan -> 2026-04-10)
        $dpCredit1 = $this->ledgerService->recordCredit($this->employee, 'DP', 1, [
            'reference_type' => 'attendance',
            'reference_id' => 101,
        ]);

        $this->assertSame(LeaveLedgerEntry::TYPE_CREDIT, $dpCredit1->transaction_type);
        $this->assertSame(1, $dpCredit1->amount);
        $this->assertSame(1, $dpCredit1->remaining_amount);
        $this->assertSame(1, $dpCredit1->balance_after);
        $this->assertSame('2026-04-10', $dpCredit1->expires_at?->toDateString());

        // 3. Kredit DP kedua (+2 hari)
        $dpCredit2 = $this->ledgerService->recordCredit($this->employee, 'DP', 2, [
            'reference_type' => 'manual_grant',
            'expires_at' => '2026-05-15',
        ]);

        $this->assertSame(2, $dpCredit2->amount);
        $this->assertSame(2, $dpCredit2->remaining_amount);
        $this->assertSame(3, $dpCredit2->balance_after);
        $this->assertSame(3, $this->ledgerService->getBalance($this->employee, 'DP'));
    }

    /**
     * Skenario 2: Pemotongan (debit) menggunakan antrean FIFO (menguras bucket dengan expires_at paling dekat terlebih dahulu)
     * dan menolak pengurangan jika saldo tidak mencukupi.
     */
    public function test_scenario_2_debit_depletes_buckets_in_fifo_expiry_order_and_rejects_insufficient_balance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0, 'Asia/Jakarta'));

        // Buat 3 bucket kredit DP dengan urutan tanggal kedaluwarsa berbeda:
        // Bucket 1: 3 hari, kedaluwarsa lebih lambat (2026-09-30)
        $bucketLater = $this->ledgerService->recordCredit($this->employee, 'DP', 3, [
            'expires_at' => '2026-09-30',
        ]);

        // Bucket 2: 2 hari, kedaluwarsa paling dekat (2026-07-15) -> harus dikuras pertama kali!
        $bucketSooner = $this->ledgerService->recordCredit($this->employee, 'DP', 2, [
            'expires_at' => '2026-07-15',
        ]);

        // Bucket 3: 1 hari, tanpa batas kedaluwarsa (NULLS LAST) -> harus dikuras paling akhir!
        $bucketNoExpiry = $this->ledgerService->recordCredit($this->employee, 'DP', 1, [
            'expires_at' => null,
        ]);

        $this->assertSame(6, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Debit 4 hari (integer):
        // - Bucket 2 (2026-07-15, 2) habis -> remaining_amount = 0
        // - Bucket 1 (2026-09-30, 3) terpakai 2 -> remaining_amount = 1
        // - Bucket 3 (null, 1) utuh -> remaining_amount = 1
        $debitEntry = $this->ledgerService->recordDebit($this->employee, 'DP', 4, [
            'reference_type' => 'leave_request',
            'reference_id' => 501,
            'notes' => 'Pengambilan DP 4 hari',
        ]);

        $this->assertSame(LeaveLedgerEntry::TYPE_DEBIT, $debitEntry->transaction_type);
        $this->assertSame(-4, $debitEntry->amount);
        $this->assertSame(0, $debitEntry->remaining_amount);
        $this->assertSame(2, $debitEntry->balance_after);
        $this->assertSame($bucketSooner->id, $debitEntry->source_entry_id);

        $this->assertSame(0, $bucketSooner->fresh()->remaining_amount);
        $this->assertSame(1, $bucketLater->fresh()->remaining_amount);
        $this->assertSame(1, $bucketNoExpiry->fresh()->remaining_amount);
        $this->assertSame(2, $this->ledgerService->getBalance($this->employee, 'DP'));

        // Coba debit 3 hari ketika sisa saldo hanya 2 -> wajib gagal dengan RuntimeException
        $this->expectException(RuntimeException::class);
        $this->ledgerService->recordDebit($this->employee, 'DP', 3);
    }

    /**
     * Skenario 3: Immutability Guard menolak penghapusan (delete()) dan menolak manipulasi
     * nilai historis amount/balance_after pada baris buku besar yang sudah tersimpan.
     */
    public function test_scenario_3_strict_immutability_guard_blocks_deletion_and_core_column_tampering(): void
    {
        $entry = $this->ledgerService->recordAccrual($this->employee, 'AL', 12, [
            'accrual_year' => 2026,
            'expires_at' => '2026-12-31',
        ]);

        // 1. Percobaan hapus baris wajib melempar LogicException
        try {
            $entry->delete();
            $this->fail('Expected LogicException when deleting a LeaveLedgerEntry.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        // Pastikan baris masih ada di database
        $this->assertDatabaseHas('leave_ledger_entries', [
            'id' => $entry->id,
            'amount' => 12,
            'balance_after' => 12,
        ]);

        // 2. Percobaan mengubah kolom finansial amount atau balance_after wajib melempar LogicException
        try {
            $entry->fresh()->update(['amount' => 99]);
            $this->fail('Expected LogicException when modifying amount on LeaveLedgerEntry.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        try {
            $entry->fresh()->update(['balance_after' => 50]);
            $this->fail('Expected LogicException when modifying balance_after on LeaveLedgerEntry.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        // Pastikan nilai asli tetap utuh
        $fresh = $entry->fresh();
        $this->assertSame(12, $fresh->amount);
        $this->assertSame(12, $fresh->balance_after);
    }

    /**
     * Skenario 4: Command leave:accrue-annual memberikan kuota cuti tahunan secara idempoten
     * (eksekusi kedua di tahun yang sama tidak menambah saldo ganda) dan terisolasi per tenant.
     */
    public function test_scenario_4_accrue_annual_command_grants_quota_idempotently_and_respects_tenant_isolation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 2, 6, 0, 0, 'Asia/Jakarta'));

        // Buat tenant kedua (Company B) beserta karyawan aktifnya
        $secondCompany = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius_meters' => 100,
            'is_active' => true,
        ]);

        $secondDept = Department::withoutGlobalScopes()->create([
            'company_id' => $secondCompany->id,
            'code' => 'FO',
            'name' => 'Front Office Ibis',
            'is_operational' => true,
            'min_staff_per_shift' => 1,
        ]);

        $secondEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $secondCompany->id,
            'department_id' => $secondDept->id,
            'position' => 'Receptionist',
            'join_date' => '2025-02-01',
            'status' => 'active',
        ]);

        // Buat karyawan non-aktif (resigned) yang tidak boleh menerima akrual
        $resignedEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->foDept->id,
            'position' => 'Former Bellboy',
            'join_date' => '2024-01-10',
            'status' => 'resigned',
        ]);

        // Eksekusi pertama command leave:accrue-annual --year=2026
        $this->artisan('leave:accrue-annual', ['--year' => 2026])
            ->assertExitCode(0);

        $this->assertSame(12, $this->ledgerService->getBalance($this->employee, 'AL'));
        $this->assertSame(12, $this->ledgerService->getBalance($secondEmployee, 'AL'));
        $this->assertSame(0, $this->ledgerService->getBalance($resignedEmployee, 'AL'));

        $emp1Entry = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->where('accrual_year', 2026)
            ->firstOrFail();

        $emp2Entry = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('employee_id', $secondEmployee->id)
            ->where('accrual_year', 2026)
            ->firstOrFail();

        $this->assertSame($this->company->id, $emp1Entry->company_id);
        $this->assertSame($secondCompany->id, $emp2Entry->company_id);
        $this->assertSame('2026-12-31', $emp1Entry->expires_at?->toDateString());

        // Eksekusi kedua pada tahun yang sama (Idempotency Check)
        $this->artisan('leave:accrue-annual', ['--year' => 2026])
            ->assertExitCode(0);

        $this->assertSame(12, $this->ledgerService->getBalance($this->employee, 'AL'));
        $this->assertSame(12, $this->ledgerService->getBalance($secondEmployee, 'AL'));

        $this->assertSame(
            1,
            LeaveLedgerEntry::withoutGlobalScopes()
                ->where('employee_id', $this->employee->id)
                ->where('accrual_year', 2026)
                ->count()
        );
    }
}
