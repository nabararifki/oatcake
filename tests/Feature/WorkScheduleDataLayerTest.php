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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkScheduleDataLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function createMultiTenantFixture(): array
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'FO')
            ->firstOrFail();
        $hkDeptA = Department::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'HK')
            ->firstOrFail();
        $modTagA = Tag::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'MOD_ELIGIBLE')
            ->firstOrFail();

        $userA = User::create([
            'company_id' => $hotelA->id,
            'name' => 'Manager FO Novotel',
            'username' => 'mgr_fo_novotel',
            'email' => 'mgr.fo@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $employeeA = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $userA->id,
            'department_id' => $foDeptA->id,
            'position' => 'Front Office Manager',
            'join_date' => '2025-02-01',
            'phone' => '+6281122334455',
            'status' => 'active',
        ]);

        $hotelB = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Styles Yogyakarta International Airport',
            'latitude' => '-7.9048000',
            'longitude' => '110.0572000',
            'geofence_radius_meters' => 150,
            'accent_color' => '#E11D48',
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
            'name' => 'Manager FO Ibis',
            'username' => 'mgr_fo_ibis',
            'email' => 'mgr.fo@ibis-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $employeeB = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'user_id' => $userB->id,
            'department_id' => $foDeptB->id,
            'position' => 'Front Office Manager',
            'join_date' => '2025-03-01',
            'phone' => '+6281199887766',
            'status' => 'active',
        ]);

        $shiftB = Shift::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'MO',
            'name' => 'Morning Shift Ibis',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'color_badge' => '#3B82F6',
            'is_active' => true,
        ]);

        return compact(
            'hotelA',
            'foDeptA',
            'hkDeptA',
            'modTagA',
            'userA',
            'employeeA',
            'hotelB',
            'foDeptB',
            'userB',
            'employeeB',
            'shiftB'
        );
    }

    /**
     * Skenario 1: Master shift tersimpan dengan kalkulasi deteksi otomatis cross_day dan durasi jam yang valid.
     */
    public function test_scenario_1_master_shifts_seeded_with_automatic_cross_day_and_duration_calculation(): void
    {
        $fixture = $this->createMultiTenantFixture();
        $hotelA = $fixture['hotelA'];

        $morning = Shift::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'MO')
            ->firstOrFail();
        $this->assertFalse($morning->cross_day);
        $this->assertFalse($morning->isOvernight());
        $this->assertSame(8.0, $morning->getDurationInHours());

        $generalOffice = Shift::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'GO')
            ->firstOrFail();
        $this->assertFalse($generalOffice->cross_day);
        $this->assertSame(8.5, $generalOffice->getDurationInHours());

        $night = Shift::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'NI')
            ->firstOrFail();
        $this->assertTrue($night->cross_day);
        $this->assertTrue($night->isOvernight());
        $this->assertSame(8.0, $night->getDurationInHours());

        $mod = Shift::withoutGlobalScopes()
            ->where('company_id', $hotelA->id)
            ->where('code', 'MOD')
            ->firstOrFail();
        $this->assertTrue($mod->cross_day);
        $this->assertTrue($mod->isOvernight());
        $this->assertSame(12.0, $mod->getDurationInHours());

        // Verifikasi deteksi otomatis cross_day ketika membuat shift baru tanpa menyertakan atribut cross_day
        $autoOvernightShift = Shift::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'code' => 'LATE',
            'name' => 'Late Closing Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
        ]);

        $this->assertTrue($autoOvernightShift->cross_day);
        $this->assertTrue($autoOvernightShift->isOvernight());
        $this->assertSame(8.0, $autoOvernightShift->getDurationInHours());
    }

    /**
     * Skenario 2: Multi-tenant scoping memastikan jadwal dan shift Hotel A tidak bocor ke Hotel B.
     */
    public function test_scenario_2_multi_tenant_scoping_isolates_shifts_and_work_schedules_between_hotels(): void
    {
        $fixture = $this->createMultiTenantFixture();

        $shiftA = Shift::withoutGlobalScopes()
            ->where('company_id', $fixture['hotelA']->id)
            ->where('code', 'MO')
            ->firstOrFail();

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $shiftA->id,
            'schedule_date' => '2026-11-10',
            'status' => 'published',
            'created_by' => $fixture['userA']->id,
        ]);

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelB']->id,
            'employee_id' => $fixture['employeeB']->id,
            'shift_id' => $fixture['shiftB']->id,
            'schedule_date' => '2026-11-10',
            'status' => 'published',
            'created_by' => $fixture['userB']->id,
        ]);

        // Login sebagai Manager Hotel A (Novotel YIA)
        $this->actingAs($fixture['userA']);

        $visibleShiftsForA = Shift::active()->get();
        $this->assertCount(6, $visibleShiftsForA);
        $this->assertTrue($visibleShiftsForA->every(fn (Shift $s) => $s->company_id === $fixture['hotelA']->id));

        $visibleSchedulesForA = WorkSchedule::all();
        $this->assertCount(1, $visibleSchedulesForA);
        $this->assertSame($fixture['employeeA']->id, $visibleSchedulesForA->first()->employee_id);

        // Login sebagai Manager Hotel B (Ibis Styles YIA)
        $this->actingAs($fixture['userB']);

        $visibleShiftsForB = Shift::active()->get();
        $this->assertCount(1, $visibleShiftsForB);
        $this->assertSame($fixture['hotelB']->id, $visibleShiftsForB->first()->company_id);

        $visibleSchedulesForB = WorkSchedule::all();
        $this->assertCount(1, $visibleSchedulesForB);
        $this->assertSame($fixture['employeeB']->id, $visibleSchedulesForB->first()->employee_id);
    }

    /**
     * Skenario 3: Dukungan multi-shift pada hari yang sama untuk karyawan yang sama setelah pencabutan unique constraint.
     */
    public function test_scenario_3_database_allows_multiple_shifts_for_same_employee_and_date(): void
    {
        $fixture = $this->createMultiTenantFixture();

        $shiftMO = Shift::withoutGlobalScopes()
            ->where('company_id', $fixture['hotelA']->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $shiftAF = Shift::withoutGlobalScopes()
            ->where('company_id', $fixture['hotelA']->id)
            ->where('code', 'AF')
            ->firstOrFail();

        $first = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => '2026-11-15',
            'status' => 'published',
            'created_by' => $fixture['userA']->id,
        ]);

        $second = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $shiftAF->id,
            'schedule_date' => '2026-11-15',
            'status' => 'draft',
            'created_by' => $fixture['userA']->id,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(
            2,
            WorkSchedule::withoutGlobalScopes()
                ->where('employee_id', $fixture['employeeA']->id)
                ->where('schedule_date', '2026-11-15')
                ->count()
        );
    }

    /**
     * Skenario 4: Fleksibilitas penugasan shift_id = null (Day Off/Libur) dan asosiasi duty_tag_id untuk MOD.
     */
    public function test_scenario_4_supports_day_off_null_shift_and_mod_duty_tag_assignment(): void
    {
        $fixture = $this->createMultiTenantFixture();

        // 4a. Penugasan Day Off / Libur (shift_id = null)
        $dayOffSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => null,
            'schedule_date' => '2026-11-16',
            'status' => 'published',
            'notes' => 'Weekly Day Off / DP',
            'created_by' => $fixture['userA']->id,
        ]);

        $this->assertTrue($dayOffSchedule->isOff());
        $this->assertTrue($dayOffSchedule->isPublished());
        $this->assertNull($dayOffSchedule->shift);
        $this->assertSame('', $dayOffSchedule->getEffectiveStartTime());
        $this->assertSame('', $dayOffSchedule->getEffectiveEndTime());

        // 4b. Penugasan Shift MOD dengan pengikatan tag fungsional MOD_ELIGIBLE
        $modShift = Shift::withoutGlobalScopes()
            ->where('company_id', $fixture['hotelA']->id)
            ->where('code', 'MOD')
            ->firstOrFail();

        $modSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $modShift->id,
            'schedule_date' => '2026-11-17',
            'duty_tag_id' => $fixture['modTagA']->id,
            'status' => 'published',
            'created_by' => $fixture['userA']->id,
        ]);

        $this->assertFalse($modSchedule->isOff());
        $this->assertNotNull($modSchedule->dutyTag);
        $this->assertSame('MOD_ELIGIBLE', $modSchedule->dutyTag->code);
        $this->assertSame('19:00:00', $modSchedule->getEffectiveStartTime());
        $this->assertSame('07:00:00', $modSchedule->getEffectiveEndTime());
    }

    /**
     * Skenario 5: Helper resolusi waktu efektif mendahulukan jam kustom hasil resizing Gantt Chart serta verifikasi query scopes.
     */
    public function test_scenario_5_effective_time_resolution_prioritizes_custom_times_and_query_scopes_work(): void
    {
        $fixture = $this->createMultiTenantFixture();

        $shiftMO = Shift::withoutGlobalScopes()
            ->where('company_id', $fixture['hotelA']->id)
            ->where('code', 'MO')
            ->firstOrFail();

        // Jadwal standar tanpa kustomisasi jam (mengikuti shift MO: 07:00:00 - 15:00:00)
        $standardSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => '2026-11-18',
            'status' => 'published',
            'created_by' => $fixture['userA']->id,
        ]);

        $this->assertSame('07:00:00', $standardSchedule->getEffectiveStartTime());
        $this->assertSame('15:00:00', $standardSchedule->getEffectiveEndTime());

        // Jadwal dengan custom_start_time & custom_end_time hasil resize Gantt Chart (08:00:00 - 17:30:00)
        $resizedSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotelA']->id,
            'employee_id' => $fixture['employeeA']->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => '2026-11-19',
            'custom_start_time' => '08:00:00',
            'custom_end_time' => '17:30:00',
            'status' => 'draft',
            'created_by' => $fixture['userA']->id,
        ]);

        $this->assertSame('08:00:00', $resizedSchedule->getEffectiveStartTime());
        $this->assertSame('17:30:00', $resizedSchedule->getEffectiveEndTime());
        $this->assertFalse($resizedSchedule->isPublished());

        // Verifikasi Query Scopes (betweenDates, byDepartment, published, draft)
        $this->actingAs($fixture['userA']);

        $this->assertCount(2, WorkSchedule::betweenDates('2026-11-18', '2026-11-19')->get());
        $this->assertCount(1, WorkSchedule::published()->get());
        $this->assertCount(1, WorkSchedule::draft()->get());
        $this->assertCount(2, WorkSchedule::byDepartment($fixture['foDeptA']->id)->get());
        $this->assertCount(0, WorkSchedule::byDepartment($fixture['hkDeptA']->id)->get());
        $this->assertCount(2, $fixture['employeeA']->workSchedules);
        $this->assertCount(6, $fixture['hotelA']->shifts);
    }
}
