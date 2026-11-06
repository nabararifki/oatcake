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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Shift $shiftMo;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->shiftMo = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();
    }

    /**
     * Skenario 1: Dashboard memuat widget presensi minimalis untuk pengguna bertipe employee
     * lengkap dengan status shift, badge Provisional DP (jika libur), dan transisi status presensi hari ini.
     */
    public function test_scenario_1_dashboard_renders_minimalist_attendance_widget_for_employee_with_shift_and_attendance_states(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 17, 7, 5, 0, 'Asia/Jakarta'));

        PublicHoliday::create([
            'company_id' => null,
            'holiday_date' => '2026-08-17',
            'name' => 'Hari Kemerdekaan RI',
            'is_national' => true,
        ]);

        $staffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.dashboard',
            'email' => 'budi.dashboard@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $staffUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftMo->id,
            'schedule_date' => '2026-08-17',
            'status' => 'published',
        ]);

        // Kondisi 1: Belum Presensi Masuk
        $resBeforeClockIn = $this->actingAs($staffUser)->get(route('dashboard'));
        $resBeforeClockIn->assertOk()
            ->assertSee('id="dashboard-attendance-widget"', escape: false)
            ->assertSee('dashboardAttendanceWidget', escape: false)
            ->assertSee('getPreciseLocation()', escape: false)
            ->assertSee('window.isSecureContext', escape: false)
            ->assertSee('enableHighAccuracy: false, timeout: 20000, maximumAge: 60000', escape: false)
            ->assertSee('07:00–15:00')
            ->assertSee('✦ Provisional DP')
            ->assertSee(__('dashboard.clock_in_now'))
            ->assertSee(__('dashboard.outside_duty_prompt'))
            ->assertSee('capture="user"', escape: false);

        // Kondisi 2: Sudah Clock-In, Belum Clock-Out
        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'clock_in_at' => Carbon::create(2026, 8, 17, 7, 2, 0, 'Asia/Jakarta'),
            'clock_in_lat' => -7.9048250,
            'clock_in_lon' => 110.0572110,
            'clock_in_distance_meters' => 15,
            'is_outside_radius' => false,
            'status' => 'on_time',
            'late_minutes' => 0,
            'is_provisional_dp' => true,
        ]);

        $resAfterClockIn = $this->actingAs($staffUser)->get(route('dashboard'));
        $resAfterClockIn->assertOk()
            ->assertSee('07:02 WIB')
            ->assertSee(__('dashboard.clock_out_now'));

        // Kondisi 3: Sudah Clock-Out (Selesai Hari Ini)
        $attendance->update([
            'clock_out_at' => Carbon::create(2026, 8, 17, 15, 2, 0, 'Asia/Jakarta'),
            'clock_out_lat' => -7.9048250,
            'clock_out_lon' => 110.0572110,
            'clock_out_distance_meters' => 12,
        ]);

        $resCompleted = $this->actingAs($staffUser)->get(route('dashboard'));
        $resCompleted->assertOk()
            ->assertSee('07:02 – 15:02')
            ->assertSee('8 Jam Kerja');

        Carbon::setTestNow();
    }

    /**
     * Skenario 2: Dashboard menyembunyikan widget presensi personal untuk role super_admin
     * yang tidak memiliki relasi employee, dan menampilkan ringkasan platform makro.
     */
    public function test_scenario_2_dashboard_hides_personal_attendance_widget_for_super_admin_without_employee(): void
    {
        $superAdmin = User::factory()->create([
            'company_id' => null,
            'name' => 'Global Super Admin',
            'username' => 'root.superadmin',
            'email' => 'root@zonabara.com',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($superAdmin)->get(route('dashboard'));

        $response->assertOk()
            ->assertDontSee('id="dashboard-attendance-widget"', escape: false)
            ->assertDontSee('dashboardAttendanceWidget', escape: false)
            ->assertSee(__('common.platform_global_overview'));
    }

    /**
     * Skenario 3: Halaman Roster menggunakan multi-line flex-wrap pada toolbar dan bilah status
     * tanpa overflow-x-auto pada kontrol kanan, sembari mempertahankan scroll matriks .o-inset-stage.
     */
    public function test_scenario_3_roster_toolbar_uses_responsive_flex_wrap_without_horizontal_toolbar_scroll(): void
    {
        $managerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Manager FO',
            'username' => 'mgr.fo.roster',
            'email' => 'mgr.fo.roster@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $managerUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-06-01',
            'status' => 'active',
        ]);

        $response = $this->actingAs($managerUser)->get(route('roster.index'));

        $response->assertOk()
            ->assertSee('flex flex-wrap items-center gap-2 sm:gap-3 w-full sm:w-auto', escape: false)
            ->assertSee('w-full sm:w-auto flex flex-wrap items-center gap-1.5 text-xs py-1.5 px-3 rounded-xl', escape: false)
            ->assertSee('id="roster-gantt-viewport" class="flex-1 min-h-0 overflow-auto relative o-inset-stage bg-canvas"', escape: false)
            ->assertSee('toggleDateExpand(', escape: false)
            ->assertDontSee('(24h ×)', escape: false)
            ->assertDontSee('overflow-x-auto no-scrollbar w-full sm:w-auto flex items-center gap-2', escape: false);
    }
}
