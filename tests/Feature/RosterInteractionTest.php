<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RosterInteractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpRosterFixture(): array
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $hkDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'HK')->firstOrFail();
        $modTagA = Tag::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MOD_ELIGIBLE')->firstOrFail();

        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MO')->firstOrFail();
        $shiftNI = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'NI')->firstOrFail();

        $adminUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'HR Admin Novotel',
            'username' => 'hr_admin_novotel',
            'email' => 'hr.admin@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $managerUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'FO Manager Novotel',
            'username' => 'fo_mgr_novotel',
            'email' => 'fo.mgr@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $managerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $managerUser->id,
            'department_id' => $foDeptA->id,
            'position' => 'Front Office Manager',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
        $managerEmployee->tags()->sync([$modTagA->id]);

        $staffUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Siti Aminah',
            'username' => 'siti_aminah',
            'email' => 'siti.aminah@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $staffUser->id,
            'department_id' => $foDeptA->id,
            'manager_id' => $managerEmployee->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-03-15',
            'status' => 'active',
        ]);

        $hkUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Dedi Kurniawan',
            'username' => 'dedi_hk',
            'email' => 'dedi.hk@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $hkUser->id,
            'department_id' => $hkDeptA->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-04-01',
            'status' => 'active',
        ]);

        // Tenant B (Hotel B) untuk pengujian isolasi multi-tenant
        $hotelB = Company::create([
            'code' => 'PULLMAN-JKT',
            'name' => 'Pullman Jakarta Central Park',
            'latitude' => '-6.1774000',
            'longitude' => '106.7907000',
            'geofence_radius_meters' => 150,
            'accent_color' => '#0F766E',
            'is_active' => true,
        ]);

        $foDeptB = Department::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $userB = User::create([
            'company_id' => $hotelB->id,
            'name' => 'Staf Pullman',
            'username' => 'staf_pullman',
            'email' => 'staf@pullman-jkt.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'user_id' => $userB->id,
            'department_id' => $foDeptB->id,
            'position' => 'Receptionist',
            'join_date' => '2025-05-01',
            'status' => 'active',
        ]);

        return compact(
            'hotelA',
            'foDeptA',
            'hkDeptA',
            'modTagA',
            'shiftMO',
            'shiftNI',
            'adminUser',
            'managerUser',
            'managerEmployee',
            'staffUser',
            'staffEmployee',
            'hkEmployee',
            'hotelB',
            'employeeB'
        );
    }

    /**
     * Skenario 1: Halaman roster berhasil dimuat dengan data pegawai dan jadwal yang sesuai dengan filter bulan/departemen.
     */
    public function test_scenario_1_roster_page_loads_with_filtered_employees_and_schedules(): void
    {
        $fixture = $this->setUpRosterFixture();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['staffEmployee']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-12',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $response = $this->actingAs($fixture['adminUser'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDeptA']->id,
            ]));

        $response->assertOk()
            ->assertSee('Siti Aminah')
            ->assertSee('FO Manager Novotel')
            ->assertDontSee('Dedi Kurniawan') // Staf HK terfilter keluar saat memilih departemen FO
            ->assertSee('2026-11-12')
            ->assertSee('MO');
    }

    /**
     * Skenario 2: Satu karyawan dapat memiliki >= 2 shift pada tanggal yang sama (multi-shift) tanpa duplicate key violation,
     * dan jadwal baru otomatis berstatus 'draft'.
     */
    public function test_scenario_2_roster_assign_supports_multiple_shifts_on_same_day_with_draft_status(): void
    {
        $fixture = $this->setUpRosterFixture();

        // 2a. Buat shift pertama (MO) pada tanggal 2026-11-20
        $firstResponse = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee']->id,
                'schedule_date' => '2026-11-20',
                'shift_id' => $fixture['shiftMO']->id,
            ]);

        $firstResponse->assertOk()
            ->assertJsonPath('schedule.employee_id', $fixture['staffEmployee']->id)
            ->assertJsonPath('schedule.shift_code', 'MO')
            ->assertJsonPath('schedule.is_off', false)
            ->assertJsonPath('schedule.status', 'draft')
            ->assertJsonPath('schedule.effective_start_time', '07:00:00');

        // 2b. Tambahkan shift kedua (NI) pada karyawan & tanggal yang sama (2026-11-20)
        $secondResponse = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee']->id,
                'schedule_date' => '2026-11-20',
                'shift_id' => $fixture['shiftNI']->id,
            ]);

        $secondResponse->assertOk()
            ->assertJsonPath('schedule.shift_code', 'NI')
            ->assertJsonPath('schedule.is_off', false)
            ->assertJsonPath('schedule.status', 'draft')
            ->assertJsonPath('schedule.cross_day', true);

        $count = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $fixture['staffEmployee']->id)
            ->where('schedule_date', '2026-11-20')
            ->count();

        $this->assertSame(2, $count);
    }

    /**
     * Skenario 3: Endpoint roster.resize berhasil memutasi custom_start_time dan custom_end_time.
     */
    public function test_scenario_3_roster_resize_updates_custom_start_and_end_times(): void
    {
        $fixture = $this->setUpRosterFixture();

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['staffEmployee']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-21',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $response = $this->actingAs($fixture['managerUser'])
            ->patchJson(route('roster.resize', $schedule), [
                'custom_start_time' => '08:15:00',
                'custom_end_time' => '16:45:00',
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule.custom_start_time', '08:15:00')
            ->assertJsonPath('schedule.custom_end_time', '16:45:00')
            ->assertJsonPath('schedule.effective_start_time', '08:15:00')
            ->assertJsonPath('schedule.effective_end_time', '16:45:00')
            ->assertJsonPath('schedule.duration_hours', 8.5);

        $this->assertDatabaseHas('work_schedules', [
            'id' => $schedule->id,
            'custom_start_time' => '08:15:00',
            'custom_end_time' => '16:45:00',
        ]);

        // 3b. Resize pada shift lintas hari (Overnight / Cross-Day: NI 23:00:00 -> 07:30:00, di mana T_end <= T_start)
        $overnightSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['staffEmployee']->id,
            'shift_id' => $fixture['shiftNI']->id,
            'schedule_date' => '2026-11-22',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $overnightResponse = $this->actingAs($fixture['managerUser'])
            ->patchJson(route('roster.resize', $overnightSchedule), [
                'custom_start_time' => '23:00:00',
                'custom_end_time' => '07:30:00',
            ]);

        $overnightResponse->assertOk()
            ->assertJsonPath('schedule.custom_start_time', '23:00:00')
            ->assertJsonPath('schedule.custom_end_time', '07:30:00')
            ->assertJsonPath('schedule.effective_start_time', '23:00:00')
            ->assertJsonPath('schedule.effective_end_time', '07:30:00')
            ->assertJsonPath('schedule.cross_day', true)
            ->assertJsonPath('schedule.duration_hours', 8.5)
            ->assertJsonPath('schedule.status', 'draft');
    }

    /**
     * Skenario 4: Multi-tenancy check: penugasan shift pada pegawai milik tenant lain ditolak dengan respon HTTP 403 / 404.
     */
    public function test_scenario_4_multi_tenancy_blocks_cross_tenant_schedule_assignment(): void
    {
        $fixture = $this->setUpRosterFixture();

        $response = $this->actingAs($fixture['adminUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['employeeB']->id, // Milik Hotel B (Pullman)
                'schedule_date' => '2026-11-22',
                'shift_id' => $fixture['shiftMO']->id,
            ]);

        $this->assertContains($response->getStatusCode(), [403, 404]);

        $this->assertDatabaseMissing('work_schedules', [
            'employee_id' => $fixture['employeeB']->id,
            'schedule_date' => '2026-11-22',
        ]);
    }

    /**
     * Skenario 5: Pengguna dengan role User biasa ditolak saat melakukan mutasi jadwal via AJAX (HTTP 403 Forbidden).
     */
    public function test_scenario_5_regular_user_role_receives_403_on_roster_mutations(): void
    {
        $fixture = $this->setUpRosterFixture();

        $existingSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['staffEmployee']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-23',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 5a. Assign ditolak 403
        $assignResponse = $this->actingAs($fixture['staffUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee']->id,
                'schedule_date' => '2026-11-24',
                'shift_id' => $fixture['shiftMO']->id,
            ]);
        $assignResponse->assertForbidden();

        // 5b. Resize ditolak 403
        $resizeResponse = $this->actingAs($fixture['staffUser'])
            ->patchJson(route('roster.resize', $existingSchedule), [
                'custom_start_time' => '09:00:00',
                'custom_end_time' => '17:00:00',
            ]);
        $resizeResponse->assertForbidden();

        // 5c. Destroy ditolak 403
        $deleteResponse = $this->actingAs($fixture['staffUser'])
            ->deleteJson(route('roster.destroy', $existingSchedule));
        $deleteResponse->assertForbidden();
    }

    /**
     * Skenario 6: Snapshot waktu tersimpan saat assign sehingga perubahan master shift tidak mengubah jadwal historis yang sudah terpasang.
     */
    public function test_scenario_6_assign_snapshots_shift_hours_and_master_shift_edit_preserves_past_schedules(): void
    {
        $fixture = $this->setUpRosterFixture();

        // 6a. Tugaskan shift MO (default 07:00:00 - 15:00:00) pada tanggal 2026-11-25
        $assignBefore = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee']->id,
                'schedule_date' => '2026-11-25',
                'shift_id' => $fixture['shiftMO']->id,
                'status' => 'draft',
            ]);

        $assignBefore->assertOk()
            ->assertJsonPath('schedule.custom_start_time', '07:00:00')
            ->assertJsonPath('schedule.custom_end_time', '15:00:00')
            ->assertJsonPath('schedule.effective_start_time', '07:00:00')
            ->assertJsonPath('schedule.effective_end_time', '15:00:00');

        // 6b. Ubah konfigurasi Master Shift MO menjadi 08:30 - 16:30 melalui PUT /roster/shifts/{shift}
        $updateShiftResponse = $this->actingAs($fixture['managerUser'])
            ->putJson(route('roster.shifts.update', $fixture['shiftMO']), [
                'name' => 'Morning Shift Updated',
                'start_time' => '08:30',
                'end_time' => '16:30',
                'grace_period_minutes' => 20,
                'color_badge' => '#0284C7',
            ]);

        $updateShiftResponse->assertOk()
            ->assertJsonPath('shift.start_time', '08:30:00')
            ->assertJsonPath('shift.end_time', '16:30:00')
            ->assertJsonPath('shift.grace_period_minutes', 20)
            ->assertJsonPath('shift.color_badge', '#0284C7');

        // 6c. Pastikan jadwal tanggal 2026-11-25 yang sudah pernah diletakkan TIDAK berubah jam kerjanya (tetap 07:00 - 15:00)
        $historicalSchedule = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $fixture['staffEmployee']->id)
            ->where('schedule_date', '2026-11-25')
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
            ->firstOrFail();

        $this->assertSame('07:00:00', $historicalSchedule->getEffectiveStartTime());
        $this->assertSame('15:00:00', $historicalSchedule->getEffectiveEndTime());
        $this->assertSame(8.0, $historicalSchedule->getEffectiveDurationInHours());

        // 6d. Jadwal baru yang diletakkan setelah master shift diubah (2026-11-26) menggunakan jam baru (08:30 - 16:30)
        $assignAfter = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee']->id,
                'schedule_date' => '2026-11-26',
                'shift_id' => $fixture['shiftMO']->id,
                'status' => 'draft',
            ]);

        $assignAfter->assertOk()
            ->assertJsonPath('schedule.custom_start_time', '08:30:00')
            ->assertJsonPath('schedule.custom_end_time', '16:30:00')
            ->assertJsonPath('schedule.effective_start_time', '08:30:00')
            ->assertJsonPath('schedule.effective_end_time', '16:30:00');
    }

    /**
     * Skenario 7: Endpoint pembaruan master shift (PUT /roster/shifts/{shift}) terlindungi otorisasi role dan isolasi multi-tenant.
     */
    public function test_scenario_7_master_shift_update_endpoint_enforces_role_and_tenant_authorization(): void
    {
        $fixture = $this->setUpRosterFixture();

        // 7a. User biasa (role: user) ditolak 403 Forbidden
        $userResponse = $this->actingAs($fixture['staffUser'])
            ->putJson(route('roster.shifts.update', $fixture['shiftMO']), [
                'name' => 'Hacked Shift',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
        $userResponse->assertForbidden();

        // 7b. Shift milik tenant lain (Hotel B) ditolak 403 Forbidden
        $shiftHotelB = Shift::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelB']->id,
            'code' => 'MO',
            'name' => 'Pullman Morning',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'grace_period_minutes' => 15,
            'color_badge' => '#0F766E',
            'is_active' => true,
        ]);

        $crossTenantResponse = $this->actingAs($fixture['adminUser'])
            ->putJson(route('roster.shifts.update', $shiftHotelB), [
                'name' => 'Cross Tenant Edit',
                'start_time' => '10:00',
                'end_time' => '18:00',
            ]);
        $crossTenantResponse->assertForbidden();
    }

    /**
     * Skenario 8: Penambahan Master Shift Baru (POST /roster/shifts) berhasil tersimpan di DB
     * terikat ke company_id aktif dan mengembalikan status 201 Created.
     */
    public function test_scenario_8_create_new_master_shift_returns_201_and_binds_to_active_company(): void
    {
        $fixture = $this->setUpRosterFixture();

        // 8a. User biasa ditolak 403 Forbidden
        $forbiddenResponse = $this->actingAs($fixture['staffUser'])
            ->postJson(route('roster.shifts.store'), [
                'code' => 'NEW',
                'name' => 'Shift Baru',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'grace_period_minutes' => 15,
                'color_badge' => '#3B82F6',
            ]);
        $forbiddenResponse->assertForbidden();

        // 8b. Manager/Admin berhasil membuat shift baru dengan nilai default
        $response = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.shifts.store'), [
                'code' => 'NEW',
                'name' => 'Shift Baru',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'grace_period_minutes' => 15,
                'color_badge' => '#3B82F6',
            ]);

        $response->assertCreated()
            ->assertJsonPath('shift.code', 'NEW')
            ->assertJsonPath('shift.name', 'Shift Baru')
            ->assertJsonPath('shift.start_time', '09:00:00')
            ->assertJsonPath('shift.end_time', '17:00:00')
            ->assertJsonPath('shift.grace_period_minutes', 15)
            ->assertJsonPath('shift.color_badge', '#3B82F6')
            ->assertJsonPath('shift.cross_day', false);

        $this->assertDatabaseHas('shifts', [
            'company_id' => $fixture['hotelA']->id,
            'code' => 'NEW',
            'name' => 'Shift Baru',
            'grace_period_minutes' => 15,
            'color_badge' => '#3B82F6',
            'is_active' => true,
        ]);
    }
}
