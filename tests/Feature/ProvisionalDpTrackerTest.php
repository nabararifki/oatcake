<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProvisionalDpTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Employee $manager;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            PublicHolidaySeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $managerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Arif Nugroho',
            'username' => 'arif.fo',
            'email' => 'arif.fo@novotelyia.com',
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
            'username' => 'budi.fo',
            'email' => 'budi.fo@novotelyia.com',
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Karyawan melakukan clock-in reguler pada tanggal merah nasional (company_id IS NULL),
     * sistem otomatis menetapkan is_provisional_dp = true.
     */
    public function test_scenario_1_regular_clock_in_on_national_public_holiday_sets_provisional_dp_true(): void
    {
        // 17 Agustus 2026: Hari Kemerdekaan Republik Indonesia (disuntikkan oleh PublicHolidaySeeder, company_id = null)
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 6, 55, 0, 'Asia/Jakarta'));

        $this->assertTrue(PublicHoliday::isHoliday('2026-08-17', $this->company->id));

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-08-17',
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'on_time',
                    'is_provisional_dp' => true,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertTrue($attendance->is_provisional_dp);
        $this->assertFalse($attendance->is_outside_geofence);
    }

    /**
     * Skenario 2: Karyawan melakukan clock-in pada hari kerja reguler (bukan tanggal merah),
     * sistem menetapkan is_provisional_dp = false.
     */
    public function test_scenario_2_regular_clock_in_on_normal_workday_sets_provisional_dp_false(): void
    {
        // 4 November 2026: Hari kerja biasa (Rabu)
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 6, 55, 0, 'Asia/Jakarta'));

        $this->assertFalse(PublicHoliday::isHoliday('2026-11-04', $this->company->id));

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_provisional_dp' => false,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertFalse($attendance->is_provisional_dp);
    }

    /**
     * Skenario 3: Karyawan melakukan clock-in pada hari libur lokal khusus perusahaannya (company_id = $company->id),
     * sistem menetapkan is_provisional_dp = true.
     */
    public function test_scenario_3_regular_clock_in_on_company_specific_local_holiday_sets_provisional_dp_true(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 6, 58, 0, 'Asia/Jakarta'));

        PublicHoliday::create([
            'company_id' => $this->company->id,
            'holiday_date' => '2026-10-15',
            'name' => 'Hari Jadi Kabupaten Kulon Progo',
            'is_recurring' => true,
        ]);

        $this->assertTrue(PublicHoliday::isHoliday('2026-10-15', $this->company->id));
        $this->assertFalse(PublicHoliday::isHoliday('2026-10-15', 999999));

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_provisional_dp' => true,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertTrue($attendance->is_provisional_dp);
    }

    /**
     * Skenario 4: Karyawan mengajukan presensi dinas luar (outside duty) pada hari libur nasional,
     * sistem menetapkan is_provisional_dp = true berdampingan dengan status pending_verification.
     */
    public function test_scenario_4_outside_duty_clock_in_on_public_holiday_sets_provisional_dp_true_and_pending_verification(): void
    {
        // 25 Desember 2026: Hari Raya Natal
        Carbon::setTestNow(Carbon::create(2026, 12, 25, 8, 0, 0, 'Asia/Jakarta'));

        $selfie = UploadedFile::fake()->image('natal_pickup.jpg', 800, 600);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9001200,
            'longitude' => 110.0598500,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Bandara YIA Terminal Kedatangan',
            'notes' => 'Penjemputan tamu liburan Natal pagi.',
            'photo' => $selfie,
        ]);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'status' => 'pending_verification',
                'data' => [
                    'status' => 'pending_verification',
                    'is_outside_geofence' => true,
                    'is_provisional_dp' => true,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertTrue($attendance->is_provisional_dp);
        $this->assertTrue($attendance->is_outside_geofence);
        $this->assertSame('pending_verification', $attendance->status);
    }

    /**
     * Skenario 5: Uji Integritas Buku Besar Cuti — Buktikan bahwa pembuatan presensi dengan
     * is_provisional_dp = true sama sekali TIDAK membuat rekod baru pada tabel saldo/mutasi cuti.
     */
    public function test_scenario_5_strict_leave_ledger_guard_prevents_premature_dp_balance_mutation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 7, 0, 0, 'Asia/Jakarta'));

        DB::enableQueryLog();

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_provisional_dp', true);

        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        // Pastikan tidak ada query INSERT/UPDATE ke tabel leave_ledger_entries atau saldo cuti
        foreach ($queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('leave_ledger', $sql);
            $this->assertStringNotContainsStringIgnoringCase('dp_balance', $sql);
        }

        if (Schema::hasTable('leave_ledger_entries')) {
            $this->assertDatabaseCount('leave_ledger_entries', 0);
        }

        // Pastikan profil Employee tidak mengalami perubahan atribut saldo tersembunyi
        $freshEmployee = Employee::withoutGlobalScopes()->findOrFail($this->employee->id);
        $this->assertNull($freshEmployee->getAttribute('dp_balance'));
    }

    /**
     * Skenario 6: Respon view atau JSON riwayat presensi merender informasi flag is_provisional_dp dengan benar.
     */
    public function test_scenario_6_employee_history_and_manager_verification_views_render_provisional_dp_badge(): void
    {
        // Set waktu pada Hari Kemerdekaan RI (2026-08-17)
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 9, 0, 0, 'Asia/Jakarta'));

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'clock_in_at' => Carbon::create(2026, 8, 17, 7, 0, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.9001200,
            'clock_in_longitude' => 110.0598500,
            'clock_in_distance_meters' => 1500,
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
            'is_provisional_dp' => true,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Bandara YIA',
            'proof_photo_path' => "attendance_proofs/{$this->company->id}/2026-08/proof.webp",
            'notes' => 'Tugas penjemputan hari kemerdekaan.',
        ]);

        // 1. Halaman presensi karyawan merender banner Hari Libur Nasional & badge Provisional DP pada riwayat
        $employeeView = $this->actingAs($this->employee->user)->get(route('attendance.index'));
        $employeeView->assertOk()
            ->assertSee('Hari Kemerdekaan Republik Indonesia')
            ->assertSee(__('attendance.badge_provisional_dp'))
            ->assertSee(__('attendance.tooltip_provisional_dp'));

        // 2. Halaman konsol verifikasi manajer merender badge Provisional DP pada antrean verifikasi
        $managerView = $this->actingAs($this->manager->user)->get(route('attendance.verifications.index'));
        $managerView->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee(__('attendance.badge_provisional_dp'));
    }
}
