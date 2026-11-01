<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function createTenantFixture(): array
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

        $tagMod = Tag::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'MOD_ELIGIBLE',
            'name' => 'Eligible Manager on Duty',
            'badge_color' => '#D97706',
        ]);

        $tagFirstAider = Tag::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'FIRST_AIDER',
            'name' => 'Petugas P3K Terverifikasi',
            'badge_color' => '#10B981',
        ]);

        $managerUser = User::create([
            'company_id' => $company->id,
            'name' => 'Hendra Wijaya',
            'username' => 'hendra_fo_mgr',
            'email' => 'hendra.wijaya@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $managerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $managerUser->id,
            'department_id' => $foDept->id,
            'manager_id' => null,
            'position' => 'Front Office Manager',
            'join_date' => '2025-01-10',
            'phone' => '+6281122334455',
            'status' => 'active',
        ]);

        return compact(
            'company',
            'foDept',
            'hkDept',
            'tagMod',
            'tagFirstAider',
            'managerUser',
            'managerEmployee'
        );
    }

    /**
     * Uji 1: Tamu publik mengakses /register -> harus 404.
     */
    public function test_public_guest_accessing_register_returns_404(): void
    {
        $getResponse = $this->get('/register');
        $getResponse->assertNotFound();

        $postResponse = $this->post('/register', [
            'name' => 'Hacker Guest',
            'email' => 'hacker@example.com',
            'password' => 'password123',
        ]);
        $postResponse->assertNotFound();
    }

    /**
     * Uji 2: Pengguna role user biasa mengakses /staff/create -> harus 403 (Forbidden).
     */
    public function test_regular_user_cannot_access_staff_create_and_receives_403(): void
    {
        $fixture = $this->createTenantFixture();

        $regularUser = User::create([
            'company_id' => $fixture['company']->id,
            'name' => 'Staf Biasa',
            'username' => 'staf_biasa',
            'email' => 'staf.biasa@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $response = $this->actingAs($regularUser)->get('/staff/create');

        $response->assertForbidden();
    }

    /**
     * Uji 3: Manager berhasil mendaftarkan staf di departemennya -> User dan Employee tersimpan di DB, status 302/redirect dengan flash message.
     */
    public function test_manager_can_provision_staff_in_own_department(): void
    {
        $fixture = $this->createTenantFixture();

        $payload = [
            'name' => 'Rina Kusuma',
            'username' => 'rina_kusuma',
            'email' => 'rina.kusuma@novotel-yia.com',
            'department_id' => $fixture['foDept']->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2026-10-18',
            'phone' => '+6281299887766',
            'role' => 'user',
            'manager_id' => $fixture['managerEmployee']->id,
            'tags' => [$fixture['tagFirstAider']->id],
            'password' => '', // Kosong -> auto-generate 16 char random password di backend
        ];

        $response = $this
            ->actingAs($fixture['managerUser'])
            ->post('/staff', $payload);

        $response
            ->assertStatus(302)
            ->assertRedirect(route('staff.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'company_id' => $fixture['company']->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina_kusuma',
            'email' => 'rina.kusuma@novotel-yia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $provisionedUser = User::where('username', 'rina_kusuma')->firstOrFail();
        $this->assertNotEmpty($provisionedUser->password);

        $this->assertDatabaseHas('employees', [
            'company_id' => $fixture['company']->id,
            'user_id' => $provisionedUser->id,
            'department_id' => $fixture['foDept']->id,
            'manager_id' => $fixture['managerEmployee']->id,
            'position' => 'Guest Service Agent',
            'status' => 'active',
        ]);

        $provisionedEmployee = Employee::withoutGlobalScopes()
            ->where('user_id', $provisionedUser->id)
            ->firstOrFail();

        $this->assertDatabaseHas('employee_tags', [
            'employee_id' => $provisionedEmployee->id,
            'tag_id' => $fixture['tagFirstAider']->id,
        ]);
    }

    /**
     * Uji 4: Manager gagal mendaftarkan staf di luar departemennya -> 403 atau Validation Error.
     */
    public function test_manager_cannot_provision_staff_outside_own_department(): void
    {
        $fixture = $this->createTenantFixture();

        $payload = [
            'name' => 'Agus Pratama',
            'username' => 'agus_pratama',
            'email' => 'agus.pratama@novotel-yia.com',
            'department_id' => $fixture['hkDept']->id, // HK department, whereas Manager belongs to FO
            'position' => 'Room Attendant',
            'join_date' => '2026-10-18',
            'phone' => '+6281344556677',
            'role' => 'user',
            'manager_id' => $fixture['managerEmployee']->id,
        ];

        $response = $this
            ->actingAs($fixture['managerUser'])
            ->from('/staff/create')
            ->post('/staff', $payload);

        $response
            ->assertStatus(302)
            ->assertSessionHasErrors(['department_id']);

        $this->assertDatabaseMissing('users', [
            'username' => 'agus_pratama',
        ]);

        $this->assertDatabaseMissing('employees', [
            'position' => 'Room Attendant',
        ]);
    }

    /**
     * Uji 5: Manager berhasil mendaftarkan staf menggunakan akun Gmail pribadi.
     */
    public function test_manager_can_provision_staff_with_personal_gmail(): void
    {
        $fixture = $this->createTenantFixture();

        $payload = [
            'name' => 'Budi Santoso',
            'username' => 'budi_santoso_gmail',
            'email' => 'budi.santoso@gmail.com',
            'department_id' => $fixture['foDept']->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2026-10-19',
            'phone' => '+6281299887755',
            'role' => 'user',
            'manager_id' => $fixture['managerEmployee']->id,
            'tags' => [$fixture['tagFirstAider']->id],
            'password' => '',
        ];

        $response = $this
            ->actingAs($fixture['managerUser'])
            ->post('/staff', $payload);

        $response
            ->assertStatus(302)
            ->assertRedirect(route('staff.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'username' => 'budi_santoso_gmail',
            'email' => 'budi.santoso@gmail.com',
        ]);
    }
}
