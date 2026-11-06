<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendancePhotoService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OutsideAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $smDept;
    protected Employee $employee;
    protected Employee $peerEmployee;

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

        $user1 = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina.sm',
            'email' => 'rina.sm@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user1->id,
            'department_id' => $this->smDept->id,
            'position' => 'Sales Executive',
            'join_date' => '2025-01-15',
            'status' => 'active',
        ]);

        $user2 = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Dimas Pratama',
            'username' => 'dimas.sm',
            'email' => 'dimas.sm@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->peerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user2->id,
            'department_id' => $this->smDept->id,
            'position' => 'Sales Coordinator',
            'join_date' => '2025-02-10',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Skenario 1: Karyawan di luar radius (> 100m) berhasil mengajukan presensi dinas luar
     * saat menyertakan swafoto valid dan data tugas lengkap.
     */
    public function test_scenario_1_employee_outside_radius_successfully_submits_outside_attendance_with_selfie(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 8, 0, 0, 'Asia/Jakarta'));

        // Pastikan antarmuka widget presensi di Dashboard memiliki elemen input kamera langsung capture="user"
        $viewResponse = $this->actingAs($this->employee->user)->get(route('dashboard'));
        $viewResponse->assertOk()
            ->assertSee('capture="user"', escape: false)
            ->assertSee('camera-capture-input', escape: false);

        $selfie = UploadedFile::fake()->image('selfie_bandara.jpg', 800, 600);

        // Koordinat Bandara YIA (~1.5 km dari hotel, > 100m)
        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9001200,
            'longitude' => 110.0598500,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Terminal Kedatangan Bandara YIA',
            'notes' => 'Penjemputan tamu VIP grup korporat di pintu kedatangan domestik.',
            'photo' => $selfie,
        ]);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'status' => 'pending_verification',
                'is_outside' => true,
                'data' => [
                    'status' => 'pending_verification',
                    'is_outside_geofence' => true,
                    'outside_category' => 'airport_pickup',
                    'outside_location_name' => 'Terminal Kedatangan Bandara YIA',
                ],
            ]);

        $this->assertDatabaseHas('attendances', [
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'outside_category' => 'airport_pickup',
            'outside_location_name' => 'Terminal Kedatangan Bandara YIA',
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
        ]);
    }

    /**
     * Skenario 2: Status presensi luar otomatis tersimpan sebagai pending_verification
     * dan is_outside_geofence = true, serta menghitung late_minutes jika melewati toleransi shift.
     */
    public function test_scenario_2_outside_attendance_enforces_pending_verification_status_and_outside_geofence_flag(): void
    {
        // Shift MO: 07:00 - 15:00 (toleransi 15 menit -> threshold 07:15). Pengajuan jam 07:35 (late 35 menit).
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 7, 35, 0, 'Asia/Jakarta'));

        $morningShift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $morningShift->id,
            'schedule_date' => '2026-11-04',
            'status' => 'published',
        ]);

        $selfie = UploadedFile::fake()->image('kunjungan_klien.png', 1024, 768);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.7955800,
            'longitude' => 110.3694900,
            'outside_category' => 'client_visit',
            'outside_location_name' => 'Kantor Dinas Pariwisata DIY',
            'notes' => 'Presentasi paket MICE akhir tahun bersama kepala bagian.',
            'photo' => $selfie,
        ]);

        $response->assertCreated();

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertSame('pending_verification', $attendance->status);
        $this->assertTrue($attendance->isPendingVerification());
        $this->assertTrue($attendance->is_outside_geofence);
        $this->assertSame($schedule->id, $attendance->work_schedule_id);
        $this->assertSame(35, $attendance->late_minutes);
        $this->assertGreaterThan(100, $attendance->clock_in_distance_meters);
    }

    /**
     * Skenario 3: Pengajuan ditolak dengan HTTP 422 jika berkas foto tidak disertakan
     * atau formatnya bukan gambar.
     */
    public function test_scenario_3_outside_attendance_is_rejected_with_422_when_photo_missing_or_not_an_image(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 8, 0, 0, 'Asia/Jakarta'));

        // Kasus A: Tanpa menyertakan berkas foto
        $missingPhotoResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'outside_category' => 'procurement',
            'outside_location_name' => 'Pasar Induk Wates',
            'notes' => 'Belanja bahan segar dapur F&B pagi hari.',
        ]);

        $missingPhotoResponse->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        // Kasus B: Menyertakan dokumen PDF / teks bukan gambar
        $fakeDocument = UploadedFile::fake()->create('dokumen_palsu.pdf', 120, 'application/pdf');

        $invalidMimeResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'outside_category' => 'procurement',
            'outside_location_name' => 'Pasar Induk Wates',
            'notes' => 'Belanja bahan segar dapur F&B pagi hari.',
            'photo' => $fakeDocument,
        ]);

        $invalidMimeResponse->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertDatabaseCount('attendances', 0);
    }

    /**
     * Skenario 4: Berkas foto berhasil diproses, dikompresi (<= 250 KB, maks 1280px),
     * dan tersimpan di direktori storage terisolasi per company_id.
     */
    public function test_scenario_4_photo_is_resized_compressed_under_250kb_and_stored_in_company_isolated_directory(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 9, 0, 0, 'Asia/Jakarta'));

        // Buat gambar beresolusi besar (2000 x 1500 px) untuk menguji downscaling <= 1280px & kompresi <= 250 KB
        $largePhoto = UploadedFile::fake()->image('high_res_selfie.jpg', 2000, 1500);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'outside_category' => 'event',
            'outside_location_name' => 'Jogja Expo Center (JEC)',
            'notes' => 'Jaga booth pameran pernikahan mewakili Novotel YIA.',
            'photo' => $largePhoto,
        ]);

        $response->assertCreated();

        $savedPath = $response->json('data.proof_photo_path');
        $this->assertNotEmpty($savedPath);

        // Pastikan path terisolasi di bawah attendance_proofs/{company_id}/2026-11/
        $expectedPrefix = "attendance_proofs/{$this->company->id}/2026-11/";
        $this->assertStringStartsWith($expectedPrefix, $savedPath);

        Storage::disk('local')->assertExists($savedPath);

        // Pastikan ukuran hasil kompresi <= 250 KB (256.000 bytes)
        $storedContent = Storage::disk('local')->get($savedPath);
        $this->assertLessThanOrEqual(AttendancePhotoService::MAX_SIZE_BYTES, strlen($storedContent));

        // Pastikan dimensi gambar yang tersimpan telah di-scale down <= 1280px
        $imageDimensions = getimagesizefromstring($storedContent);
        $this->assertNotFalse($imageDimensions);
        $this->assertLessThanOrEqual(AttendancePhotoService::MAX_DIMENSION_PX, $imageDimensions[0]);
        $this->assertLessThanOrEqual(AttendancePhotoService::MAX_DIMENSION_PX, $imageDimensions[1]);
    }

    /**
     * Skenario 5: Pengguna tidak dapat mengajukan presensi luar atas nama karyawan lain (terlindungi HTTP 403).
     */
    public function test_scenario_5_user_cannot_submit_outside_attendance_on_behalf_of_another_employee(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 4, 8, 30, 0, 'Asia/Jakarta'));

        $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

        // Percobaan menyisipkan employee_id milik rekan kerja lain
        $spoofById = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'employee_id' => $this->peerEmployee->id,
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'outside_category' => 'other',
            'outside_location_name' => 'Kantor Bank Mandiri Kulon Progo',
            'notes' => 'Setor dokumen administrasi perusahaan.',
            'photo' => $selfie,
        ]);

        $spoofById->assertStatus(403)
            ->assertJson(['success' => false]);

        // Percobaan menyisipkan employee_uuid milik rekan kerja lain
        $spoofByUuid = $this->actingAs($this->employee->user)->postJson(route('attendance.clockInOutside'), [
            'employee_uuid' => $this->peerEmployee->uuid,
            'latitude' => -7.9016774,
            'longitude' => 110.0572110,
            'outside_category' => 'other',
            'outside_location_name' => 'Kantor Bank Mandiri Kulon Progo',
            'notes' => 'Setor dokumen administrasi perusahaan.',
            'photo' => $selfie,
        ]);

        $spoofByUuid->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $this->peerEmployee->id,
        ]);
    }
}
