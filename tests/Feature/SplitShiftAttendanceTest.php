<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SplitShiftAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $fbDept;
    protected Employee $employee;
    protected Shift $splitShift;

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
        $this->fbDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FBS')
            ->first() ?? Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Andi Pramusaji',
            'username' => 'andi.split',
            'email' => 'andi.split@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'department_id' => $this->fbDept->id,
            'position' => 'F&B Attendant',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $this->splitShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_employee_can_complete_two_split_shift_sessions_on_same_day_with_independent_lateness_and_dashboard_widget_state(): void
    {
        $dateStr = '2026-11-05';

        // Sesi 1: Pagi 07:00 - 11:00 (toleransi 15 menit -> threshold 07:15)
        $session1 = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $this->splitShift->id,
            'schedule_date' => $dateStr,
            'custom_start_time' => '07:00:00',
            'custom_end_time' => '11:00:00',
            'status' => 'published',
        ]);

        // Sesi 2: Sore 15:00 - 19:00 (toleransi 15 menit -> threshold 15:15)
        $session2 = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $this->splitShift->id,
            'schedule_date' => $dateStr,
            'custom_start_time' => '15:00:00',
            'custom_end_time' => '19:00:00',
            'status' => 'published',
        ]);

        // 1. Clock-In Sesi 1 pada pukul 07:05 WIB (on_time, late_minutes = 0)
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 7, 5, 0, 'Asia/Jakarta'));

        $clockIn1 = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie1_in.jpg'),
        ]);

        $clockIn1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_schedule_id', $session1->id)
            ->assertJsonPath('data.status', 'on_time')
            ->assertJsonPath('data.late_minutes', 0);

        // 2. Clock-Out Sesi 1 pada pukul 11:00 WIB (early_leave_minutes = 0)
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 11, 0, 0, 'Asia/Jakarta'));

        $clockOut1 = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie1_out.jpg'),
        ]);

        $clockOut1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_schedule_id', $session1->id)
            ->assertJsonPath('data.early_leave_minutes', 0);

        // 3. Periksa tampilan widget Dashboard setelah Sesi 1 selesai dan Sesi 2 menunggu
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 14, 45, 0, 'Asia/Jakarta'));

        $dashboardWaiting = $this->actingAs($this->employee->user)->get(route('dashboard'));
        $dashboardWaiting->assertOk()
            ->assertSee('Sesi 1 Selesai. Menunggu Jam Masuk Sesi 2 (15:00 WIB)')
            ->assertSee(__('dashboard.clock_in_now'));

        // 4. Clock-In Sesi 2 pada pukul 15:25 WIB (melewati toleransi 15 menit dari 15:00 -> late, late_minutes = 25)
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 15, 25, 0, 'Asia/Jakarta'));

        $clockIn2 = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie2_in.jpg'),
        ]);

        $clockIn2->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_schedule_id', $session2->id)
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 25);

        // 5. Clock-Out Sesi 2 pada pukul 18:40 WIB (20 menit lebih awal dari 19:00 -> early_leave_minutes = 20)
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 18, 40, 0, 'Asia/Jakarta'));

        $clockOut2 = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9043753,
            'longitude' => 110.0572110,
            'photo' => UploadedFile::fake()->image('selfie2_out.jpg'),
        ]);

        $clockOut2->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_schedule_id', $session2->id)
            ->assertJsonPath('data.late_minutes', 25)
            ->assertJsonPath('data.early_leave_minutes', 20);

        // Pastikan 2 rekod presensi terpisah tersimpan di DB untuk masing-masing sesi
        $attendances = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $attendances);
        $this->assertSame($session1->id, $attendances[0]->work_schedule_id);
        $this->assertSame(0, $attendances[0]->late_minutes);
        $this->assertSame(0, $attendances[0]->early_leave_minutes);

        $this->assertSame($session2->id, $attendances[1]->work_schedule_id);
        $this->assertSame(25, $attendances[1]->late_minutes);
        $this->assertSame(20, $attendances[1]->early_leave_minutes);
    }

    public function test_mobile_roster_renders_floating_bottom_action_sheet_and_bottom_padding(): void
    {
        $adminUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Diana HR Admin',
            'username' => 'diana.roster.sheet',
            'email' => 'diana.sheet@novotelyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($adminUser)->get(route('roster.index', [
            'month' => 11,
            'year' => 2026,
        ]));

        $response->assertOk()
            ->assertSee('x-show="selectedSchedule"', escape: false)
            ->assertSee('selectedSchedule = schedule', escape: false)
            ->assertSee('@click="selectedSchedule = null"', escape: false)
            ->assertSee('x-if="expandedDate === schedule.schedule_date"', escape: false)
            ->assertSee('expandedDate === selectedSchedule.schedule_date', escape: false)
            ->assertDontSee('open_24h_to_split', escape: false)
            ->assertDontSee('Buka Linimasa 24 Jam untuk Membelah Shift', escape: false)
            ->assertDontSee('Open 24h Timeline to Split Shift', escape: false)
            ->assertSee('pb-32 sm:pb-12', escape: false)
            ->assertSee('data-prevent-drawer-swipe', escape: false)
            ->assertSee('transform ease-out duration-300', escape: false);
    }
}
