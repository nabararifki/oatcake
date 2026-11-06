<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $smDept;
    protected Department $foDept;
    protected Employee $smManager;
    protected Employee $smStaff;
    protected Employee $foStaff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->smDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'SM')
            ->firstOrFail();

        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $smManagerUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Hendra Wijaya',
            'username' => 'hendra.sm',
            'email' => 'hendra.sm@novotelyia.com',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->smManager = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $smManagerUser->id,
            'department_id' => $this->smDept->id,
            'position' => 'Director of Sales',
            'join_date' => '2024-06-01',
            'status' => 'active',
        ]);

        $smStaffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina.sm',
            'email' => 'rina.sm@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->smStaff = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $smStaffUser->id,
            'department_id' => $this->smDept->id,
            'manager_id' => $this->smManager->id,
            'position' => 'Sales Executive',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $foStaffUser = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.fo',
            'email' => 'budi.fo@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->foStaff = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $this->foDept->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Manager berhasil menyetujui presensi staf di departemennya;
     * status beralih menjadi on_time (atau late jika ada late_minutes), dan kolom audit (verified_by, verified_at) terisi.
     */
    public function test_scenario_1_manager_approves_own_department_staff_attendance_and_records_audit_trail(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 10, 0, 0, 'Asia/Jakarta'));

        // A. Kasus tepat waktu (late_minutes = 0 -> status menjadi on_time)
        $onTimeOutside = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->smStaff->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 7, 5, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.7955800,
            'clock_in_longitude' => 110.3694900,
            'clock_in_distance_meters' => 4200,
            'status' => 'pending_verification',
            'late_minutes' => 0,
            'is_outside_geofence' => true,
            'outside_category' => 'client_visit',
            'outside_location_name' => 'Kantor Pemda Kulon Progo',
            'proof_photo_path' => "attendance_proofs/{$this->company->id}/2026-11/sample1.webp",
            'notes' => 'Kunjungan kerja sama corporate rate.',
        ]);

        // Pastikan halaman konsol verifikasi menampilkan antrean staf departemen SM
        $consoleResponse = $this->actingAs($this->smManager->user)->get(route('attendance.verifications.index'));
        $consoleResponse->assertOk()
            ->assertSee('Rina Kusuma')
            ->assertSee('Kantor Pemda Kulon Progo')
            ->assertSee('attendance-map', escape: false);

        $approveResponse = $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.approve', $onTimeOutside));

        $approveResponse->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'on_time',
                    'verified_by' => $this->smManager->user->id,
                ],
            ]);

        $onTimeOutside->refresh();
        $this->assertSame('on_time', $onTimeOutside->status);
        $this->assertTrue($onTimeOutside->isApproved());
        $this->assertSame($this->smManager->user->id, $onTimeOutside->verified_by);
        $this->assertNotNull($onTimeOutside->verified_at);

        // B. Kasus terlambat (late_minutes = 25 -> status menjadi late saat disetujui)
        $lateOutside = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->smStaff->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 7, 25, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.7955800,
            'clock_in_longitude' => 110.3694900,
            'clock_in_distance_meters' => 4200,
            'status' => 'pending_verification',
            'late_minutes' => 25,
            'is_outside_geofence' => true,
            'outside_category' => 'event',
            'outside_location_name' => 'Jogja Expo Center',
            'proof_photo_path' => "attendance_proofs/{$this->company->id}/2026-11/sample2.webp",
            'notes' => 'Persiapan pameran siang.',
        ]);

        $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.approve', $lateOutside))
            ->assertOk()
            ->assertJsonPath('data.status', 'late');
    }

    /**
     * Skenario 2: Manager berhasil menolak presensi dengan menyertakan rejection_reason;
     * status beralih menjadi rejected.
     */
    public function test_scenario_2_manager_rejects_attendance_with_reason_and_transitions_status_to_rejected(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 11, 0, 0, 'Asia/Jakarta'));

        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->smStaff->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 8, 10, 0, 'Asia/Jakarta'),
            'clock_in_latitude' => -7.8012000,
            'clock_in_longitude' => 110.3645000,
            'clock_in_distance_meters' => 3800,
            'status' => 'pending_verification',
            'late_minutes' => 0,
            'is_outside_geofence' => true,
            'outside_category' => 'other',
            'outside_location_name' => 'Lokasi Tidak Dikenal',
            'proof_photo_path' => "attendance_proofs/{$this->company->id}/2026-11/sample_reject.webp",
            'notes' => 'Kunjungan luar.',
        ]);

        $response = $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.reject', $attendance), [
                'rejection_reason' => 'Swafoto gelap dan tidak memperlihatkan papan nama instansi klien.',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'rejected',
                    'rejection_reason' => 'Swafoto gelap dan tidak memperlihatkan papan nama instansi klien.',
                    'verified_by' => $this->smManager->user->id,
                ],
            ]);

        $attendance->refresh();
        $this->assertSame('rejected', $attendance->status);
        $this->assertTrue($attendance->isRejected());
        $this->assertFalse($attendance->isApproved());
        $this->assertSame($this->smManager->user->id, $attendance->verified_by);
        $this->assertNotNull($attendance->verified_at);
    }

    /**
     * Skenario 3: Penolakan tanpa alasan ditolak dengan validasi error HTTP 422.
     */
    public function test_scenario_3_rejection_without_reason_fails_with_422_validation_error(): void
    {
        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->smStaff->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta'),
            'clock_in_latitude' => -7.8012000,
            'clock_in_longitude' => 110.3645000,
            'clock_in_distance_meters' => 3800,
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
            'outside_category' => 'client_visit',
            'outside_location_name' => 'PT Mitra Wisata',
        ]);

        $response = $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.reject', $attendance), [
                'rejection_reason' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);

        $this->assertSame('pending_verification', $attendance->fresh()->status);
    }

    /**
     * Skenario 4: Manager dilarang menyetujui/menolak presensi staf dari departemen lain (HTTP 403 Forbidden).
     */
    public function test_scenario_4_manager_cannot_approve_or_reject_attendance_from_another_department(): void
    {
        $foAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->foStaff->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta'),
            'clock_in_latitude' => -7.9001200,
            'clock_in_longitude' => 110.0598500,
            'clock_in_distance_meters' => 1500,
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Bandara YIA',
        ]);

        // Manager SM mencoba menyetujui presensi staf FO -> 403 Forbidden
        $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.approve', $foAttendance))
            ->assertStatus(403);

        // Manager SM mencoba menolak presensi staf FO -> 403 Forbidden
        $this->actingAs($this->smManager->user)
            ->postJson(route('attendance.verifications.reject', $foAttendance), [
                'rejection_reason' => 'Bukan departemen saya tetapi mencoba menolak.',
            ])
            ->assertStatus(403);

        $this->assertSame('pending_verification', $foAttendance->fresh()->status);
    }

    /**
     * Skenario 5: Karyawan biasa (role: user) dilarang mengakses konsol verifikasi presensi (HTTP 403 Forbidden).
     */
    public function test_scenario_5_regular_user_cannot_access_verification_console_and_receives_403(): void
    {
        $this->actingAs($this->smStaff->user)
            ->get(route('attendance.verifications.index'))
            ->assertStatus(403);
    }

    /**
     * Skenario 6: Akses ke rute streaming foto (attendance.proof) terlindungi
     * dan menolak akses dari pengguna tenant lain (HTTP 403 Forbidden).
     */
    public function test_scenario_6_secure_proof_photo_streamer_blocks_cross_tenant_and_unauthorized_access(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 8, 0, 0, 'Asia/Jakarta'));

        $selfie = UploadedFile::fake()->image('bukti_bandara.jpg', 640, 480);

        $submitResponse = $this->actingAs($this->smStaff->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9001200,
            'longitude' => 110.0598500,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Bandara YIA',
            'notes' => 'Penjemputan tamu VIP grup korporat.',
            'photo' => $selfie,
        ]);

        $submitResponse->assertCreated();

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->smStaff->id)
            ->firstOrFail();

        // 1. Pemilik presensi dan Manajer departemen yang sama dapat mengakses streaming foto (HTTP 200)
        $this->actingAs($this->smStaff->user)
            ->get(route('attendance.proof', $attendance))
            ->assertOk();

        $this->actingAs($this->smManager->user)
            ->get(route('attendance.proof', $attendance))
            ->assertOk();

        // 2. Buat Tenant B (perusahaan lain) dan pastikan ditolak dengan HTTP 403 Forbidden
        $otherCompany = Company::create([
            'code' => 'IBIS-YIA',
            'name' => 'Ibis Styles YIA Kulon Progo',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius_meters' => 100,
            'accent_color' => '#E11D48',
            'is_active' => true,
        ]);

        $otherTenantAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Admin Ibis',
            'username' => 'admin.ibis',
            'email' => 'admin@ibisyia.com',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($otherTenantAdmin)
            ->get(route('attendance.proof', $attendance))
            ->assertStatus(403);
    }
}
