<?php

namespace Tests\Feature;

use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RemediationBatch3Test extends TestCase
{
    use RefreshDatabase;

    public function test_block_1_dead_routes_are_pruned_and_password_reset_routes_preserved(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertOk();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patch('/preferences')->assertNotFound();
        $this->actingAs($admin)->get('/dashboard/today-schedules')->assertNotFound();
        $this->actingAs($admin)->get('/settings/company/edit')->assertNotFound();
        $this->actingAs($admin)->get('/company/settings')->assertNotFound();
        $this->actingAs($admin)->patch('/settings/company')->assertStatus(405);

        $this->actingAs($admin)->get('/verify-email')->assertNotFound();
        $this->actingAs($admin)->get('/confirm-password')->assertNotFound();

        $this->assertFalse(Route::has('preferences.update'));
        $this->assertFalse(Route::has('dashboard.today-schedules'));
        $this->assertFalse(Route::has('settings.company.edit'));
        $this->assertFalse(Route::has('company.settings'));
        $this->assertFalse(Route::has('company.settings.update'));
        $this->assertFalse(Route::has('verification.notice'));
        $this->assertFalse(Route::has('verification.verify'));
        $this->assertFalse(Route::has('verification.send'));
        $this->assertFalse(Route::has('password.confirm'));

        // Constraint 3: password reset and profile password update routes remain active
        $this->assertTrue(Route::has('password.request'));
        $this->assertTrue(Route::has('password.email'));
        $this->assertTrue(Route::has('password.reset'));
        $this->assertTrue(Route::has('password.store'));
        $this->assertTrue(Route::has('password.update'));
    }

    public function test_block_1_dead_files_methods_css_js_and_npm_deps_are_removed(): void
    {
        $removedFiles = [
            app_path('Http/Controllers/Auth/RegisteredUserController.php'),
            app_path('Http/Controllers/Auth/ConfirmablePasswordController.php'),
            app_path('Http/Controllers/Auth/EmailVerificationNotificationController.php'),
            app_path('Http/Controllers/Auth/EmailVerificationPromptController.php'),
            app_path('Http/Controllers/Auth/VerifyEmailController.php'),
            app_path('Http/Controllers/PreferenceController.php'),
            resource_path('views/welcome.blade.php'),
            resource_path('views/layouts/navigation.blade.php'),
            resource_path('views/company/settings.blade.php'),
            resource_path('views/auth/register.blade.php'),
            resource_path('views/auth/verify-email.blade.php'),
            resource_path('views/auth/confirm-password.blade.php'),
            resource_path('views/profile/partials/delete-user-form.blade.php'),
            resource_path('views/components/application-logo.blade.php'),
            resource_path('views/components/nav-link.blade.php'),
            resource_path('views/components/responsive-nav-link.blade.php'),
            resource_path('views/components/modal.blade.php'),
            resource_path('views/components/danger-button.blade.php'),
            resource_path('views/components/secondary-button.blade.php'),
        ];

        foreach ($removedFiles as $path) {
            $this->assertFalse(File::exists($path), "Dead file still exists: {$path}");
        }

        $this->assertFalse(File::isDirectory(resource_path('views/company')));

        $this->assertFalse(method_exists(DashboardController::class, 'departments'));
        $this->assertFalse(method_exists(DashboardController::class, 'companySettings'));
        $this->assertFalse(method_exists(DashboardController::class, 'todaySchedules'));
        $this->assertFalse(method_exists(CompanySettingsController::class, 'storeTenant'));

        $packageJson = json_decode(File::get(base_path('package.json')), true);
        $allDeps = array_merge($packageJson['devDependencies'] ?? [], $packageJson['dependencies'] ?? []);
        $this->assertArrayNotHasKey('@tailwindcss/vite', $allDeps);
        $this->assertArrayNotHasKey('daisyui', $allDeps);
        $this->assertArrayNotHasKey('sortablejs', $allDeps);

        $appCss = File::get(resource_path('css/app.css'));
        $this->assertStringNotContainsString('--row-h', $appCss);
        $this->assertStringNotContainsString('.no-scrollbar', $appCss);

        $loginBlade = File::get(resource_path('views/auth/login.blade.php'));
        $this->assertStringNotContainsString('alert-error', $loginBlade);

        $appJs = File::get(resource_path('js/app.js'));
        $this->assertStringNotContainsString('mobileOpen', $appJs);
        $this->assertStringNotContainsString('toggleMobile', $appJs);

        $mobileDrawerJs = File::get(resource_path('js/mobile-drawer.js'));
        $this->assertStringNotContainsString('touchmove', $mobileDrawerJs);
    }

    public function test_block_3_indonesian_laravel_validation_pagination_passwords_and_breeze_json_work(): void
    {
        app()->setLocale('id');

        $this->assertSame(
            'Kolom alamat email wajib diisi.',
            __('validation.required', ['attribute' => __('validation.attributes.email')])
        );
        $this->assertStringContainsString('Sebelumnya', __('pagination.previous'));
        $this->assertStringContainsString('Selanjutnya', __('pagination.next'));
        $this->assertSame(
            'Kami telah mengirimkan tautan atur ulang kata sandi ke alamat email Anda.',
            __('passwords.sent')
        );

        $this->assertSame('Profil', __('Profile'));
        $this->assertSame('Simpan', __('Save'));
        $this->assertSame('Tersimpan.', __('Saved.'));
        $this->assertSame('Perbarui Kata Sandi', __('Update Password'));

        $user = User::factory()->create();

        $profileResponse = $this->actingAs($user)
            ->withSession(['locale' => 'id'])
            ->get('/profile');

        $profileResponse->assertOk()
            ->assertSee('Profil')
            ->assertSee('Informasi Profil')
            ->assertSee('Perbarui Kata Sandi')
            ->assertSee('Simpan')
            ->assertDontSee('send-verification');
    }

    public function test_block_4_field_ui_ux_polish_error_banners_timeout_icons_sidebar_and_clipboard(): void
    {
        $company = Company::create([
            'name' => 'Novotel YIA',
            'code' => 'NOVOTEL-YIA',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
        ]);

        $admin = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        // Sidebar and mobile drawer highlight Company Settings on canonical settings.company route
        $settingsHtml = $this->actingAs($admin)->get(route('settings.company'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '#href="[^"]*/settings/company"[^>]*class="[^"]*bg-accent text-accent-fg#s',
            $settingsHtml
        );

        // Dashboard modals include error banners inside <dialog> and 30s AbortController timeout
        $dashboardView = File::get(resource_path('views/dashboard.blade.php'));
        foreach (['clock-in-selfie-modal', 'clock-out-selfie-modal', 'dashboard-outside-attendance-modal'] as $modalName) {
            $pos = strpos($dashboardView, "name=\"{$modalName}\"");
            $this->assertNotFalse($pos, "Modal {$modalName} not found");
            $endPos = strpos($dashboardView, '</x-ui.modal>', $pos);
            $this->assertNotFalse($endPos, "Closing tag for {$modalName} not found");
            $modalSlice = substr($dashboardView, $pos, $endPos - $pos);
            $this->assertStringContainsString('feedbackMessage && !feedbackSuccess', $modalSlice);
            $this->assertStringContainsString('gpsErrorMessage', $modalSlice);
        }
        $this->assertStringContainsString('fetchWithTimeout(url, options = {}, timeoutMs = 30000)', $dashboardView);
        $this->assertStringContainsString('new AbortController()', $dashboardView);
        $this->assertStringContainsString("error.name === 'AbortError'", $dashboardView);
        $this->assertStringNotContainsString('error.message || this.i18n.errNetwork', $dashboardView);

        // Snapshot viewer uses SVG instead of Unicode right arrow glyph
        $snapshotViewer = File::get(resource_path('views/components/pwa/snapshot-viewer.blade.php'));
        $this->assertStringNotContainsString('→', $snapshotViewer);
        $this->assertStringContainsString('M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3', $snapshotViewer);

        // Roster action bar uses outline Heroicons (no 20x20 solid Heroicons)
        $rosterView = File::get(resource_path('views/roster/index.blade.php'));
        $this->assertStringNotContainsString('viewBox="0 0 20 20"', $rosterView);

        // PWA clipboard fallback mounts textarea inside open <dialog> to bypass top-layer focus trap
        $pwaJs = File::get(resource_path('js/pwa.js'));
        $this->assertStringContainsString("document.querySelectorAll('dialog[open]')", $pwaJs);
        $this->assertStringContainsString('mountTarget.appendChild(textarea)', $pwaJs);
    }
}
