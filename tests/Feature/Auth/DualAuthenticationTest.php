<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class DualAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function mockSocialiteUser(
        string $id = 'google-unique-id-123',
        string $email = 'registered@novotel-yia.com',
        string $name = 'Registered User',
        string $avatar = 'https://avatar.url/photo.jpg'
    ): void {
        $abstractUser = Mockery::mock('Laravel\Socialite\Two\User');
        $abstractUser->shouldReceive('getId')->andReturn($id);
        $abstractUser->shouldReceive('getEmail')->andReturn($email);
        $abstractUser->shouldReceive('getName')->andReturn($name);
        $abstractUser->shouldReceive('getAvatar')->andReturn($avatar);

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /**
     * Skenario Uji 1: Login Google berhasil untuk email terdaftar & aktif -> User login, google_id tersimpan, redirect ke dashboard.
     */
    public function test_google_login_succeeds_for_registered_and_active_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Registered User',
            'username' => 'registered_user',
            'email' => 'registered@novotel-yia.com',
            'is_active' => true,
            'google_id' => null,
            'avatar_url' => null,
        ]);

        // Test case-insensitive matching with mixed-case email from Google
        $this->mockSocialiteUser(
            id: 'google-unique-id-123',
            email: '  Registered@Novotel-YIA.com ',
            name: 'Registered User',
            avatar: 'https://avatar.url/photo.jpg'
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();
        $this->assertSame('google-unique-id-123', $user->google_id);
        $this->assertSame('https://avatar.url/photo.jpg', $user->avatar_url);
    }

    /**
     * Skenario Uji 1b: Login Google berhasil untuk akun Gmail pribadi staf (@gmail.com).
     */
    public function test_google_login_succeeds_for_personal_gmail_account(): void
    {
        $user = User::factory()->create([
            'name' => 'Staf Akun Gmail',
            'username' => 'staf_gmail',
            'email' => 'karyawan.hotel@gmail.com',
            'is_active' => true,
            'google_id' => null,
            'avatar_url' => null,
        ]);

        $this->mockSocialiteUser(
            id: 'google-uid-gmail-789',
            email: 'Karyawan.Hotel@gmail.com',
            name: 'Staf Akun Gmail',
            avatar: 'https://avatar.url/gmail-photo.jpg'
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();
        $this->assertSame('google-uid-gmail-789', $user->google_id);
        $this->assertSame('https://avatar.url/gmail-photo.jpg', $user->avatar_url);
    }

    /**
     * Skenario Uji 2: Login Google ditolak untuk email yang belum terdaftar -> User tetap guest, redirect ke login dengan flash error.
     */
    public function test_google_login_is_rejected_for_unregistered_email(): void
    {
        $this->mockSocialiteUser(
            id: 'google-unregistered-999',
            email: 'stranger@outside.com',
            name: 'Stranger User',
            avatar: 'https://avatar.url/stranger.jpg'
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertGuest();
        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'error',
                'Alamat email Google (stranger@outside.com) belum terdaftar di sistem OATCAKE. Hubungi HRD atau Manajer Anda.'
            );
    }

    /**
     * Skenario Uji 3: Login Google ditolak untuk akun terdaftar tetapi is_active = false -> User tetap guest.
     */
    public function test_google_login_is_rejected_for_inactive_user(): void
    {
        User::factory()->create([
            'name' => 'Inactive Staff',
            'username' => 'inactive_staff',
            'email' => 'inactive@novotel-yia.com',
            'is_active' => false,
        ]);

        $this->mockSocialiteUser(
            id: 'google-inactive-456',
            email: 'inactive@novotel-yia.com',
            name: 'Inactive Staff',
            avatar: 'https://avatar.url/inactive.jpg'
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertGuest();
        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'error',
                'Akun Anda sedang dinonaktifkan. Silakan hubungi administrator.'
            );
    }

    /**
     * Skenario Uji 4: Login manual berhasil menggunakan username.
     */
    public function test_manual_login_succeeds_using_username(): void
    {
        $user = User::factory()->create([
            'username' => 'budi_santoso',
            'email' => 'budi.santoso@novotel-yia.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'login' => 'budi_santoso',
            'password' => 'SecretPass123!',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Skenario Uji 5: Login manual berhasil menggunakan email.
     */
    public function test_manual_login_succeeds_using_email(): void
    {
        $user = User::factory()->create([
            'username' => 'siti_aminah',
            'email' => 'siti.aminah@novotel-yia.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'login' => 'siti.aminah@novotel-yia.com',
            'password' => 'SecretPass123!',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Skenario Uji 6: Login manual ditolak jika user terdaftar tetapi is_active = false.
     */
    public function test_manual_login_is_rejected_when_user_is_inactive(): void
    {
        User::factory()->create([
            'username' => 'suspended_user',
            'email' => 'suspended@novotel-yia.com',
            'password' => Hash::make('SecretPass123!'),
            'is_active' => false,
        ]);

        $response = $this->from('/login')->post('/login', [
            'login' => 'suspended_user',
            'password' => 'SecretPass123!',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'login' => 'Akun Anda telah dinonaktifkan. Silakan hubungi administrator.',
            ]);
    }
}
