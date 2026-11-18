<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutoAssignNearestShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Shift $shiftMo;
    protected Shift $shiftAf;
    protected Shift $shiftNi;
    protected Shift $shiftOff;

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

        // Pastikan shift perusahaan selaras dengan skenario pengujian:
        // MO (07:00–15:00), AF (15:00–23:00), NI (23:00–07:00), OFF
        $this->shiftMo = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $this->shiftAf = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'AF')
            ->firstOrFail();

        $this->shiftNi = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'NI')
            ->firstOrFail();

        $this->shiftOff = Shift::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $this->company->id,
                'code' => 'OFF',
            ],
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

        // Nonaktifkan shift tambahan (MD / GO / BO) agar kandidat murni MO, AF, NI, MOD, OFF
        Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('code', ['MD', 'GO', 'BO'])
            ->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createEmployee(string $name, string $username): Employee
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'username' => $username,
            'email' => $username . '@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
    }

    /**
     * Skenario 1: Auto-Assign saat Tidak Ada Jadwal.
     * Karyawan yang tidak memiliki jadwal kerja pada hari ini melakukan Clock-In pukul 07:10 WIB
     * (di mana perusahaan memiliki shift MO 07:00–15:00, AF 15:00–23:00, NI 23:00–07:00).
     * Sistem otomatis membuat WorkSchedule berstatus published dengan shift MO,
     * mengikat work_schedule_id pada Attendance, dan mencatat late_minutes = 10 (status = 'on_time' dalam toleransi 15 menit).
     */
    public function test_scenario_1_unscheduled_clock_in_at_0710_auto_assigns_mo_shift_and_records_on_time_with_10_late_minutes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 7, 10, 0, 'Asia/Jakarta'));

        $employee = $this->createEmployee('Andi Pratama', 'andi.auto.mo');

        $this->assertDatabaseMissing('work_schedules', [
            'employee_id' => $employee->id,
            'schedule_date' => '2026-11-12',
        ]);

        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_0710.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'on_time')
            ->assertJsonPath('data.late_minutes', 10);

        $schedule = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', '2026-11-12')
            ->first();

        $this->assertNotNull($schedule);
        $this->assertSame($this->shiftMo->id, $schedule->shift_id);
        $this->assertSame('published', $schedule->status);
        $this->assertSame('Auto-assigned on clock-in', $schedule->notes);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame($schedule->id, $attendance->work_schedule_id);
        $this->assertSame('on_time', $attendance->status);
        $this->assertSame(10, $attendance->late_minutes);
    }

    /**
     * Skenario 2: Auto-Assign Memilih Shift Sore/Malam Terdekat (Termasuk Lintas Tengah Malam).
     * Karyawan tanpa jadwal yang melakukan Clock-In pukul 14:50 WIB otomatis dipilihkan shift AF (15:00–23:00);
     * karyawan yang melakukan Clock-In pukul 23:05 WIB otomatis dipilihkan shift NI (23:00–07:00).
     */
    public function test_scenario_2_auto_assign_selects_nearest_afternoon_and_night_shifts_including_cross_midnight(): void
    {
        $empAfternoon = $this->createEmployee('Bima Sore', 'bima.auto.af');
        $empNight = $this->createEmployee('Citra Malam', 'citra.auto.ni');

        // 1. Clock-In pukul 14:50 WIB -> terdekat ke AF (15:00–23:00, jarak 10 menit)
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 14, 50, 0, 'Asia/Jakarta'));

        $resAf = $this->actingAs($empAfternoon->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_af.jpg'),
        ]);

        $resAf->assertOk()
            ->assertJsonPath('data.status', 'on_time');

        $schedAf = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $empAfternoon->id)
            ->whereDate('schedule_date', '2026-11-12')
            ->firstOrFail();

        $this->assertSame($this->shiftAf->id, $schedAf->shift_id);
        $this->assertSame('published', $schedAf->status);
        $this->assertSame('Auto-assigned on clock-in', $schedAf->notes);

        // 2. Clock-In pukul 23:05 WIB -> terdekat ke NI (23:00–07:00, jarak 5 menit)
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 23, 5, 0, 'Asia/Jakarta'));

        $resNi = $this->actingAs($empNight->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_ni.jpg'),
        ]);

        $resNi->assertOk()
            ->assertJsonPath('data.status', 'on_time')
            ->assertJsonPath('data.late_minutes', 5);

        $schedNi = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $empNight->id)
            ->whereDate('schedule_date', '2026-11-12')
            ->firstOrFail();

        $this->assertSame($this->shiftNi->id, $schedNi->shift_id);
        $this->assertSame('published', $schedNi->status);
        $this->assertSame('Auto-assigned on clock-in', $schedNi->notes);
    }

    /**
     * Skenario 3: Auto-Assign Mengonversi Jadwal OFF Reguler.
     * Karyawan yang awalnya dijadwalkan OFF reguler (bukan cuti approved) dan masuk bekerja pada hari tersebut
     * otomatis diperbarui jadwalnya menjadi shift terdekat.
     */
    public function test_scenario_3_auto_assign_converts_regular_off_schedule_into_nearest_shift(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 15, 0, 0, 'Asia/Jakarta'));

        $employee = $this->createEmployee('Dedi Off', 'dedi.regular.off');

        $offSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftOff->id,
            'schedule_date' => '2026-11-12',
            'status' => 'published',
            'notes' => null,
        ]);

        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_off_convert.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_schedule_id', $offSchedule->id);

        $offSchedule->refresh();
        $this->assertSame($this->shiftAf->id, $offSchedule->shift_id);
        $this->assertSame('published', $offSchedule->status);
        $this->assertSame('Auto-assigned on clock-in', $offSchedule->notes);

        // Pastikan tidak membuat baris duplikat melainkan memperbarui rekod OFF yang ada
        $this->assertSame(
            1,
            WorkSchedule::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->whereDate('schedule_date', '2026-11-12')
                ->count()
        );
    }

    /**
     * Skenario 4: Proteksi Cuti Approved.
     * Karyawan yang sedang memiliki cuti berstatus approved (LEAVE:AL) pada hari tersebut
     * tidak akan tertimpa jadwal cutinya.
     */
    public function test_scenario_4_approved_leave_schedule_is_protected_and_not_overwritten_on_clock_in(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 7, 5, 0, 'Asia/Jakarta'));

        $employee = $this->createEmployee('Eka Cuti', 'eka.leave.locked');

        $leaveTypeAl = LeaveType::withoutGlobalScopes()
            ->where('code', 'AL')
            ->firstOrFail();

        $leaveRequest = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveTypeAl->id,
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-12',
            'total_days' => 1.0,
            'reason' => 'Cuti Tahunan Keluarga',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $lockedSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftOff->id,
            'schedule_date' => '2026-11-12',
            'status' => 'published',
            'notes' => "LEAVE:AL:{$leaveRequest->id}:Cuti Tahunan Keluarga",
        ]);

        $response = $this->actingAs($employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie_on_leave.jpg'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        // Pastikan jadwal cuti tetap terkunci pada shift OFF dengan notes LEAVE:AL dan tidak tertimpa!
        $lockedSchedule->refresh();
        $this->assertSame($this->shiftOff->id, $lockedSchedule->shift_id);
        $this->assertStringStartsWith('LEAVE:AL:', (string) $lockedSchedule->notes);
    }

    /**
     * Skenario 5: Kebijakan Perusahaan `auto_assign_shift_on_clock_in = false`.
     * - Karyawan tanpa jadwal aktif (tidak ada jadwal maupun jadwal OFF) ditolak HTTP 422 pada Clock-In reguler & dinas luar.
     * - Tidak ada WorkSchedule baru maupun Attendance yang tercipta/tertimpa.
     * - Dashboard menampilkan status kunci kebijakan dan menonaktifkan tombol Clock-In.
     * - Karyawan yang MEMILIKI jadwal shift kerja aktif tetap dapat melakukan Clock-In secara normal.
     */
    public function test_scenario_5_disabled_auto_assign_shift_blocks_unscheduled_clock_in_and_allows_scheduled(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 8, 5, 0, 'Asia/Jakarta'));

        $this->company->update(['auto_assign_shift_on_clock_in' => false]);

        $unscheduledEmp = $this->createEmployee('Bambang Unscheduled', 'bambang.unsched');
        $offEmp = $this->createEmployee('Siti DayOff', 'siti.dayoff');
        $scheduledEmp = $this->createEmployee('Andi Scheduled', 'andi.sched');

        $offSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $offEmp->id,
            'shift_id' => $this->shiftOff->id,
            'schedule_date' => '2026-11-12',
            'status' => 'published',
        ]);

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $scheduledEmp->id,
            'shift_id' => $this->shiftMo->id,
            'schedule_date' => '2026-11-12',
            'status' => 'published',
        ]);

        // 1. Dashboard karyawan tanpa jadwal menampilkan notice kebijakan & tombol clock-in terkunci
        $this->actingAs($unscheduledEmp->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('isClockInBlockedByUnscheduledPolicy: true', false)
            ->assertSee(__('attendance.unscheduled_clock_in_policy_notice'));

        // 2. Clock-In reguler tanpa jadwal ditolak HTTP 422
        $this->actingAs($unscheduledEmp->user)
            ->postJson(route('attendance.clockIn'), [
                'latitude' => -7.9043753,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('unscheduled_in.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('attendance.unscheduled_clock_in_disabled'));

        // 3. Clock-In dinas luar tanpa jadwal juga ditolak HTTP 422
        $this->actingAs($unscheduledEmp->user)
            ->postJson(route('attendance.clockInOutside'), [
                'latitude' => -7.795580,
                'longitude' => 110.369490,
                'outside_category' => 'client_visit',
                'outside_location_name' => 'Kantor Klien',
                'notes' => 'Kunjungan mendadak tanpa jadwal.',
                'photo' => UploadedFile::fake()->image('unscheduled_outside.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('attendance.unscheduled_clock_in_disabled'));

        // Pastikan tidak ada jadwal maupun presensi yang dibuat
        $this->assertSame(0, WorkSchedule::withoutGlobalScopes()->where('employee_id', $unscheduledEmp->id)->count());
        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('employee_id', $unscheduledEmp->id)->count());

        // 4. Karyawan dengan jadwal OFF saja juga ditolak HTTP 422 & jadwal OFF tidak tertimpa
        $this->actingAs($offEmp->user)
            ->postJson(route('attendance.clockIn'), [
                'latitude' => -7.9043753,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('off_emp_in.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('attendance.unscheduled_clock_in_disabled'));

        $this->assertSame($this->shiftOff->id, $offSchedule->fresh()->shift_id);

        // 5. Karyawan YANG MEMILIKI jadwal shift kerja aktif tetap dapat Clock-In normal (HTTP 200 OK)
        $this->actingAs($scheduledEmp->user)
            ->postJson(route('attendance.clockIn'), [
                'latitude' => -7.9043753,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('scheduled_in.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
