<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function createFixture(): array
    {
        $companyA = Company::create([
            'code' => 'NOVOTEL-YIA',
            'name' => 'Novotel Yogyakarta International Airport Kulon Progo',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $companyB = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport Kulon Progo',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 100,
            'geofence_radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $foDept = Department::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_24_hours' => true,
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'description' => 'Guest Service & Reception',
        ]);

        $emptyDept = Department::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'code' => 'SPA',
            'name' => 'InBalance Spa',
            'is_24_hours' => false,
            'is_operational' => false,
            'min_staff_per_shift' => 1,
            'description' => 'Wellness & Spa Center',
        ]);

        $adminUser = User::create([
            'company_id' => $companyA->id,
            'name' => 'Diana HR Admin',
            'username' => 'diana_admin',
            'email' => 'diana@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $managerUser = User::create([
            'company_id' => $companyA->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra_mgr',
            'email' => 'hendra@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $staffUser = User::create([
            'company_id' => $companyA->id,
            'name' => 'Budi Staff',
            'username' => 'budi_staff',
            'email' => 'budi@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'user_id' => $staffUser->id,
            'department_id' => $foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        return compact(
            'companyA',
            'companyB',
            'foDept',
            'emptyDept',
            'adminUser',
            'managerUser',
            'staffUser',
            'staffEmployee'
        );
    }

    public function test_admin_can_perform_full_crud_on_departments_within_active_company(): void
    {
        $fixture = $this->createFixture();

        // 1. Index menampilkan daftar departemen beserta staf aktif & status 24 jam
        $indexResponse = $this->actingAs($fixture['adminUser'])->get(route('departments.index'));
        $indexResponse->assertOk()
            ->assertSee('Front Office')
            ->assertSee('InBalance Spa');

        // 2. Store departemen baru
        $storeResponse = $this->actingAs($fixture['adminUser'])->postJson(route('departments.store'), [
            'name' => 'Food & Beverage Service',
            'code' => 'FBS',
            'is_24_hours' => true,
            'min_staff_per_shift' => 3,
            'description' => 'Restaurant, Banquet & Room Service',
        ]);

        $storeResponse->assertCreated()
            ->assertJsonPath('department.code', 'FBS')
            ->assertJsonPath('department.name', 'Food & Beverage Service')
            ->assertJsonPath('department.is_24_hours', true)
            ->assertJsonPath('department.min_staff_per_shift', 3);

        $this->assertDatabaseHas('departments', [
            'company_id' => $fixture['companyA']->id,
            'code' => 'FBS',
            'name' => 'Food & Beverage Service',
            'is_24_hours' => true,
            'min_staff_per_shift' => 3,
        ]);

        // Kode departemen yang sama diizinkan pada tenant berbeda (multi-tenancy unique per company_id)
        Department::withoutGlobalScopes()->create([
            'company_id' => $fixture['companyB']->id,
            'code' => 'FBS',
            'name' => 'F&B Ibis',
            'is_24_hours' => false,
            'is_operational' => false,
            'min_staff_per_shift' => 1,
        ]);

        // Duplikasi kode pada perusahaan yang sama ditolak 422
        $duplicateResponse = $this->actingAs($fixture['adminUser'])->postJson(route('departments.store'), [
            'name' => 'Duplicate FBS',
            'code' => 'FBS',
            'is_24_hours' => false,
            'min_staff_per_shift' => 1,
        ]);
        $duplicateResponse->assertStatus(422);

        // 3. Update departemen
        $updateResponse = $this->actingAs($fixture['adminUser'])->putJson(
            route('departments.update', $fixture['emptyDept']),
            [
                'name' => 'InBalance Wellness & Pool',
                'code' => 'WELL',
                'is_24_hours' => false,
                'min_staff_per_shift' => 2,
                'description' => 'Updated description',
            ]
        );

        $updateResponse->assertOk()
            ->assertJsonPath('department.code', 'WELL')
            ->assertJsonPath('department.name', 'InBalance Wellness & Pool')
            ->assertJsonPath('department.min_staff_per_shift', 2);

        // 4. Destroy departemen kosong (tanpa staf & tanpa jadwal) berhasil
        $deleteResponse = $this->actingAs($fixture['adminUser'])->deleteJson(
            route('departments.destroy', $fixture['emptyDept'])
        );
        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('departments', [
            'id' => $fixture['emptyDept']->id,
        ]);
    }

    public function test_rbac_enforcement_for_manager_read_only_and_user_forbidden(): void
    {
        $fixture = $this->createFixture();

        // Manager hanya boleh melihat daftar departemen (read-only)
        $this->actingAs($fixture['managerUser'])
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('Front Office');

        // Manager ditolak saat mencoba membuat, mengubah, atau menghapus departemen
        $this->actingAs($fixture['managerUser'])
            ->postJson(route('departments.store'), [
                'name' => 'Security',
                'code' => 'SEC',
                'is_24_hours' => true,
                'min_staff_per_shift' => 2,
            ])
            ->assertForbidden();

        $this->actingAs($fixture['managerUser'])
            ->putJson(route('departments.update', $fixture['emptyDept']), [
                'name' => 'Edited Spa',
                'code' => 'SPA',
                'is_24_hours' => false,
                'min_staff_per_shift' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($fixture['managerUser'])
            ->deleteJson(route('departments.destroy', $fixture['emptyDept']))
            ->assertForbidden();

        // User biasa ditolak sepenuhnya (HTTP 403 Forbidden)
        $this->actingAs($fixture['staffUser'])
            ->get(route('departments.index'))
            ->assertForbidden();

        $this->actingAs($fixture['staffUser'])
            ->postJson(route('departments.store'), [
                'name' => 'Unauthorized Dept',
                'code' => 'UNAUTH',
                'is_24_hours' => false,
                'min_staff_per_shift' => 1,
            ])
            ->assertForbidden();
    }

    public function test_safe_delete_guard_prevents_deleting_department_with_active_employees_or_schedules(): void
    {
        $fixture = $this->createFixture();

        // 1. Departemen FO masih memiliki karyawan aktif -> tolak dengan HTTP 422 JSON
        $responseWithStaff = $this->actingAs($fixture['adminUser'])
            ->deleteJson(route('departments.destroy', $fixture['foDept']));

        $responseWithStaff->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('departments', [
            'id' => $fixture['foDept']->id,
        ]);

        // 2. Uji juga apabila karyawan memiliki jadwal kerja aktif pada departemen
        $shift = Shift::withoutGlobalScopes()->create([
            'company_id' => $fixture['companyA']->id,
            'code' => 'MO',
            'name' => 'Morning Shift',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'grace_period_minutes' => 15,
            'color_badge' => '#3B82F6',
            'is_active' => true,
        ]);

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['companyA']->id,
            'employee_id' => $fixture['staffEmployee']->id,
            'shift_id' => $shift->id,
            'schedule_date' => '2026-11-05',
            'status' => 'published',
        ]);

        $this->assertGreaterThan(0, $fixture['foDept']->workSchedules()->count());

        $redirectResponse = $this->actingAs($fixture['adminUser'])
            ->delete(route('departments.destroy', $fixture['foDept']));

        $redirectResponse->assertRedirect(route('departments.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', [
            'id' => $fixture['foDept']->id,
        ]);
    }
}
