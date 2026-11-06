<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpDashboardScheduleFixture(): array
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $todayStr = Carbon::today('Asia/Jakarta')->toDateString();

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $hkDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'HK')->firstOrFail();
        $modTagA = Tag::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MOD_ELIGIBLE')->firstOrFail();

        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MO')->firstOrFail();
        $shiftAF = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'AF')->firstOrFail();
        $shiftMOD = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MOD')->firstOrFail();

        $managerUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Arif FO Manager',
            'username' => 'arif_fo_mgr',
            'email' => 'arif.fo@novotel-yia.com',
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

        $foStaffUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Nadia FO Staff',
            'username' => 'nadia_fo',
            'email' => 'nadia.fo@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $foStaffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $foDeptA->id,
            'manager_id' => $managerEmployee->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-02-15',
            'status' => 'active',
        ]);

        $hkStaffUser = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Rizky HK Attendant',
            'username' => 'rizky_hk',
            'email' => 'rizky.hk@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkStaffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $hkStaffUser->id,
            'department_id' => $hkDeptA->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-03-01',
            'status' => 'active',
        ]);

        // Tenant B (Hotel B) untuk pengujian isolasi multi-tenancy
        $hotelB = Company::create([
            'code' => 'MERCURE-SBY',
            'name' => 'Mercure Surabaya Grand Mirama',
            'latitude' => '-7.2856000',
            'longitude' => '112.7421000',
            'geofence_radius_meters' => 150,
            'accent_color' => '#7C3AED',
            'is_active' => true,
        ]);

        $foDeptB = Department::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'FO',
            'name' => 'Front Office Mercure',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $shiftHotelB = Shift::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'MO',
            'name' => 'Morning Shift Mercure',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'grace_period_minutes' => 15,
            'color_badge' => '#7C3AED',
            'is_active' => true,
        ]);

        $userB = User::create([
            'company_id' => $hotelB->id,
            'name' => 'Kevin Mercure Staff',
            'username' => 'kevin_mercure',
            'email' => 'kevin@mercure-sby.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'user_id' => $userB->id,
            'department_id' => $foDeptB->id,
            'position' => 'Receptionist Mercure',
            'join_date' => '2025-04-01',
            'status' => 'active',
        ]);

        return compact(
            'todayStr',
            'hotelA',
            'foDeptA',
            'hkDeptA',
            'modTagA',
            'shiftMO',
            'shiftAF',
            'shiftMOD',
            'managerUser',
            'managerEmployee',
            'foStaffUser',
            'foStaffEmployee',
            'hkStaffUser',
            'hkStaffEmployee',
            'hotelB',
            'foDeptB',
            'shiftHotelB',
            'userB',
            'employeeB'
        );
    }

    /**
     * Skenario 1: Modal jadwal hari ini di Dashboard memuat jadwal seluruh departemen pada hari berjalan,
     * menerapkan isolasi multi-tenancy (tidak membocorkan jadwal Hotel B),
     * dan menyaring hanya jadwal berstatus 'published' bagi staf biasa (role: user) sementara Manager/Admin dapat melihat 'draft'.
     */
    public function test_dashboard_loads_today_schedules_across_departments_with_tenant_isolation_and_published_filtering(): void
    {
        $fixture = $this->setUpDashboardScheduleFixture();
        $todayStr = $fixture['todayStr'];

        // 1. Jadwal PUBLISHED di Departemen FO (MOD Hari Ini)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['managerEmployee']->id,
            'shift_id' => $fixture['shiftMOD']->id,
            'duty_tag_id' => $fixture['modTagA']->id,
            'schedule_date' => $todayStr,
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 2. Jadwal PUBLISHED di Departemen HK (Lintas Departemen pada Tenant yang Sama)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['hkStaffEmployee']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => $todayStr,
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 3. Jadwal DRAFT di Departemen FO (Hanya boleh terlihat oleh Manager/Admin, disembunyikan dari User biasa)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['foStaffEmployee']->id,
            'shift_id' => $fixture['shiftAF']->id,
            'schedule_date' => $todayStr,
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 4. Jadwal PUBLISHED milik Tenant B (Hotel B - Mercure) pada hari yang sama -> TIDAK boleh bocor ke Hotel A
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelB']->id,
            'employee_id' => $fixture['employeeB']->id,
            'shift_id' => $fixture['shiftHotelB']->id,
            'schedule_date' => $todayStr,
            'status' => 'published',
            'created_by' => $fixture['userB']->id,
        ]);

        // A. Pengujian sebagai Staf Biasa (role: user) di Hotel A:
        //    Hanya melihat 2 jadwal 'published' (Arif FO Manager & Rizky HK Attendant lintas departemen),
        //    TIDAK melihat jadwal 'draft' (Nadia FO Staff) maupun jadwal Tenant B (Kevin Mercure Staff).
        $userHtmlResponse = $this->actingAs($fixture['hkStaffUser'])->get(route('dashboard'));
        $userHtmlResponse->assertOk()
            ->assertSee('today-schedules-modal', escape: false)
            ->assertSee('rounded-2xl border border-line bg-surface overflow-hidden', escape: false)
            ->assertSee('w-full max-h-[65dvh] overflow-x-auto overflow-y-auto overscroll-x-contain overscroll-y-contain rounded-2xl', escape: false)
            ->assertSee('min-w-[720px] sm:min-w-[860px] relative rounded-2xl bg-surface flex flex-col', escape: false)
            ->assertSee('sticky left-0 z-20 w-36 sm:w-48 shrink-0 bg-surface-muted border-r border-line shadow-[2px_0_6px_-2px_rgba(0,0,0,0.08)] px-3 py-1.5 text-2xs font-bold text-ink-muted uppercase tracking-wider truncate', escape: false)
            ->assertSee('sticky left-0 z-20 w-36 sm:w-48 shrink-0 bg-surface border-r border-line shadow-[2px_0_6px_-2px_rgba(0,0,0,0.08)]', escape: false)
            ->assertSee(__('dashboard.btn_view_today_schedule'))
            ->assertSee(__('dashboard.modal_today_title'))
            ->assertSee('00:00')
            ->assertSee('12:00')
            ->assertSee('24:00')
            ->assertSee('left: 29.17%; width: 33.33%;', escape: false)
            ->assertSee('8h')
            ->assertSee('12h')
            ->assertSee('Arif FO Manager')
            ->assertSee('Rizky HK Attendant')
            ->assertDontSee('Nadia FO Staff')
            ->assertDontSee('Kevin Mercure Staff');

        $userSchedules = $userHtmlResponse->viewData('todaySchedules');
        $this->assertCount(2, $userSchedules);
        $this->assertTrue($userSchedules->every(fn (WorkSchedule $ws) => $ws->status === 'published'));
        $this->assertTrue($userSchedules->every(fn (WorkSchedule $ws) => (int) $ws->company_id === (int) $fixture['hotelA']->id));

        // B. Pengujian endpoint JSON untuk Staf Biasa
        $userJsonResponse = $this->actingAs($fixture['hkStaffUser'])->getJson(route('dashboard.today-schedules'));
        $userJsonResponse->assertOk()
            ->assertJsonPath('total_scheduled', 2)
            ->assertJsonCount(2, 'schedules')
            ->assertJsonCount(1, 'mods');

        // C. Pengujian sebagai Manager di Hotel A:
        //    Dapat melihat 3 jadwal (2 published + 1 draft), tetapi tetap terisolasi dari Tenant B.
        $managerHtmlResponse = $this->actingAs($fixture['managerUser'])->get(route('dashboard'));
        $managerHtmlResponse->assertOk()
            ->assertSee('Arif FO Manager')
            ->assertSee('Rizky HK Attendant')
            ->assertSee('Nadia FO Staff')
            ->assertDontSee('Kevin Mercure Staff');

        $managerSchedules = $managerHtmlResponse->viewData('todaySchedules');
        $this->assertCount(3, $managerSchedules);
    }
}
