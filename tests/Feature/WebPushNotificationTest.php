<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PushSubscription;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkSchedule;
use App\Services\LeaveApprovalService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use App\Services\WebPushService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Generate a valid 65-byte uncompressed P-256 public key & 16-byte auth secret in Base64URL.
     *
     * @return array{p256dh: string, auth: string}
     */
    private function generateFakeBrowserPushKeys(): array
    {
        $ec = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($ec)['ec'];
        $rawPub = "\x04"
            .str_pad($details['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['y'], 32, "\x00", STR_PAD_LEFT);

        return [
            'p256dh' => WebPushService::base64UrlEncode($rawPub),
            'auth' => WebPushService::base64UrlEncode(random_bytes(16)),
        ];
    }

    /**
     * Skenario 1: Registrasi, pembaruan idempoten & penghapusan langganan Web Push.
     */
    public function test_scenario_1_push_subscription_store_update_and_destroy_lifecycle(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        $keys1 = $this->generateFakeBrowserPushKeys();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/sample-device-token-123';

        $createResp = $this->actingAs($user)->postJson('/notifications/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => $keys1,
            'content_encoding' => 'aes128gcm',
        ]);

        $createResp->assertCreated()->assertJson(['success' => true]);

        $expectedHash = hash('sha256', $endpoint);
        $this->assertDatabaseHas('push_subscriptions', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'endpoint_hash' => $expectedHash,
            'public_key' => $keys1['p256dh'],
        ]);

        // Pembaruan kunci pada endpoint yang sama harus idempoten (tidak membuat duplikat)
        $keys2 = $this->generateFakeBrowserPushKeys();
        $updateResp = $this->actingAs($user)->postJson('/notifications/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => $keys2,
        ]);

        $updateResp->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->where('endpoint_hash', $expectedHash)->count());
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint_hash' => $expectedHash,
            'public_key' => $keys2['p256dh'],
        ]);

        // Penghapusan langganan
        $deleteResp = $this->actingAs($user)->deleteJson('/notifications/push-subscriptions', [
            'endpoint' => $endpoint,
        ]);

        $deleteResp->assertOk()->assertJson(['success' => true, 'deleted' => 1]);
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint_hash' => $expectedHash,
        ]);
    }

    /**
     * Skenario 2: Generator kunci VAPID & enkripsi pengiriman Web Push (RFC 8291 & 8292).
     */
    public function test_scenario_2_vapid_key_generation_and_encrypted_web_push_dispatch(): void
    {
        $this->artisan('webpush:vapid')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertExitCode(0);

        $webPushService = app(WebPushService::class);
        $publicKeyB64 = $webPushService->getVapidPublicKey();
        $rawPublic = WebPushService::base64UrlDecode($publicKeyB64);

        $this->assertSame(65, strlen($rawPublic));
        $this->assertSame("\x04", $rawPublic[0]);

        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        $browserKeys = $this->generateFakeBrowserPushKeys();
        $endpoint = 'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint';

        PushSubscription::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => $browserKeys['p256dh'],
            'auth_token' => $browserKeys['auth'],
            'content_encoding' => 'aes128gcm',
        ]);

        Http::fake([
            'https://updates.push.services.mozilla.com/*' => Http::response('', 201),
        ]);

        $notification = $webPushService->notifyUser(
            $user,
            'push_test',
            'Judul Uji Push',
            'Isi pesan terenkripsi aes128gcm.',
            '/dashboard',
            ['source' => 'unit_check', 'nik' => 'SHOULD_BE_STRIPPED']
        );

        $this->assertNotNull($notification->pushed_at);
        $this->assertArrayNotHasKey('nik', $notification->meta ?? []);

        Http::assertSent(function (HttpClientRequest $request) use ($endpoint, $publicKeyB64): bool {
            $authHeader = $request->header('Authorization')[0] ?? '';
            $encodingHeader = $request->header('Content-Encoding')[0] ?? '';
            $body = $request->body();

            return $request->url() === $endpoint
                && str_starts_with($authHeader, 'vapid t=')
                && str_contains($authHeader, ", k={$publicKeyB64}")
                && $encodingHeader === 'aes128gcm'
                && strlen($body) > 86;
        });
    }

    /**
     * Skenario 3: Pembersihan otomatis endpoint kedaluwarsa (HTTP 410 Gone) & fault-tolerance jaringan.
     */
    public function test_scenario_3_dead_endpoint_410_gone_is_pruned_and_network_failure_is_graceful(): void
    {
        $this->seed([CompanySeeder::class]);
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        $keysA = $this->generateFakeBrowserPushKeys();
        $keysB = $this->generateFakeBrowserPushKeys();
        $goneEndpoint = 'https://fcm.googleapis.com/fcm/send/expired-endpoint-410';
        $errorEndpoint = 'https://fcm.googleapis.com/fcm/send/timeout-endpoint-500';

        PushSubscription::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'endpoint' => $goneEndpoint,
            'endpoint_hash' => hash('sha256', $goneEndpoint),
            'public_key' => $keysA['p256dh'],
            'auth_token' => $keysA['auth'],
            'content_encoding' => 'aes128gcm',
        ]);

        PushSubscription::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'endpoint' => $errorEndpoint,
            'endpoint_hash' => hash('sha256', $errorEndpoint),
            'public_key' => $keysB['p256dh'],
            'auth_token' => $keysB['auth'],
            'content_encoding' => 'aes128gcm',
        ]);

        Http::fake([
            $goneEndpoint => Http::response('Gone', 410),
            $errorEndpoint => Http::response('Server Error', 500),
        ]);

        $webPushService = app(WebPushService::class);
        $notification = $webPushService->notifyUser($user, 'push_test', 'Uji', 'Pesan uji.');

        $this->assertNull($notification->pushed_at);
        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint_hash' => hash('sha256', $goneEndpoint),
        ]);
        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint_hash' => hash('sha256', $errorEndpoint),
        ]);
    }

    /**
     * Skenario 4: Pemicu real-time alur cuti berjenjang (Submit -> Tier 1 -> Tier 2 & Reject) dengan isolasi departemen/tenant.
     */
    public function test_scenario_4_leave_workflow_triggers_scoped_real_time_notifications(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 9, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $hkDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'HK')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MO')->firstOrFail();
        $alType = LeaveType::findByCode('AL', $hotelA->id);

        $foManager = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foManager->id,
            'department_id' => $foDeptA->id,
            'position' => 'FO Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $hkManager = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $hkManager->id,
            'department_id' => $hkDeptA->id,
            'position' => 'HK Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $hrAdmin = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'admin', 'is_active' => true]);

        $foStaffUser = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        $foStaffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $foDeptA->id,
            'position' => 'GSA',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // Tenant B Manager (tidak boleh pernah menerima notifikasi Hotel A)
        $hotelB = Company::create([
            'code' => 'MERCURE-SBY',
            'name' => 'Mercure Surabaya',
            'latitude' => '-7.2856000',
            'longitude' => '112.7421000',
            'geofence_radius_meters' => 150,
            'accent_color' => '#7C3AED',
            'is_active' => true,
        ]);
        $deptB = Department::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'code' => 'FO',
            'name' => 'Front Office B',
            'is_operational' => true,
            'min_staff_per_shift' => 1,
            'is_executive_oversight' => false,
        ]);
        $managerB = User::factory()->create(['company_id' => $hotelB->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelB->id,
            'user_id' => $managerB->id,
            'department_id' => $deptB->id,
            'position' => 'FO Manager B',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        app(LeaveLedgerService::class)->recordAccrual($foStaffEmp, 'AL', 12.0, ['accrual_year' => 2026]);

        for ($d = 1; $d <= 6; $d++) {
            WorkSchedule::withoutGlobalScopes()->create([
                'company_id' => $hotelA->id,
                'employee_id' => $foStaffEmp->id,
                'shift_id' => $shiftMO->id,
                'schedule_date' => Carbon::today('Asia/Jakarta')->addDays($d)->toDateString(),
                'status' => 'published',
            ]);
        }

        $leaveRequestService = app(LeaveRequestService::class);
        $leaveApprovalService = app(LeaveApprovalService::class);

        // 1. Staf mengajukan cuti -> Manajer FO menerima leave_submitted, Manajer HK & Tenant B tidak menerima
        $leave1 = $leaveRequestService->submitRequest($foStaffEmp, [
            'leave_type_id' => $alType->id,
            'start_date' => Carbon::today('Asia/Jakarta')->addDay()->toDateString(),
            'end_date' => Carbon::today('Asia/Jakarta')->addDays(2)->toDateString(),
            'reason' => 'Keperluan keluarga mendesak',
        ], null, $foStaffUser);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foManager->id,
            'category' => 'leave_submitted',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $hkManager->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $managerB->id]);

        // 2. Tier 1 approve -> HR Admin & Staf menerima leave_tier1_approved
        $leave1 = $leaveApprovalService->approveTier1($leave1, $foManager, 'Disetujui di departemen');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $hrAdmin->id,
            'category' => 'leave_tier1_approved',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaffUser->id,
            'category' => 'leave_tier1_approved',
        ]);

        // 3. Tier 2 approve -> Staf menerima leave_approved
        $leaveApprovalService->approveTier2($leave1, $hrAdmin, 'Disahkan HR');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaffUser->id,
            'category' => 'leave_approved',
        ]);

        // 4. Pengajuan kedua ditolak -> Staf menerima leave_rejected
        $leave2 = $leaveRequestService->submitRequest($foStaffEmp, [
            'leave_type_id' => $alType->id,
            'start_date' => Carbon::today('Asia/Jakarta')->addDays(4)->toDateString(),
            'end_date' => Carbon::today('Asia/Jakarta')->addDays(5)->toDateString(),
            'reason' => 'Liburan tambahan',
        ], null, $foStaffUser);

        $leaveApprovalService->reject($leave2, $foManager, 'Okupansi kamar penuh pada tanggal tersebut');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaffUser->id,
            'category' => 'leave_rejected',
        ]);
    }

    /**
     * Skenario 5: Pemicu real-time presensi dinas luar & verifikasi manajer.
     */
    public function test_scenario_5_outside_attendance_and_verification_trigger_real_time_notifications(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 8, 15, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $hkDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'HK')->firstOrFail();

        $foManager = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foManager->id,
            'department_id' => $foDeptA->id,
            'position' => 'FO Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $hkManager = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $hkManager->id,
            'department_id' => $hkDeptA->id,
            'position' => 'HK Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $foStaffUser = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $foDeptA->id,
            'position' => 'Sales Coordinator',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        $photo = UploadedFile::fake()->image('outside_selfie.jpg', 640, 480);

        $clockInResp = $this->actingAs($foStaffUser)->postJson('/attendance/outside-clock-in', [
            'latitude' => -7.795580,
            'longitude' => 110.369490,
            'outside_category' => 'client_visit',
            'outside_location_name' => 'Balai Kota Yogyakarta',
            'notes' => 'Koordinasi acara korporat tamu VIP',
            'photo' => $photo,
        ]);

        $clockInResp->assertCreated();
        $attendanceId = $clockInResp->json('data.id');

        // Manajer FO menerima notifikasi; Manajer HK tidak
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foManager->id,
            'category' => 'attendance_outside_pending',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $hkManager->id,
            'category' => 'attendance_outside_pending',
        ]);

        // Manajer FO menyetujui -> Staf menerima attendance_verified
        $attendance = Attendance::withoutGlobalScopes()->findOrFail($attendanceId);
        $approveResp = $this->actingAs($foManager)->postJson("/attendance/verifications/{$attendance->uuid}/approve");
        $approveResp->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $foStaffUser->id,
            'category' => 'attendance_verified',
        ]);
    }

    /**
     * Skenario 6: Pemicu real-time penerbitan jadwal kerja (POST /roster/publish).
     */
    public function test_scenario_6_roster_publish_sends_unique_notification_per_scheduled_staff(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 10, 0, 0, 'Asia/Jakarta'));

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDeptA = Department::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'FO')->firstOrFail();
        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotelA->id)->where('code', 'MO')->firstOrFail();

        $foManager = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'manager', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $foManager->id,
            'department_id' => $foDeptA->id,
            'position' => 'FO Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $staffUser = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        $staffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $staffUser->id,
            'department_id' => $foDeptA->id,
            'position' => 'GSA',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        $unscheduledUser = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        Employee::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'user_id' => $unscheduledUser->id,
            'department_id' => $foDeptA->id,
            'position' => 'Concierge',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        // Buat 2 jadwal draft di bulan berjalan untuk staffEmp
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmp->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => '2026-11-20',
            'status' => 'draft',
        ]);
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $hotelA->id,
            'employee_id' => $staffEmp->id,
            'shift_id' => $shiftMO->id,
            'schedule_date' => '2026-11-21',
            'status' => 'draft',
        ]);

        $publishResp = $this->actingAs($foManager)->postJson('/roster/publish', [
            'month' => 11,
            'year' => 2026,
            'department_id' => $foDeptA->id,
        ]);

        $publishResp->assertOk();

        // Hanya 1 notifikasi roster_published untuk staffUser (tidak ganda untuk 2 shift)
        $this->assertSame(
            1,
            UserNotification::withoutGlobalScopes()
                ->where('user_id', $staffUser->id)
                ->where('category', 'roster_published')
                ->count()
        );

        // Staf yang tidak memiliki jadwal draft diterbitkan tidak menerima notifikasi
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $unscheduledUser->id,
            'category' => 'roster_published',
        ]);
    }

    /**
     * Skenario 7: Manajemen status baca (markRead & markAllRead), test-push & isolasi 403 lintas pengguna/tenant.
     */
    public function test_scenario_7_notification_center_endpoints_and_cross_user_403_isolation(): void
    {
        $this->seed([CompanySeeder::class]);
        $hotelA = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $hotelB = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis YIA',
            'latitude' => '-7.9010000',
            'longitude' => '110.0550000',
            'geofence_radius_meters' => 100,
            'accent_color' => '#059669',
            'is_active' => true,
        ]);

        $userA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        $peerA = User::factory()->create(['company_id' => $hotelA->id, 'role' => 'user', 'is_active' => true]);
        $userB = User::factory()->create(['company_id' => $hotelB->id, 'role' => 'user', 'is_active' => true]);

        // Uji kirim notifikasi mandiri
        $testPushResp = $this->actingAs($userA)->postJson('/notifications/test-push');
        $testPushResp->assertOk()->assertJson(['success' => true]);

        $notifA = UserNotification::withoutGlobalScopes()->where('user_id', $userA->id)->firstOrFail();

        $indexResp = $this->actingAs($userA)->getJson('/notifications');
        $indexResp->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications');
        $this->assertNotEmpty($indexResp->json('vapid_public_key'));

        // Pengguna lain di perusahaan sama atau tenant berbeda ditolak 403 saat mencoba markRead
        $this->actingAs($peerA)->postJson("/notifications/{$notifA->uuid}/read")->assertForbidden();
        $this->actingAs($userB)->postJson("/notifications/{$notifA->uuid}/read")->assertForbidden();

        // Pemilik sah menandai satu notifikasi dibaca
        $this->actingAs($userA)->postJson("/notifications/{$notifA->uuid}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($notifA->fresh()->read_at);

        // Buat 2 notifikasi baru untuk userA dan 1 untuk peerA lalu uji markAllRead
        app(WebPushService::class)->notifyUser($userA, 'push_test', 'N1', 'Body 1');
        app(WebPushService::class)->notifyUser($userA, 'push_test', 'N2', 'Body 2');
        $peerNotif = app(WebPushService::class)->notifyUser($peerA, 'push_test', 'Peer', 'Peer body');

        $this->actingAs($userA)->postJson('/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, UserNotification::withoutGlobalScopes()->where('user_id', $userA->id)->unread()->count());
        $this->assertNull($peerNotif->fresh()->read_at);
    }

    /**
     * Skenario 8: Integrasi listener push & notificationclick di public/sw.js serta higienitas logout.
     */
    public function test_scenario_8_service_worker_and_frontend_integrate_push_listeners_and_logout_cleanup(): void
    {
        $swContent = File::get(public_path('sw.js'));
        $pwaJsContent = File::get(resource_path('js/pwa.js'));

        $this->assertStringContainsString("'/notifications'", $swContent);
        $this->assertStringContainsString("addEventListener('push'", $swContent);
        $this->assertStringContainsString("addEventListener('notificationclick'", $swContent);
        $this->assertStringContainsString('showNotification', $swContent);
        $this->assertStringContainsString('openWindow', $swContent);

        $this->assertStringContainsString('pushManager.subscribe', $pwaJsContent);
        $this->assertStringContainsString('unsubscribeActivePushOnLogout', $pwaJsContent);
        $this->assertStringContainsString('applicationServerKey', $pwaJsContent);
    }
}
