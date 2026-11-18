<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
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

class CompanyHolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;
    protected Company $companyB;
    protected Department $foDeptA;
    protected User $adminA;
    protected User $adminB;

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

        $this->companyA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->companyB = Company::create([
            'name' => 'Ibis Styles Yogyakarta Airport',
            'code' => 'IBIS-YIA',
            'address' => 'Kulon Progo, DIY',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 100,
            'geofence_radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->foDeptA = Department::withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $this->adminA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Admin Company A',
            'username' => 'admin.comp.a',
            'email' => 'admin.a@novotelyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->adminB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'name' => 'Admin Company B',
            'username' => 'admin.comp.b',
            'email' => 'admin.b@ibisyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Isolasi CRUD Kalender Libur Per Perusahaan.
     * Admin Perusahaan A berhasil menambah event khusus / mengubah tanggal libur nasional di perusahaannya;
     * perubahan tersebut sama sekali tidak mengubah kalender libur Perusahaan B.
     */
    public function test_scenario_1_per_company_holiday_crud_is_strictly_isolated_between_tenants(): void
    {
        // Pastikan tidak ada baris yatim company_id IS NULL
        $this->assertSame(0, PublicHoliday::withoutGlobalScopes()->whereNull('company_id')->count());

        // Kedua perusahaan memiliki 12 hari libur nasional awal hasil seeding
        $this->assertSame(12, PublicHoliday::withoutGlobalScopes()->where('company_id', $this->companyA->id)->count());
        $this->assertSame(12, PublicHoliday::withoutGlobalScopes()->where('company_id', $this->companyB->id)->count());

        // 1. Admin A menambahkan event khusus perusahaan pada 2026-09-10
        $storeResponse = $this->actingAs($this->adminA)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-09-10',
            'name' => 'Anniversary Novotel YIA ke-5',
            'is_national' => false,
        ]);

        $storeResponse->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertTrue(PublicHoliday::isHoliday('2026-09-10', $this->companyA->id));
        $this->assertFalse(PublicHoliday::isHoliday('2026-09-10', $this->companyB->id));

        // 2. Admin A mengubah tanggal libur Nyepi (2026-03-19) di Perusahaan A menjadi 2026-03-18
        $nyepiA = PublicHoliday::withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->whereDate('holiday_date', '2026-03-19')
            ->firstOrFail();

        $updateResponse = $this->actingAs($this->adminA)->putJson(route('settings.holidays.update', $nyepiA), [
            'holiday_date' => '2026-03-18',
            'name' => 'Cuti Bersama & Nyepi Properti A',
            'is_national' => true,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('success', true);

        // Di Perusahaan A, 2026-03-18 menjadi libur dan 2026-03-19 bukan lagi libur
        $this->assertTrue(PublicHoliday::isHoliday('2026-03-18', $this->companyA->id));
        $this->assertFalse(PublicHoliday::isHoliday('2026-03-19', $this->companyA->id));

        // Di Perusahaan B, 2026-03-19 tetap utuh sebagai Hari Suci Nyepi dan 2026-03-18 bukan libur
        $this->assertTrue(PublicHoliday::isHoliday('2026-03-19', $this->companyB->id));
        $this->assertFalse(PublicHoliday::isHoliday('2026-03-18', $this->companyB->id));

        // 3. Admin A mencoba mengubah atau menghapus hari libur milik Perusahaan B -> ditolak 403
        $nyepiB = PublicHoliday::withoutGlobalScopes()
            ->where('company_id', $this->companyB->id)
            ->whereDate('holiday_date', '2026-03-19')
            ->firstOrFail();

        $this->actingAs($this->adminA)
            ->putJson(route('settings.holidays.update', $nyepiB), [
                'holiday_date' => '2026-03-22',
                'name' => 'Sabotase Lintas Perusahaan',
                'is_national' => false,
            ])
            ->assertStatus(403);

        $this->actingAs($this->adminA)
            ->deleteJson(route('settings.holidays.destroy', $nyepiB))
            ->assertStatus(403);

        $this->assertTrue(PublicHoliday::isHoliday('2026-03-19', $this->companyB->id));
    }

    /**
     * Skenario 2: Otorisasi RBAC Kalender Libur.
     * admin, super_user, dan super_admin dapat menambah, mengedit, dan menghapus hari libur perusahaannya,
     * sedangkan manager dan user ditolak dengan HTTP 403 Forbidden.
     */
    public function test_scenario_2_holiday_rbac_allows_admin_and_above_but_forbids_manager_and_user(): void
    {
        $superUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'role' => 'super_user',
            'is_active' => true,
        ]);

        $superAdmin = User::factory()->create([
            'company_id' => null,
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $managerUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $staffUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        // 1. Admin berhasil menambah, mengedit, menghapus
        $resAdmin = $this->actingAs($this->adminA)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-07-10',
            'name' => 'Event Admin',
            'is_national' => false,
        ])->assertCreated();

        $holidayAdmin = PublicHoliday::withoutGlobalScopes()->findOrFail($resAdmin->json('holiday.id'));

        $this->actingAs($this->adminA)->putJson(route('settings.holidays.update', $holidayAdmin), [
            'holiday_date' => '2026-07-11',
            'name' => 'Event Admin Updated',
            'is_national' => false,
        ])->assertOk();

        // 2. Super User berhasil menambah, mengedit, menghapus
        $resSuperUser = $this->actingAs($superUser)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-07-15',
            'name' => 'EventGM / Super User',
            'is_national' => false,
        ])->assertCreated();

        $holidaySu = PublicHoliday::withoutGlobalScopes()->findOrFail($resSuperUser->json('holiday.id'));

        $this->actingAs($superUser)->putJson(route('settings.holidays.update', $holidaySu), [
            'holiday_date' => '2026-07-16',
            'name' => 'Event GM Updated',
            'is_national' => true,
        ])->assertOk();

        $this->actingAs($superUser)->deleteJson(route('settings.holidays.destroy', $holidaySu))
            ->assertOk();

        // 3. Super Admin berhasil menambah, mengedit, menghapus (dengan konteks perusahaan aktif)
        $resSuperAdmin = $this->actingAs($superAdmin)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->postJson(route('settings.holidays.store'), [
                'holiday_date' => '2026-07-20',
                'name' => 'Event Super Admin',
                'is_national' => false,
            ])->assertCreated();

        $holidaySa = PublicHoliday::withoutGlobalScopes()->findOrFail($resSuperAdmin->json('holiday.id'));

        $this->actingAs($superAdmin)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->putJson(route('settings.holidays.update', $holidaySa), [
                'holiday_date' => '2026-07-21',
                'name' => 'Event Super Admin Updated',
                'is_national' => false,
            ])->assertOk();

        $this->actingAs($superAdmin)
            ->withSession(['active_company_id' => $this->companyA->id])
            ->deleteJson(route('settings.holidays.destroy', $holidaySa))
            ->assertOk();

        // 4. Manager dan User ditolak dengan HTTP 403 Forbidden
        foreach ([$managerUser, $staffUser] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)->postJson(route('settings.holidays.store'), [
                'holiday_date' => '2026-07-25',
                'name' => 'Event Terlarang',
                'is_national' => false,
            ])->assertStatus(403);

            $this->actingAs($unauthorizedUser)->putJson(route('settings.holidays.update', $holidayAdmin), [
                'holiday_date' => '2026-07-26',
                'name' => 'Ubah Terlarang',
                'is_national' => false,
            ])->assertStatus(403);

            $this->actingAs($unauthorizedUser)->deleteJson(route('settings.holidays.destroy', $holidayAdmin))
                ->assertStatus(403);
        }

        // Terakhir Admin menghapus holidayAdmin
        $this->actingAs($this->adminA)->deleteJson(route('settings.holidays.destroy', $holidayAdmin))
            ->assertOk();
    }

    /**
     * Skenario 3: Validasi Duplikasi Tanggal.
     * Menambahkan dua hari libur pada tanggal yang sama di perusahaan yang sama ditolak dengan HTTP 422,
     * namun tanggal yang sama di perusahaan berbeda diizinkan.
     */
    public function test_scenario_3_duplicate_holiday_date_is_rejected_within_same_company_but_allowed_across_different_companies(): void
    {
        // Perusahaan A menambahkan tanggal 2026-10-20 -> Berhasil (201)
        $this->actingAs($this->adminA)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-10-20',
            'name' => 'Gala Dinner Properti A',
            'is_national' => false,
        ])->assertCreated();

        // Perusahaan A mencoba menambahkan lagi tanggal 2026-10-20 -> Ditolak (422)
        $this->actingAs($this->adminA)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-10-20',
            'name' => 'Duplikat Tanggal Properti A',
            'is_national' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['holiday_date']);

        // Perusahaan B menambahkan tanggal yang sama (2026-10-20) -> Diizinkan (201)
        $this->actingAs($this->adminB)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-10-20',
            'name' => 'Townhall Properti B',
            'is_national' => false,
        ])->assertCreated();
    }

    /**
     * Skenario 4: Integrasi Presensi pada Event Khusus Perusahaan & Rekonsiliasi Hari Berjalan.
     * Saat staf melakukan Clock-In dan Clock-Out (>= 4 jam) pada tanggal event khusus yang ditambahkan
     * oleh Admin perusahaannya, staf tersebut memperoleh is_provisional_dp = true saat Clock-In
     * dan otomatis dikomit menjadi +1.00 DP di leave_ledger_entries saat Clock-Out.
     * Penghapusan hari libur setelah komit tetap menjaga entri leave_ledger_entries (Strict Append-Only Ledger).
     */
    public function test_scenario_4_attendance_on_company_special_event_grants_provisional_dp_and_commits_to_append_only_ledger(): void
    {
        $staffUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Rina GSA',
            'username' => 'rina.gsa.holiday',
            'email' => 'rina.gsa@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id,
            'user_id' => $staffUser->id,
            'department_id' => $this->foDeptA->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $shiftMo = Shift::withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->where('code', 'MO')
            ->firstOrFail();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id,
            'employee_id' => $employee->id,
            'shift_id' => $shiftMo->id,
            'schedule_date' => '2026-11-20',
            'status' => 'published',
        ]);

        // Admin menambahkan event khusus pada 2026-11-20
        $storeRes = $this->actingAs($this->adminA)->postJson(route('settings.holidays.store'), [
            'holiday_date' => '2026-11-20',
            'name' => 'Novotel YIA Appreciation Day',
            'is_national' => false,
        ])->assertCreated();

        $holidayId = $storeRes->json('holiday.id');

        // 1. Staf Clock-In pukul 07:00 WIB pada tanggal event khusus 2026-11-20
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 7, 0, 0, 'Asia/Jakarta'));

        $clockInRes = $this->actingAs($staffUser)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('in.jpg'),
        ]);

        $clockInRes->assertOk()
            ->assertJsonPath('data.is_provisional_dp', true);

        // 2. Staf Clock-Out pukul 15:00 WIB (durasi 8.0 jam >= 4.0 jam)
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 15, 0, 0, 'Asia/Jakarta'));

        $clockOutRes = $this->actingAs($staffUser)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('out.jpg'),
        ]);

        $clockOutRes->assertOk();

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertTrue($attendance->is_provisional_dp);
        $this->assertNotNull($attendance->dp_committed_at);
        $this->assertNotNull($attendance->dp_ledger_entry_id);

        $ledgerService = app(LeaveLedgerService::class);
        $this->assertSame(1, $ledgerService->getBalance($employee, 'DP'));

        // 3. Jika Admin menghapus hari libur tersebut setelah DP dikomit, entri leave_ledger_entries tetap utuh (Strict Append-Only)
        $holiday = PublicHoliday::withoutGlobalScopes()->findOrFail($holidayId);
        $this->actingAs($this->adminA)
            ->deleteJson(route('settings.holidays.destroy', $holiday))
            ->assertOk();

        $this->assertDatabaseHas('leave_ledger_entries', [
            'id' => $attendance->dp_ledger_entry_id,
            'employee_id' => $employee->id,
            'amount' => 1,
        ]);
        $this->assertSame(1, $ledgerService->getBalance($employee, 'DP'));
    }
}
