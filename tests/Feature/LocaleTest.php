<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_is_id(): void
    {
        $redirectResponse = $this->withHeaders(['Accept-Language' => ''])->get('/');
        $redirectResponse->assertRedirect(route('login'));

        $response = $this->withHeaders(['Accept-Language' => ''])->get('/login');

        $response->assertStatus(200);
        $this->assertSame('id', App::getLocale());
    }

    public function test_guest_switching_locale_to_en_updates_session_and_cookie(): void
    {
        $response = $this->from('/')->post(route('locale.update'), [
            'locale' => 'en',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('locale', 'en');
        $response->assertPlainCookie('locale', 'en');
    }

    public function test_authenticated_user_switching_locale_updates_database(): void
    {
        $user = User::factory()->create([
            'locale' => 'id',
        ]);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('locale.update'), [
                'locale' => 'en',
            ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('locale', 'en');
        $this->assertDatabaseHas('users', [
            'id'     => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_hierarchy_resolution_prioritizes_user_locale(): void
    {
        $user = User::factory()->create([
            'locale' => 'en',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'id'])
            ->withUnencryptedCookie('locale', 'id')
            ->withHeaders(['Accept-Language' => 'id-ID,id;q=0.9'])
            ->get('/dashboard');

        $response->assertStatus(200);
        $this->assertSame('en', App::getLocale());
    }

    public function test_unsupported_locale_is_rejected_with_validation_error(): void
    {
        $response = $this->from('/')->post(route('locale.update'), [
            'locale' => 'fr',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['locale']);
    }

    public function test_dashboard_strings_translate_between_id_and_en(): void
    {
        $user = User::factory()->create([
            'role'   => 'super_admin',
            'locale' => 'id',
        ]);

        $idResponse = $this->actingAs($user)->get('/dashboard');
        $idResponse->assertStatus(200)
            ->assertSee('Dashboard Operasional')
            ->assertSee('Total Perusahaan Penyewa');

        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'en']);

        $enResponse = $this->actingAs($user->fresh())->get('/dashboard');
        $enResponse->assertStatus(200)
            ->assertSee('Operational Dashboard')
            ->assertSee('Total Tenant Companies');
    }

    public function test_staff_directory_and_onboarding_strings_translate_between_id_and_en(): void
    {
        $user = User::factory()->create([
            'role'      => 'admin',
            'locale'    => 'id',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/staff')
            ->assertStatus(200)
            ->assertSee('Direktori Staf Aktif')
            ->assertSee('+ Daftarkan Staf Baru');

        $this->actingAs($user)->get('/staff/create')
            ->assertStatus(200)
            ->assertSee('Onboarding & Provisioning Staf Baru')
            ->assertSee('Email Akun Google (Gmail)')
            ->assertSee('Gunakan alamat Gmail aktif staf untuk otentikasi Single Sign-On (SSO).')
            ->assertSee('Simpan & Daftarkan Staf');

        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'en']);
        $freshUser = $user->fresh();

        $this->actingAs($freshUser)->get('/staff')
            ->assertStatus(200)
            ->assertSee('Active Staff Directory')
            ->assertSee('+ Register New Staff');

        $this->actingAs($freshUser)->get('/staff/create')
            ->assertStatus(200)
            ->assertSee('New Staff Onboarding & Provisioning')
            ->assertSee('Google Account Email (Gmail)')
            ->assertSee('Use the staff\'s active Gmail address for Single Sign-On (SSO) authentication.')
            ->assertSee('Save & Register Staff');
    }
}
