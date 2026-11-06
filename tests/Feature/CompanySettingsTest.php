<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function createFixture(): array
    {
        $company = Company::create([
            'code' => 'NOVOTEL-YIA',
            'name' => 'Novotel Yogyakarta International Airport Kulon Progo',
            'address' => 'Jl. Wates - Purworejo KM 10, Temon, Kulon Progo',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $superAdmin = User::create([
            'company_id' => null,
            'name' => 'Global Super Admin',
            'username' => 'root_superadmin',
            'email' => 'root@oatcake.id',
            'password' => Hash::make('Password123!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $adminUser = User::create([
            'company_id' => $company->id,
            'name' => 'Diana HR Admin',
            'username' => 'diana_admin',
            'email' => 'diana@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $managerUser = User::create([
            'company_id' => $company->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra_mgr',
            'email' => 'hendra@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $staffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Budi Staff',
            'username' => 'budi_staff',
            'email' => 'budi@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        return compact('company', 'superAdmin', 'adminUser', 'managerUser', 'staffUser');
    }

    public function test_admin_can_view_and_update_company_geofence_and_timezone_settings(): void
    {
        $fixture = $this->createFixture();

        // 1. Akses halaman pengaturan properti (/settings/company)
        $editResponse = $this->actingAs($fixture['adminUser'])->get(route('settings.company.edit'));
        $editResponse->assertOk()
            ->assertSee('Novotel Yogyakarta International Airport Kulon Progo')
            ->assertSee('NOVOTEL-YIA');

        // 2. Update koordinat GPS, geofence_radius, dan timezone
        $updateResponse = $this->actingAs($fixture['adminUser'])->putJson(route('settings.company.update'), [
            'name' => 'Novotel YIA Kulon Progo Updated',
            'code' => 'NVT-YIA',
            'address' => 'Kawasan Bandara Internasional Yogyakarta',
            'latitude' => -7.9051234,
            'longitude' => 110.0584321,
            'geofence_radius' => 250,
            'timezone' => 'Asia/Makassar',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('company.name', 'Novotel YIA Kulon Progo Updated')
            ->assertJsonPath('company.code', 'NVT-YIA')
            ->assertJsonPath('company.geofence_radius', 250)
            ->assertJsonPath('company.timezone', 'Asia/Makassar');

        $this->assertDatabaseHas('companies', [
            'id' => $fixture['company']->id,
            'name' => 'Novotel YIA Kulon Progo Updated',
            'code' => 'NVT-YIA',
            'geofence_radius' => 250,
            'geofence_radius_meters' => 250,
            'timezone' => 'Asia/Makassar',
        ]);

        // 3. Validasi batas geofence_radius (20 - 1000m) dan timezone valid
        $invalidResponse = $this->actingAs($fixture['adminUser'])->putJson(route('settings.company.update'), [
            'name' => 'Novotel YIA',
            'code' => 'NVT-YIA',
            'latitude' => -7.9051234,
            'longitude' => 110.0584321,
            'geofence_radius' => 10, // di bawah 20m
            'timezone' => 'Europe/London', // di luar zona waktu Indonesia
        ]);

        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['geofence_radius', 'timezone']);
    }

    public function test_regular_user_and_manager_are_forbidden_from_accessing_or_updating_company_settings(): void
    {
        $fixture = $this->createFixture();

        // Role user ditolak 403 Forbidden
        $this->actingAs($fixture['staffUser'])
            ->get(route('settings.company.edit'))
            ->assertForbidden();

        $this->actingAs($fixture['staffUser'])
            ->putJson(route('settings.company.update'), [
                'name' => 'Hacked Hotel',
                'code' => 'HACK',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'geofence_radius' => 500,
                'timezone' => 'Asia/Jakarta',
            ])
            ->assertForbidden();

        // Role manager ditolak 403 Forbidden
        $this->actingAs($fixture['managerUser'])
            ->get(route('settings.company.edit'))
            ->assertForbidden();

        $this->actingAs($fixture['managerUser'])
            ->putJson(route('settings.company.update'), [
                'name' => 'Manager Edit',
                'code' => 'MGR',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'geofence_radius' => 200,
                'timezone' => 'Asia/Jakarta',
            ])
            ->assertForbidden();
    }

    public function test_only_super_admin_can_register_new_tenant_company(): void
    {
        $fixture = $this->createFixture();

        // Admin biasa ditolak saat mencoba membuat tenant baru
        $this->actingAs($fixture['adminUser'])
            ->postJson(route('admin.companies.store'), [
                'name' => 'Mercure Bali Nusa Dua',
                'code' => 'MERCURE-BND',
                'latitude' => -8.805,
                'longitude' => 115.225,
                'geofence_radius' => 120,
                'timezone' => 'Asia/Makassar',
            ])
            ->assertForbidden();

        // Super Admin murni berhasil mendaftarkan tenant baru (POST /admin/companies)
        $storeResponse = $this->actingAs($fixture['superAdmin'])
            ->postJson(route('admin.companies.store'), [
                'name' => 'Mercure Bali Nusa Dua',
                'code' => 'MERCURE-BND',
                'address' => 'Jl. Nusa Dua Selatan, Benoa, Bali',
                'latitude' => -8.805,
                'longitude' => 115.225,
                'geofence_radius' => 120,
                'timezone' => 'Asia/Makassar',
            ]);

        $storeResponse->assertCreated()
            ->assertJsonPath('company.code', 'MERCURE-BND')
            ->assertJsonPath('company.geofence_radius', 120)
            ->assertJsonPath('company.timezone', 'Asia/Makassar');

        $this->assertDatabaseHas('companies', [
            'code' => 'MERCURE-BND',
            'name' => 'Mercure Bali Nusa Dua',
            'geofence_radius' => 120,
            'timezone' => 'Asia/Makassar',
        ]);
    }
}
