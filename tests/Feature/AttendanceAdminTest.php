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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Employee $staff;

    protected Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $dept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $staffUser = $this->makeUser('staff.adm', 'user');
        $this->staff = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $staffUser->id,
            'department_id' => $dept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $shift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'schedule_date' => '2026-11-04',
            'status' => 'published',
        ]);

        $this->attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->staff->id,
            'work_schedule_id' => $schedule->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 7, 40, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 4, 14, 0, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 40,
            'early_leave_minutes' => 60,
            'is_outside_geofence' => false,
        ]);
    }

    protected function makeUser(string $username, string $role, ?Company $company = null): User
    {
        return User::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username.'@example.test',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    protected function payload(array $override = []): array
    {
        return array_merge([
            'clock_in_at' => '2026-11-04 06:58:00',
            'clock_out_at' => '2026-11-04 15:02:00',
            'status' => 'on_time',
            'notes' => 'Lupa clock-in',
        ], $override);
    }

    /**
     * Skenario 1: Admin / Super User / Super Admin berhasil mengoreksi jam & status presensi.
     */
    public function test_scenario_1_admin_and_super_admin_can_update_attendance(): void
    {
        $admin = $this->makeUser('admin.one', 'admin');

        $this->actingAs($admin)
            ->putJson(route('attendance.update', $this->attendance->uuid), $this->payload())
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['status' => 'on_time', 'late_minutes' => 0, 'early_leave_minutes' => 0]]);

        $fresh = $this->attendance->fresh();
        $this->assertSame('06:58:00', $fresh->clock_in_at->timezone('Asia/Jakarta')->format('H:i:s'));
        $this->assertSame('15:02:00', $fresh->clock_out_at->timezone('Asia/Jakarta')->format('H:i:s'));
        $this->assertSame('on_time', $fresh->status);
        $this->assertSame(0, $fresh->late_minutes);
        $this->assertSame(0, $fresh->early_leave_minutes);
        $this->assertSame(8.07, $fresh->getWorkDurationInHours());
        $this->assertStringContainsString('Lupa clock-in', $fresh->notes);
        $this->assertStringContainsString('Dikoreksi oleh Admin Admin.one', $fresh->notes);

        $superAdmin = $this->makeUser('super.one', 'super_admin');
        $this->actingAs($superAdmin)
            ->putJson(route('attendance.update', $this->attendance->uuid), $this->payload(['status' => 'early_leave', 'clock_out_at' => '2026-11-04 14:00:00']))
            ->assertOk();

        $this->assertSame('early_leave', $this->attendance->fresh()->status);
        $this->assertSame(60, $this->attendance->fresh()->early_leave_minutes);

        // Validasi: clock_out harus setelah clock_in
        $this->actingAs($admin)
            ->putJson(route('attendance.update', $this->attendance->uuid), $this->payload(['clock_out_at' => '2026-11-04 05:00:00']))
            ->assertStatus(422);
    }

    /**
     * Skenario 2: Admin menghapus rekod beserta file swafoto fisiknya.
     */
    public function test_scenario_2_admin_can_delete_attendance_and_proof_photo_file(): void
    {
        Storage::fake('local');
        $path = 'attendance_proofs/'.$this->company->id.'/2026-11/proof.webp';
        Storage::disk('local')->put($path, 'fake-image');
        $this->attendance->update(['proof_photo_path' => $path]);

        $admin = $this->makeUser('admin.two', 'admin');

        $this->actingAs($admin)
            ->deleteJson(route('attendance.destroy', $this->attendance->uuid))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('attendances', ['id' => $this->attendance->id]);
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * Skenario 3: Manager dan User biasa ditolak (403).
     */
    public function test_scenario_3_manager_and_regular_user_are_forbidden(): void
    {
        foreach (['manager', 'user'] as $role) {
            $actor = $this->makeUser('actor.'.$role, $role);

            $this->actingAs($actor)
                ->putJson(route('attendance.update', $this->attendance->uuid), $this->payload())
                ->assertForbidden();

            $this->actingAs($actor)
                ->deleteJson(route('attendance.destroy', $this->attendance->uuid))
                ->assertForbidden();
        }

        $this->assertSame('late', $this->attendance->fresh()->status);
        $this->assertDatabaseHas('attendances', ['id' => $this->attendance->id]);
    }

    /**
     * Skenario 4: Admin tenant lain ditolak (multi-tenancy).
     */
    public function test_scenario_4_admin_of_another_tenant_is_forbidden(): void
    {
        $otherCompany = Company::create(['code' => 'OTHER-HOTEL', 'name' => 'Other Hotel', 'latitude' => -6.2, 'longitude' => 106.8]);
        $foreignAdmin = $this->makeUser('admin.foreign', 'admin', $otherCompany);

        $this->actingAs($foreignAdmin)
            ->putJson(route('attendance.update', $this->attendance->uuid), $this->payload())
            ->assertForbidden();

        $this->actingAs($foreignAdmin)
            ->deleteJson(route('attendance.destroy', $this->attendance->uuid))
            ->assertForbidden();

        $this->assertSame('late', $this->attendance->fresh()->status);
        $this->assertDatabaseHas('attendances', ['id' => $this->attendance->id]);
    }

    /**
     * UI: kolom aksi & modal hanya tampil bagi admin tier.
     */
    public function test_admin_sees_action_modals_while_manager_does_not(): void
    {
        $admin = $this->makeUser('admin.ui', 'admin');
        $this->actingAs($admin)->get(route('attendance.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('edit-attendance-modal', false)
            ->assertSee('delete-attendance-modal', false);

        $manager = $this->makeUser('manager.ui', 'manager');
        $this->actingAs($manager)->get(route('attendance.index'))
            ->assertOk()
            ->assertDontSee('edit-attendance-modal', false);
    }
}
