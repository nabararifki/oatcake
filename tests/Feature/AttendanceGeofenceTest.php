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
use App\Services\AttendanceGeofenceService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceGeofenceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Employee $employee;
    protected Employee $peerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $user1 = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.fo',
            'email' => 'budi.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user1->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $user2 = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Siti Rahma',
            'username' => 'siti.fo',
            'email' => 'siti.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->peerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user2->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-02-01',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: AttendanceGeofenceService mengkalkulasi jarak Haversine dengan presisi
     * terhadap koordinat Novotel YIA (-7.9048250, 110.0572110).
     */
    public function test_scenario_1_haversine_service_calculates_distance_accurately_against_novotel_yia(): void
    {
        $service = app(AttendanceGeofenceService::class);

        $novotelLat = -7.9048250;
        $novotelLon = 110.0572110;

        // Titik identik (0 meter)
        $zeroDist = $service->calculateDistance($novotelLat, $novotelLon, $novotelLat, $novotelLon);
        $this->assertSame(0.0, $zeroDist);

        // Titik berjarak ~50 meter ke utara (delta lat = 0.00044966 derajat)
        $lat50m = -7.9043753;
        $dist50m = $service->calculateDistance($novotelLat, $novotelLon, $lat50m, $novotelLon);
        $this->assertEqualsWithDelta(50.0, $dist50m, 0.5);

        $geofenceInside = $service->validateGeofence($this->company, $lat50m, $novotelLon);
        $this->assertTrue($geofenceInside['is_inside']);
        $this->assertSame(50, $geofenceInside['distance_meters']);
        $this->assertSame(100, $geofenceInside['radius_limit']);

        // Titik berjarak ~350 meter ke utara (delta lat = 0.0031476 derajat)
        $lat350m = -7.9016774;
        $dist350m = $service->calculateDistance($novotelLat, $novotelLon, $lat350m, $novotelLon);
        $this->assertEqualsWithDelta(350.0, $dist350m, 0.5);

        $geofenceOutside = $service->validateGeofence($this->company, $lat350m, $novotelLon);
        $this->assertFalse($geofenceOutside['is_inside']);
        $this->assertSame(350, $geofenceOutside['distance_meters']);
        $this->assertSame(100, $geofenceOutside['radius_limit']);

        // Verifikasi juga model PublicHoliday & scopes (upcoming, forYear)
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 8, 0, 0, 'Asia/Jakarta'));

        PublicHoliday::create([
            'company_id' => null,
            'holiday_date' => '2026-08-17',
            'name' => 'Hari Kemerdekaan RI',
            'is_recurring' => true,
        ]);

        PublicHoliday::create([
            'company_id' => $this->company->id,
            'holiday_date' => '2026-12-25',
            'name' => 'Hari Raya Natal',
            'is_recurring' => true,
        ]);

        $this->assertCount(1, PublicHoliday::upcoming()->get());
        $this->assertCount(2, PublicHoliday::forYear(2026)->get());
    }

    /**
     * Skenario 2: Karyawan clock-in pada koordinat jarak 50 meter (<= 100m)
     * berhasil disimpan dengan status on_time.
     */
    public function test_scenario_2_employee_clock_in_at_50_meters_succeeds_with_on_time_status(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 6, 55, 0, 'Asia/Jakarta'));

        $employee = Employee::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-11-04',
            'status' => 'published',
        ]);

        // Pastikan halaman antarmuka presensi juga dapat diakses dengan lancar
        $pageResponse = $this->actingAs($employee->user)->get(route('attendance.index'));
        $pageResponse->assertOk()
            ->assertSee($morningShift->name)
            ->assertSee('07:00');

        // Koordinat berjarak ~50m dari titik Novotel YIA (-7.9048250, 110.0572110)
        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'distance' => 50,
                'is_outside' => false,
                'data' => [
                    'status' => 'on_time',
                    'late_minutes' => 0,
                    'clock_in_distance_meters' => 50,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertSame($schedule->id, $attendance->work_schedule_id);
        $this->assertSame('on_time', $attendance->status);
        $this->assertTrue($attendance->isOnTime());
        $this->assertFalse($attendance->isLate());
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(50, $attendance->clock_in_distance_meters);
        $this->assertFalse($attendance->is_outside_geofence);
    }

    /**
     * Skenario 3: Karyawan clock-in reguler pada koordinat jarak 350 meter (> 100m)
     * ditolak dengan status HTTP 422 dan pesan error geofence.
     */
    public function test_scenario_3_employee_clock_in_at_350_meters_is_rejected_with_422_geofence_error(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 7, 0, 0, 'Asia/Jakarta'));

        $employee = Employee::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        // Koordinat berjarak ~350m dari titik Novotel YIA (> batas 100m)
        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'distance' => 350,
                'is_outside' => true,
            ]);

        $this->assertStringContainsString('350', $response->json('message'));
        $this->assertStringContainsString('100', $response->json('message'));

        $this->assertDatabaseCount('attendances', 0);
    }

    /**
     * Skenario 4: Karyawan clock-in melewati batas toleransi shift
     * (misal 07:20 pada shift 07:00 toleransi 15 menit) tercatat dengan status late dan late_minutes = 20.
     */
    public function test_scenario_4_employee_clock_in_after_grace_period_records_late_status_and_20_late_minutes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 7, 20, 0, 'Asia/Jakarta'));

        $employee = Employee::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        // Shift MO: 07:00:00 - 15:00:00, grace_period_minutes = 15 (threshold 07:15:00)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-11-04',
            'status' => 'published',
        ]);

        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'late',
                    'late_minutes' => 20,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame('late', $attendance->status);
        $this->assertTrue($attendance->isLate());
        $this->assertFalse($attendance->isOnTime());
        $this->assertSame(20, $attendance->late_minutes);
    }

    /**
     * Skenario 5: Karyawan berhasil melakukan clock-out dalam radius dan tercatat clock_out_at serta durasi kerja.
     */
    public function test_scenario_5_employee_clock_out_within_radius_records_clock_out_at_and_work_duration(): void
    {
        $employee = Employee::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-11-04',
            'status' => 'published',
        ]);

        // 1. Clock-In pada pukul 07:00 WIB (jarak 50m)
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 7, 0, 0, 'Asia/Jakarta'));

        $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_in.jpg'),
        ])->assertOk();

        // 2. Clock-Out pada pukul 15:00 WIB (jarak 30m)
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 15, 0, 0, 'Asia/Jakarta'));

        $clockOutResponse = $this->actingAs($employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9045552,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_out.jpg'),
        ]);

        $clockOutResponse->assertOk()
            ->assertJson([
                'success' => true,
                'is_outside' => false,
                'data' => [
                    'early_leave_minutes' => 0,
                    'work_duration_hours' => 8.0,
                ],
            ]);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertNotNull($attendance->clock_out_at);
        $this->assertSame('15:00:00', $attendance->clock_out_at->timezone('Asia/Jakarta')->format('H:i:s'));
        $this->assertSame(30, $attendance->clock_out_distance_meters);
        $this->assertSame(0, $attendance->early_leave_minutes);
        $this->assertSame(8.0, $attendance->getWorkDurationInHours());
    }

    /**
     * Skenario 6: Pengguna tidak dapat melakukan presensi atas nama staf lain.
     */
    public function test_scenario_6_user_cannot_clock_in_or_clock_out_on_behalf_of_another_employee(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 7, 0, 0, 'Asia/Jakarta'));

        $actorEmployee = $this->employee;
        $targetEmployee = $this->peerEmployee;

        // Percobaan 1: Mengirim employee_id milik staf lain saat Clock-In
        $spoofByIdResponse = $this->actingAs($actorEmployee->user)->postJson(route('attendance.clockIn'), [
            'employee_id' => $targetEmployee->id,
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
        ]);

        $spoofByIdResponse->assertStatus(403)
            ->assertJson(['success' => false]);

        // Percobaan 2: Mengirim employee_uuid milik staf lain saat Clock-In
        $spoofByUuidResponse = $this->actingAs($actorEmployee->user)->postJson(route('attendance.clockIn'), [
            'employee_uuid' => $targetEmployee->uuid,
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
        ]);

        $spoofByUuidResponse->assertStatus(403)
            ->assertJson(['success' => false]);

        // Percobaan 3: Mengirim user_id milik staf lain saat Clock-Out
        $spoofClockOutResponse = $this->actingAs($actorEmployee->user)->postJson(route('attendance.clockOut'), [
            'user_id' => $targetEmployee->user_id,
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
        ]);

        $spoofClockOutResponse->assertStatus(403)
            ->assertJson(['success' => false]);

        // Pastikan tidak ada presensi yang tercatat untuk targetEmployee
        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $targetEmployee->id,
        ]);
    }

    /**
     * Skenario 7: Halaman /attendance berfungsi sebagai Riwayat & Log Audit Terpusat dengan RBAC berjenjang
     * (User: pribadi, Manager: departemennya, Admin: seluruh perusahaan) beserta filter tanggal, departemen, status, dan pencarian staf.
     */
    public function test_scenario_7_attendance_audit_log_enforces_hierarchical_rbac_scoping_and_filters(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 10, 0, 0, 'Asia/Jakarta'));

        $hkDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'HK')
            ->firstOrFail();

        $foManagerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra.fo.mgr',
            'email' => 'hendra.fo.mgr@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $foManagerUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-05-01',
            'status' => 'active',
        ]);

        $hkStaffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rizky HK Staff',
            'username' => 'rizky.hk.log',
            'email' => 'rizky.hk.log@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $hkStaffUser->id,
            'department_id' => $hkDept->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-03-01',
            'status' => 'active',
        ]);

        $adminUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Diana HR Admin',
            'username' => 'diana.hr.log',
            'email' => 'diana.hr.log@novotelyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Presensi 1: Budi Santoso (FO, on_time, 2026-11-04 06:58)
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 6, 58, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.9043753,
            'clock_in_longitude' => 110.0572110,
            'clock_in_distance_meters' => 45,
            'status' => 'on_time',
            'late_minutes' => 0,
            'is_outside_geofence' => false,
        ]);

        // Presensi 2: Siti Rahma (FO, late, 2026-11-04 07:25)
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->peerEmployee->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 7, 25, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.9043753,
            'clock_in_longitude' => 110.0572110,
            'clock_in_distance_meters' => 52,
            'status' => 'late',
            'late_minutes' => 25,
            'is_outside_geofence' => false,
        ]);

        // Presensi 3: Rizky HK Staff (HK, on_time, 2026-11-03 06:50)
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $hkEmployee->id,
            'clock_in_at' => Carbon::create(2026, 11, 3, 6, 50, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.9043753,
            'clock_in_longitude' => 110.0572110,
            'clock_in_distance_meters' => 30,
            'status' => 'on_time',
            'late_minutes' => 0,
            'is_outside_geofence' => false,
        ]);

        // 1. Tingkat Karyawan Biasa (Budi Santoso - FO): hanya melihat presensi miliknya sendiri (06:58:00),
        //    tidak melihat Siti Rahma (07:25:00) maupun Rizky HK Staff, dan tanpa filter departemen/pencarian staf.
        $userRes = $this->actingAs($this->employee->user)->get(route('attendance.index'));
        $userRes->assertOk()
            ->assertSee('06:58:00')
            ->assertDontSee('07:25:00')
            ->assertDontSee('06:50:00')
            ->assertDontSee('name="department_id"', escape: false)
            ->assertDontSee('name="search"', escape: false);

        // 2. Tingkat Manajer FO: melihat seluruh staf departemen FO (Budi Santoso & Siti Rahma),
        //    tetapi TIDAK melihat staf departemen HK (Rizky HK Staff) walaupun mengirim ?department_id=HK.
        $managerRes = $this->actingAs($foManagerUser)->get(route('attendance.index', [
            'department_id' => $hkDept->id,
        ]));
        $managerRes->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Siti Rahma')
            ->assertDontSee('Rizky HK Staff')
            ->assertSee('name="search"', escape: false);

        // 3. Tingkat HR Admin: melihat lintas departemen (FO & HK), serta dapat menyaring per departemen, status, dan pencarian nama.
        $adminAllRes = $this->actingAs($adminUser)->get(route('attendance.index'));
        $adminAllRes->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Siti Rahma')
            ->assertSee('Rizky HK Staff')
            ->assertSee('name="department_id"', escape: false);

        $adminFilteredRes = $this->actingAs($adminUser)->get(route('attendance.index', [
            'department_id' => $this->foDept->id,
            'status' => 'late',
            'search' => 'Siti',
        ]));
        $adminFilteredRes->assertOk()
            ->assertSee('Siti Rahma')
            ->assertDontSee('Budi Santoso')
            ->assertDontSee('Rizky HK Staff');
    }

    /**
     * Skenario 8 (Skenario 5 modul admin): Badge MOD pada modal jadwal dashboard hanya muncul
     * pada staf yang shift hari ini berkode 'MOD', bukan staf bertag MOD_ELIGIBLE di shift reguler.
     */
    public function test_scenario_8_mod_badge_only_rendered_for_staff_on_mod_shift_today(): void
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $modShift = Shift::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MOD')->firstOrFail();
        $moShift = Shift::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MO')->firstOrFail();
        $modTag = \App\Models\Tag::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MOD_ELIGIBLE')->firstOrFail();

        // Budi: berkualifikasi MOD tetapi bertugas di shift MO reguler.
        $this->employee->tags()->sync([$modTag->id]);
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $moShift->id,
            'duty_tag_id' => $modTag->id,
            'schedule_date' => $today,
            'status' => 'published',
        ]);

        // Siti: bertugas di shift MOD.
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->peerEmployee->id,
            'shift_id' => $modShift->id,
            'schedule_date' => $today,
            'status' => 'published',
        ]);

        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $badgeClass = 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20';
        $this->assertSame(1, substr_count($html, $badgeClass));
        $this->assertMatchesRegularExpression('/Siti Rahma<\/span>\s*<span class="[^"]*border-amber-500\/20/', $html);
        $this->assertDoesNotMatchRegularExpression('/Budi Santoso<\/span>\s*<span class="[^"]*border-amber-500\/20/', $html);
    }

    /**
     * Skenario 9: Widget dashboard merender modal konfirmasi early clock-out beserta jam selesai shift.
     */
    public function test_scenario_9_dashboard_renders_early_clockout_guard_with_shift_end_time(): void
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $moShift = Shift::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MO')->firstOrFail();

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $moShift->id,
            'schedule_date' => $today,
            'status' => 'published',
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_schedule_id' => $schedule->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta')->subMinute(),
            'status' => 'on_time',
            'late_minutes' => 0,
            'is_outside_geofence' => false,
        ]);

        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('early-clockout-confirm-modal', false)
            ->assertSee(__('attendance.modal_early_clockout_title'))
            ->assertSee('15:00 WIB')
            ->assertSee('active:scale-[0.99]', false);
    }
}

