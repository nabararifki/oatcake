<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAwareLayoutAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function createMultiRoleFixture(): array
    {
        $company = Company::create([
            'code' => 'NOVOTEL-YIA',
            'name' => 'Novotel Yogyakarta International Airport Kulon Progo',
            'latitude' => '-7.9048250',
            'longitude' => '110.0572110',
            'geofence_radius_meters' => 150,
            'accent_color' => '#004F9F',
            'is_active' => true,
        ]);

        $foDept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $hkDept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'HK',
            'name' => 'Housekeeping',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $hrDept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'HR',
            'name' => 'Human Resources',
            'is_operational' => false,
            'min_staff_per_shift' => 1,
            'is_executive_oversight' => false,
        ]);

        $tagHod = Tag::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'HOD',
            'name' => 'Head of Department',
            'badge_color' => '#4F46E5',
        ]);

        $superAdmin = User::create([
            'company_id' => null,
            'name' => 'Super Administrator',
            'username' => 'superadmin',
            'email' => 'admin@zonabara.com',
            'password' => Hash::make('Password123!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $adminUser = User::create([
            'company_id' => $company->id,
            'name' => 'Diana HR Manager',
            'username' => 'diana_hr_admin',
            'email' => 'diana.hr@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $adminEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $adminUser->id,
            'department_id' => $hrDept->id,
            'position' => 'Talent & Culture Manager',
            'join_date' => '2024-05-01',
            'status' => 'active',
        ]);

        $foManagerUser = User::create([
            'company_id' => $company->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra_fo_mgr',
            'email' => 'hendra.fo@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $foManagerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $foManagerUser->id,
            'department_id' => $foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-06-01',
            'status' => 'active',
        ]);
        $foManagerEmployee->tags()->sync([$tagHod->id]);

        $foStaffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Budi Receptionist',
            'username' => 'budi_fo_staff',
            'email' => 'budi.fo@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $foStaffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $foDept->id,
            'manager_id' => $foManagerEmployee->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $hkStaffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Sari Room Attendant',
            'username' => 'sari_hk_staff',
            'email' => 'sari.hk@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkStaffEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $hkStaffUser->id,
            'department_id' => $hkDept->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-02-01',
            'status' => 'active',
        ]);

        return compact(
            'company',
            'foDept',
            'hkDept',
            'hrDept',
            'superAdmin',
            'adminUser',
            'adminEmployee',
            'foManagerUser',
            'foManagerEmployee',
            'foStaffUser',
            'foStaffEmployee',
            'hkStaffUser',
            'hkStaffEmployee'
        );
    }

    /**
     * Uji 1: super_admin dapat mengakses dashboard dan bypass seluruh policy.
     */
    public function test_super_admin_can_access_dashboard_and_bypasses_all_policies(): void
    {
        $fixture = $this->createMultiRoleFixture();
        $superAdmin = $fixture['superAdmin'];

        $response = $this->actingAs($superAdmin)->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('OATCAKE Platform Administration')
            ->assertSee('Platform Global Overview (Super Admin)')
            ->assertSee('NOVOTEL-YIA');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Company::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $fixture['company']));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $fixture['foDept']));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $fixture['foStaffEmployee']));
    }

    /**
     * Uji 2: Pengguna role user dapat mengakses /dashboard dan hanya melihat profil mandirinya,
     * serta menerima 403 jika mencoba mengakses rute manajemen departemen/staf.
     */
    public function test_regular_user_sees_only_self_profile_and_gets_403_on_staff_and_department_routes(): void
    {
        $fixture = $this->createMultiRoleFixture();
        $regularUser = $fixture['foStaffUser'];

        $dashboardResponse = $this->actingAs($regularUser)->get('/dashboard');

        $dashboardResponse
            ->assertOk()
            ->assertSee('Kartu Profil Mandiri')
            ->assertSee('Budi Receptionist')
            ->assertSee('Guest Service Agent')
            ->assertSee('Hendra FO Manager')
            ->assertDontSee('Statistik Korporat')
            ->assertDontSee('Platform Global Overview')
            ->assertDontSee('Sari Room Attendant');

        // Policy checks for regular user
        $this->assertTrue(Gate::forUser($regularUser)->allows('view', $fixture['foStaffEmployee']));
        $this->assertFalse(Gate::forUser($regularUser)->allows('view', $fixture['hkStaffEmployee']));

        // 403 on staff and department management routes
        $this->actingAs($regularUser)->get('/staff')->assertForbidden();
        $this->actingAs($regularUser)->get('/staff/create')->assertForbidden();
        $this->actingAs($regularUser)->get('/departments')->assertForbidden();
        $this->actingAs($regularUser)->get('/company/settings')->assertForbidden();
    }

    /**
     * Uji 3: Pengguna role manager dapat mengakses /dashboard dan /staff,
     * tetapi hanya melihat data staf departemennya.
     */
    public function test_manager_can_access_dashboard_and_staff_but_only_sees_own_department_staff(): void
    {
        $fixture = $this->createMultiRoleFixture();
        $manager = $fixture['foManagerUser'];

        $dashboardResponse = $this->actingAs($manager)->get('/dashboard');

        $dashboardResponse
            ->assertOk()
            ->assertSee('Statistik Departemen Front Office (FO)')
            ->assertSee('Operasional 24 Jam')
            ->assertSee('Budi Receptionist')
            ->assertDontSee('Sari Room Attendant');

        $staffResponse = $this->actingAs($manager)->get('/staff');

        $staffResponse
            ->assertOk()
            ->assertSee('Budi Receptionist')
            ->assertDontSee('Sari Room Attendant');

        // Policy verification
        $this->assertTrue(Gate::forUser($manager)->allows('view', $fixture['foStaffEmployee']));
        $this->assertFalse(Gate::forUser($manager)->allows('view', $fixture['hkStaffEmployee']));
    }

    /**
     * Uji 4: Pengguna role admin melihat metrik seluruh departemen di perusahaannya.
     */
    public function test_admin_sees_metrics_for_all_departments_in_company(): void
    {
        $fixture = $this->createMultiRoleFixture();
        $admin = $fixture['adminUser'];

        $response = $this->actingAs($admin)->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Statistik Korporat')
            ->assertSee('Front Office')
            ->assertSee('Housekeeping')
            ->assertSee('Human Resources')
            ->assertSee('2 Operasional 24 Jam : 1 Back-Office')
            ->assertSee('-7.9048250, 110.0572110')
            ->assertSee('150 Meter');
    }

    /**
     * Uji 5: Injeksi warna aksen tenant ter-render valid pada response HTML dashboard
     * (string --primary-accent: #004F9F ada di DOM).
     */
    public function test_dynamic_tenant_accent_color_is_injected_into_dashboard_html(): void
    {
        $fixture = $this->createMultiRoleFixture();

        $response = $this->actingAs($fixture['adminUser'])->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('--primary-accent: #004F9F;', escape: false);
    }
}
