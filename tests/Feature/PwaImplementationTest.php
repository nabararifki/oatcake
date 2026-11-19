<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PwaImplementationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Validitas Web App Manifest & keberadaan berkas ikon PNG fisik.
     */
    public function test_scenario_1_web_app_manifest_and_pwa_icons_are_valid(): void
    {
        $manifestPath = public_path('manifest.webmanifest');
        $this->assertFileExists($manifestPath);

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);

        $this->assertIsArray($manifest);
        $this->assertSame('/', $manifest['id'] ?? null);
        $this->assertSame('OATCAKE', $manifest['short_name'] ?? null);
        $this->assertSame('standalone', $manifest['display'] ?? null);
        $this->assertSame('/dashboard?source=pwa', $manifest['start_url'] ?? null);
        $this->assertSame('/', $manifest['scope'] ?? null);
        $this->assertSame('#090d16', $manifest['background_color'] ?? null);
        $this->assertSame('#0f172a', $manifest['theme_color'] ?? null);

        $this->assertIsArray($manifest['shortcuts'] ?? null);
        $this->assertCount(3, $manifest['shortcuts']);
        $shortcutUrls = array_column($manifest['shortcuts'], 'url');
        $this->assertSame(['/dashboard', '/roster', '/leave'], $shortcutUrls);

        // Endpoint /manifest.webmanifest harus melayani Content-Type application/manifest+json
        $response = $this->get('/manifest.webmanifest');
        $response->assertOk();
        $this->assertStringContainsString('application/manifest+json', (string) $response->headers->get('Content-Type'));

        $expectedIcons = [
            'icon-192x192.png' => [192, 192],
            'icon-512x512.png' => [512, 512],
            'icon-maskable-512x512.png' => [512, 512],
            'apple-touch-icon.png' => [180, 180],
        ];

        foreach ($expectedIcons as $filename => [$expectedWidth, $expectedHeight]) {
            $iconPath = public_path("icons/{$filename}");
            $this->assertFileExists($iconPath, "PWA icon {$filename} is missing.");
            $this->assertGreaterThan(0, filesize($iconPath), "PWA icon {$filename} must not be empty.");

            $imageInfo = @getimagesize($iconPath);
            $this->assertIsArray($imageInfo, "PWA icon {$filename} is not a valid image.");
            $this->assertSame($expectedWidth, $imageInfo[0]);
            $this->assertSame($expectedHeight, $imageInfo[1]);
            $this->assertSame('image/png', $imageInfo['mime']);
        }
    }

    /**
     * Skenario 2: Ketersediaan & aturan keamanan Service Worker (public/sw.js).
     */
    public function test_scenario_2_service_worker_enforces_cache_tiers_and_security_exclusions(): void
    {
        $swPath = public_path('sw.js');
        $this->assertFileExists($swPath);

        $swContent = File::get($swPath);

        // Penamaan cache berversi
        $this->assertStringContainsString('oatcake-static-v2', $swContent);
        $this->assertStringContainsString('oatcake-pages-v2', $swContent);
        $this->assertStringContainsString('oatcake-data-v2', $swContent);

        // Siklus hidup install & activate
        $this->assertStringContainsString('skipWaiting', $swContent);
        $this->assertStringContainsString('clients.claim', $swContent);

        // Strict Network-Only untuk mutasi non-GET dan rute privat / auth / media bukti / admin
        $this->assertStringContainsString("request.method !== 'GET'", $swContent);
        $this->assertStringContainsString('/login', $swContent);
        $this->assertStringContainsString('/logout', $swContent);
        $this->assertStringContainsString('/proof', $swContent);
        $this->assertStringContainsString('/attachment', $swContent);
        $this->assertStringContainsString('/smart-analysis', $swContent);
        $this->assertStringContainsString('/leave/ledger/', $swContent);
        $this->assertStringContainsString('/api', $swContent);
        $this->assertStringContainsString('/sanctum', $swContent);
        $this->assertStringContainsString('/audit-logs', $swContent);
        $this->assertStringContainsString('/attendance/verifications', $swContent);
        $this->assertStringContainsString('/settings', $swContent);
        $this->assertStringContainsString('/profile', $swContent);

        // Pembersihan cache saat logout
        $this->assertStringContainsString('CLEAR_USER_CACHE', $swContent);
        $this->assertStringContainsString('/offline', $swContent);
        $this->assertStringContainsString('/pwa/offline-snapshot', $swContent);
    }

    /**
     * Skenario 3: Aksesibilitas halaman fallback /offline untuk guest maupun pengguna login.
     */
    public function test_scenario_3_offline_fallback_page_is_accessible_for_guests_and_authenticated_users(): void
    {
        // Guest
        $guestResponse = $this->get('/offline');
        $guestResponse->assertOk();
        $guestResponse->assertSee('o-inset-stage', false);
        $guestResponse->assertSee('$store.pwa.snapshot', false);
        $guestResponse->assertSee('pwaManager', false);
        $guestResponse->assertSee(__('pwa.offline_page_title'));
        $guestResponse->assertSee(__('pwa.snapshot_card_title'));
        $this->assertStringContainsString('oatcake_offline_snapshot', File::get(resource_path('js/pwa.js')));

        // Authenticated user
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        $authResponse = $this->actingAs($user)->get('/offline');
        $authResponse->assertOk();
        $authResponse->assertSee(__('pwa.btn_reload_dashboard'));
    }

    /**
     * Skenario 4: Akurasi & isolasi multi-tenant pada endpoint /pwa/offline-snapshot.
     */
    public function test_scenario_4_offline_snapshot_is_accurate_and_strictly_isolated_per_staff_and_tenant(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 30, 0, 'Asia/Jakarta'));

        // Guest ditolak / diarahkan ke /login
        $this->get('/pwa/offline-snapshot')->assertRedirect('/login');
        $this->getJson('/pwa/offline-snapshot')->assertUnauthorized();

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MO')->firstOrFail();
        $shiftAF = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'AF')->firstOrFail();

        $staffUserA = User::factory()->create([
            'company_id' => $hotelA->id,
            'name' => 'Dina Pratama',
            'username' => 'dina_fo',
            'email' => 'dina.fo@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmpA = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $staffUserA->id,
            'department_id' => $foDeptA->id,
            'position' => 'Front Desk Agent',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // Rekan kerja di perusahaan yang sama (jadwalnya tidak boleh bocor ke Dina)
        $peerUserA = User::factory()->create([
            'company_id' => $hotelA->id,
            'name' => 'Budi Santoso',
            'role' => 'user',
            'is_active' => true,
        ]);
        $peerEmpA = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $peerUserA->id,
            'department_id' => $foDeptA->id,
            'position' => 'Bellboy',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // Tenant B (perusahaan lain - tidak boleh bocor)
        $hotelB = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Yogyakarta International Airport',
            'latitude' => '-7.9010000',
            'longitude' => '110.0550000',
            'geofence_radius_meters' => 100,
            'accent_color' => '#059669',
            'is_active' => true,
        ]);
        $deptB = Department::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'FO',
            'name' => 'Front Office Ibis',
            'is_operational' => true,
            'min_staff_per_shift' => 1,
            'is_executive_oversight' => false,
        ]);
        $userB = User::factory()->create([
            'company_id' => $hotelB->id,
            'name' => 'Siti Tenant B',
            'role' => 'user',
            'is_active' => true,
        ]);
        $empB = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'user_id' => $userB->id,
            'department_id' => $deptB->id,
            'position' => 'Receptionist',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);
        $shiftB = Shift::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'IBIS-M',
            'name' => 'Ibis Morning',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'cross_day' => false,
            'color_badge' => '#10B981',
        ]);

        // Saldo AL = 9.0, DP = 2.0 untuk staffEmpA
        $ledgerService = app(LeaveLedgerService::class);
        $ledgerService->recordAccrual($staffEmpA, 'AL', 9.0, ['accrual_year' => 2026]);
        $ledgerService->recordCredit($staffEmpA, 'DP', 2.0);

        $today = Carbon::today('Asia/Jakarta');

        // Jadwal published milik Dina pada H+0 dan H+5
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => $today->toDateString(),
            'status' => 'published',
        ]);
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => null,
            'schedule_date' => $today->copy()->addDays(5)->toDateString(),
            'status' => 'published',
        ]);

        // Jadwal DRAFT milik Dina (tidak boleh masuk snapshot)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => $shiftAF->id,
            'schedule_date' => $today->copy()->addDays(2)->toDateString(),
            'status' => 'draft',
        ]);

        // Jadwal di luar jendela 14 hari (H+15 - tidak boleh masuk snapshot)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmpA->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => $today->copy()->addDays(15)->toDateString(),
            'status' => 'published',
        ]);

        // Jadwal rekan kerja & tenant B (tidak boleh masuk snapshot Dina)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $peerEmpA->id,
            'shift_id' => $shiftAF->id,
            'schedule_date' => $today->copy()->addDay()->toDateString(),
            'status' => 'published',
        ]);
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'employee_id' => $empB->id,
            'shift_id' => $shiftB->id,
            'schedule_date' => $today->toDateString(),
            'status' => 'published',
        ]);

        // Hari libur perusahaan pada H+5
        PublicHoliday::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'holiday_date' => $today->copy()->addDays(5)->toDateString(),
            'name' => 'Hari Libur Properti A',
            'is_national' => true,
        ]);

        $response = $this->actingAs($staffUserA)->getJson('/pwa/offline-snapshot');
        $response->assertOk();

        $payload = $response->json();

        // Strict Zero NIK check
        $this->assertStringNotContainsString('"nik"', json_encode($payload));

        $this->assertSame('Dina Pratama', $payload['user']['name']);
        $this->assertSame($hotelA->name, $payload['user']['company_name']);
        $this->assertSame('Front Office', $payload['user']['department_name']);

        $this->assertSame(9, $payload['leave_balances']['AL']);
        $this->assertSame(2, $payload['leave_balances']['DP']);

        $this->assertCount(2, $payload['upcoming_schedules']);
        $this->assertSame($today->toDateString(), $payload['upcoming_schedules'][0]['date']);
        $this->assertSame($shiftMO->code, $payload['upcoming_schedules'][0]['shift_code']);
        $this->assertFalse($payload['upcoming_schedules'][0]['is_off']);

        $this->assertSame($today->copy()->addDays(5)->toDateString(), $payload['upcoming_schedules'][1]['date']);
        $this->assertSame('OFF', $payload['upcoming_schedules'][1]['shift_code']);
        $this->assertTrue($payload['upcoming_schedules'][1]['is_off']);
        $this->assertTrue($payload['upcoming_schedules'][1]['is_holiday']);
        $this->assertSame('Hari Libur Properti A', $payload['upcoming_schedules'][1]['holiday_name']);

        // Pastikan tidak ada jadwal draft atau tenant B
        $dates = array_column($payload['upcoming_schedules'], 'date');
        $this->assertNotContains($today->copy()->addDays(2)->toDateString(), $dates);
        $this->assertNotContains($today->copy()->addDays(15)->toDateString(), $dates);
        $this->assertNotContains('IBIS-M', array_column($payload['upcoming_schedules'], 'shift_code'));
    }

    /**
     * Skenario 5: Integrasi meta tag PWA & komponen instalasi/banner offline pada layout utama.
     */
    public function test_scenario_5_main_layouts_render_pwa_meta_tags_and_manager_components(): void
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();

        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'department_id' => $foDept->id,
            'position' => 'Receptionist',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // Guest layout (/login) juga wajib menyertakan meta tag PWA
        $this->get('/login')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('<meta name="theme-color" content="#0f172a">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false);

        foreach (['/dashboard', '/roster', '/leave'] as $route) {
            $resp = $this->actingAs($user)->get($route);
            $resp->assertOk();
            $resp->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
            $resp->assertSee('<meta name="theme-color" content="#0f172a">', false);
            $resp->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false);
            $resp->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false);
            $resp->assertSee('pwaManager()', false);
            $resp->assertSee('$store.pwa.installApp()', false);
            $resp->assertSee('!$store.pwa.isStandalone', false);
            $resp->assertSee('oatcakeLogoutSubmit', false);
            $resp->assertSee(__('pwa.offline_banner_title'));
            $resp->assertSee(__('pwa.install_app'));
        }

        // Verifikasi modal panduan instalasi PWA memuat fallback HTTP non-HTTPS & helper salin clipboard
        $dashboardResp = $this->actingAs($user)->get('/dashboard');
        $dashboardResp->assertOk();
        $dashboardResp->assertSee('pwa-install-guide-modal', false);
        $dashboardResp->assertSee('chrome://flags/#unsafely-treat-insecure-origin-as-secure', false);
        $dashboardResp->assertSee('copyToClipboard', false);
        $dashboardResp->assertSee(__('pwa.http_fallback_title'));
        $dashboardResp->assertSee(__('pwa.http_method_1_title'));
        $dashboardResp->assertSee(__('pwa.http_method_2_title'));
        $dashboardResp->assertSee(__('pwa.install_guide_android_title'));
        $dashboardResp->assertSee(__('pwa.install_guide_ios_desc'));

        $pwaJs = File::get(resource_path('js/pwa.js'));
        foreach ([
            'isSecureContext',
            'swSupported',
            'currentOrigin',
            'chromeFlagsUrl',
            'isAndroid',
            'isIos',
            'copiedTarget',
            'copyToClipboard',
            "document.execCommand('copy')",
            'triggerInstall',
            'promptInstall',
        ] as $expectedToken) {
            $this->assertStringContainsString($expectedToken, $pwaJs);
        }
    }

    /**
     * Skenario 6: Proteksi tombol presensi Clock-In / Clock-Out di dashboard saat offline.
     */
    public function test_scenario_6_dashboard_attendance_widget_locks_clock_actions_when_offline(): void
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'FO')->firstOrFail();

        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'department_id' => $foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();
        $response->assertSee('dashboard-attendance-widget', false);
        $response->assertSee(':disabled="submitting || !isOnline || isClockInBlockedByUnscheduledPolicy"', false);
        $response->assertSee('!this.isOnline || !navigator.onLine', false);
        $response->assertSee(__('pwa.attendance_offline_warning'));
    }

    /**
     * Skenario 7: Kamus dwibahasa pwa.php terdaftar dan memiliki paritas ketat ID/EN.
     */
    public function test_scenario_7_pwa_language_dictionaries_exist_and_match_strict_parity(): void
    {
        $idFile = lang_path('id/pwa.php');
        $enFile = lang_path('en/pwa.php');

        $this->assertFileExists($idFile);
        $this->assertFileExists($enFile);

        $idKeys = array_keys(require $idFile);
        $enKeys = array_keys(require $enFile);

        sort($idKeys);
        sort($enKeys);

        $this->assertSame($idKeys, $enKeys);
        $this->assertNotEmpty($idKeys);
    }
}
