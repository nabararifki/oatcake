<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UniversalSelfieTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $department;
    protected Employee $employee;
    protected Shift $shift;
    protected WorkSchedule $schedule;

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
        $this->department = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Selfie Tester',
            'username' => 'selfie.tester',
            'email' => 'selfie.tester@novotelyia.com',
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'department_id' => $this->department->id,
            'employee_code' => 'EMP-TEST-001',
            'position' => 'Front Desk Agent',
            'status' => 'active',
            'join_date' => '2026-01-01',
        ]);

        $this->shift = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $this->schedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'schedule_date' => '2026-11-06',
            'status' => 'published',
        ]);
    }

    /**
     * 1. Universal Selfie: Clock-In dan Clock-Out wajib menyertakan foto.
     */
    public function test_clock_in_and_clock_out_require_photo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 7, 0, 0, 'Asia/Jakarta'));

        // Clock-In tanpa foto -> 422 Unprocessable Entity
        $inResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
        ]);

        $inResponse->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        // Buat record attendance aktif untuk tes clock-out
        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_schedule_id' => $this->schedule->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
            'clock_in_photo_path' => 'attendance_proofs/dummy_in.webp',
        ]);

        // Clock-Out tanpa foto -> 422 Unprocessable Entity
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 15, 0, 0, 'Asia/Jakarta'));
        $outResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
        ]);

        $outResponse->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }

    /**
     * 2. Universal Selfie: Clock-In dan Clock-Out dengan foto valid terkompresi ke WebP.
     */
    public function test_clock_in_and_clock_out_with_valid_photo_saves_compressed_paths(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 7, 0, 0, 'Asia/Jakarta'));

        $clockInPhoto = UploadedFile::fake()->image('selfie_in.jpg', 1200, 900);

        $inResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => $clockInPhoto,
        ]);

        $inResponse->assertOk()
            ->assertJsonPath('success', true);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertNotNull($attendance->clock_in_photo_path);
        $this->assertNotNull($attendance->proof_photo_path);
        $this->assertStringEndsWith('.webp', $attendance->clock_in_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($attendance->clock_in_photo_path));

        // Clock-Out dengan foto
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 15, 0, 0, 'Asia/Jakarta'));
        $clockOutPhoto = UploadedFile::fake()->image('selfie_out.jpg', 1200, 900);

        $outResponse = $this->actingAs($this->employee->user)->postJson(route('attendance.clockOut'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => $clockOutPhoto,
        ]);

        $outResponse->assertOk()
            ->assertJsonPath('success', true);

        $attendance->refresh();
        $this->assertNotNull($attendance->clock_out_photo_path);
        $this->assertStringEndsWith('.webp', $attendance->clock_out_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($attendance->clock_out_photo_path));
    }

    /**
     * 3. Proteksi Kuota Storage Tenant: Clock-In ditolak jika storage melebihi batas storage_limit_mb.
     */
    public function test_clock_in_is_blocked_when_storage_quota_is_exceeded(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 7, 0, 0, 'Asia/Jakarta'));

        // Atur kuota perusahaan menjadi 1 MB
        $this->company->update(['storage_limit_mb' => 1]);

        // Simulasikan file bukti di disk lokal perusahaan berukuran 1.2 MB
        $dummyFilePath = "attendance_proofs/{$this->company->id}/huge_test_proof.webp";
        Storage::disk('local')->put($dummyFilePath, str_repeat('A', 1200000));

        $this->assertTrue($this->company->fresh()->isStorageLimitExceeded());

        $clockInPhoto = UploadedFile::fake()->image('selfie.jpg', 800, 600);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => $clockInPhoto,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);

        $this->assertStringContainsString('1', $response->json('message') ?? $response->json('errors.photo.0'));
    }

    /**
     * 4. Scheduled Retention Auto-Erase: Command attendance:prune-photos menghapus foto kedaluwarsa.
     */
    public function test_prune_attendance_photos_command_erases_expired_files_and_nullifies_columns(): void
    {
        // Atur retensi perusahaan 3 bulan
        $this->company->update(['photo_retention_months' => 3]);

        // File lama (4 bulan lalu)
        $oldInPath = "attendance_proofs/{$this->company->id}/old_in.webp";
        $oldOutPath = "attendance_proofs/{$this->company->id}/old_out.webp";
        Storage::disk('local')->put($oldInPath, 'old_in_content');
        Storage::disk('local')->put($oldOutPath, 'old_out_content');

        $oldAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_schedule_id' => $this->schedule->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta')->subMonths(4),
            'clock_out_at' => Carbon::now('Asia/Jakarta')->subMonths(4)->addHours(8),
            'status' => 'on_time',
            'clock_in_photo_path' => $oldInPath,
            'proof_photo_path' => $oldInPath,
            'clock_out_photo_path' => $oldOutPath,
            'created_at' => Carbon::now('Asia/Jakarta')->subMonths(4),
        ]);

        // File baru (1 bulan lalu)
        $recentInPath = "attendance_proofs/{$this->company->id}/recent_in.webp";
        $recentOutPath = "attendance_proofs/{$this->company->id}/recent_out.webp";
        Storage::disk('local')->put($recentInPath, 'recent_in_content');
        Storage::disk('local')->put($recentOutPath, 'recent_out_content');

        $recentAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_schedule_id' => $this->schedule->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta')->subMonth(),
            'clock_out_at' => Carbon::now('Asia/Jakarta')->subMonth()->addHours(8),
            'status' => 'on_time',
            'clock_in_photo_path' => $recentInPath,
            'proof_photo_path' => $recentInPath,
            'clock_out_photo_path' => $recentOutPath,
        ]);

        // Eksekusi artisan command
        $exitCode = Artisan::call('attendance:prune-photos');
        $this->assertSame(0, $exitCode);

        // Verifikasi file lama terhapus fisik & kolom database bernilai null
        $this->assertFalse(Storage::disk('local')->exists($oldInPath));
        $this->assertFalse(Storage::disk('local')->exists($oldOutPath));

        $oldAttendance->refresh();
        $this->assertNull($oldAttendance->clock_in_photo_path);
        $this->assertNull($oldAttendance->proof_photo_path);
        $this->assertNull($oldAttendance->clock_out_photo_path);

        // Verifikasi file baru tetap ada & tidak diubah
        $this->assertTrue(Storage::disk('local')->exists($recentInPath));
        $this->assertTrue(Storage::disk('local')->exists($recentOutPath));

        $recentAttendance->refresh();
        $this->assertSame($recentInPath, $recentAttendance->clock_in_photo_path);
        $this->assertSame($recentOutPath, $recentAttendance->clock_out_photo_path);
    }

    /**
     * 5. Secure Proof Streaming: Stream foto clock_in dan clock_out serta proteksi 404 & 403.
     */
    public function test_secure_proof_streaming_endpoint_handles_in_out_and_expired(): void
    {
        $inPath = "attendance_proofs/{$this->company->id}/test_in.webp";
        $outPath = "attendance_proofs/{$this->company->id}/test_out.webp";
        Storage::disk('local')->put($inPath, 'webp_fake_in_binary');
        Storage::disk('local')->put($outPath, 'webp_fake_out_binary');

        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_schedule_id' => $this->schedule->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta'),
            'clock_out_at' => Carbon::now('Asia/Jakarta')->addHours(8),
            'status' => 'on_time',
            'clock_in_photo_path' => $inPath,
            'clock_out_photo_path' => $outPath,
        ]);

        // Karyawan pemilik dapat melihat foto masuk
        $responseIn = $this->actingAs($this->employee->user)
            ->get(route('attendance.proof', ['attendance' => $attendance->uuid, 'type' => 'clock_in']));
        $responseIn->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        // Karyawan pemilik dapat melihat foto pulang
        $responseOut = $this->actingAs($this->employee->user)
            ->get(route('attendance.proof', ['attendance' => $attendance->uuid, 'type' => 'clock_out']));
        $responseOut->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        // User dari tenant lain ditolak 403
        $otherCompany = Company::create([
            'code' => 'OTHER-CO',
            'name' => 'Other Company',
            'latitude' => -7.9,
            'longitude' => 110.1,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role' => 'user',
            'is_active' => true,
        ]);

        $this->actingAs($otherUser)
            ->get(route('attendance.proof', ['attendance' => $attendance->uuid, 'type' => 'clock_in']))
            ->assertForbidden();

        // Foto yang sudah terhapus/pruned mengembalikan 404
        $attendance->update(['clock_out_photo_path' => null]);
        $this->actingAs($this->employee->user)
            ->get(route('attendance.proof', ['attendance' => $attendance->uuid, 'type' => 'clock_out']))
            ->assertNotFound();
    }

    /**
     * 6. Audit CRUD Departemen: Safe-delete mencegah penghapusan jika memiliki karyawan atau jadwal.
     */
    public function test_department_safe_delete_prevents_deletion_when_employees_or_schedules_exist(): void
    {
        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Departemen memiliki karyawan ($this->employee) -> gagal dihapus
        $delResponse = $this->actingAs($admin)
            ->delete(route('departments.destroy', $this->department));

        $delResponse->assertRedirect()
            ->assertSessionHas('error', __('departments.err_has_employees'));

        $this->assertDatabaseHas('departments', ['id' => $this->department->id]);

        // Buat departemen baru tanpa karyawan & tanpa jadwal -> berhasil dihapus
        $emptyDept = Department::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Temporary Department',
            'code' => 'TEMP-DEPT',
            'is_active' => true,
        ]);

        $delSuccessResponse = $this->actingAs($admin)
            ->delete(route('departments.destroy', $emptyDept));

        $delSuccessResponse->assertRedirect(route('departments.index'))
            ->assertSessionHas('success', __('departments.deleted_successfully'));

        $this->assertDatabaseMissing('departments', ['id' => $emptyDept->id]);
    }

    /**
     * 7. Audit Pengaturan Perusahaan: storage_limit_mb hanya dapat diubah oleh super_admin.
     */
    public function test_company_storage_limit_can_only_be_updated_by_super_admin(): void
    {
        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $superAdmin = User::create([
            'company_id' => null,
            'name' => 'Super Root',
            'username' => 'super_root',
            'email' => 'root@universal.id',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Admin biasa mencoba mengupdate storage_limit_mb
        $this->actingAs($admin)->putJson(route('settings.company.update'), [
            'name' => $this->company->name,
            'code' => $this->company->code,
            'latitude' => $this->company->latitude,
            'longitude' => $this->company->longitude,
            'geofence_radius' => 100,
            'timezone' => 'Asia/Jakarta',
            'photo_retention_months' => 6,
            'storage_limit_mb' => 9999, // Percobaan ubah kuota
        ]);

        $this->company->refresh();
        $this->assertSame(6, (int) $this->company->photo_retention_months);
        $this->assertSame(1024, (int) $this->company->storage_limit_mb); // Tidak berubah!

        // Super Admin mengupdate storage_limit_mb
        session(['active_company_id' => $this->company->id]);
        $this->actingAs($superAdmin)->putJson(route('settings.company.update'), [
            'name' => $this->company->name,
            'code' => $this->company->code,
            'latitude' => $this->company->latitude,
            'longitude' => $this->company->longitude,
            'geofence_radius' => 100,
            'timezone' => 'Asia/Jakarta',
            'photo_retention_months' => 12,
            'storage_limit_mb' => 2048,
        ]);

        $this->company->refresh();
        $this->assertSame(12, (int) $this->company->photo_retention_months);
        $this->assertSame(2048, (int) $this->company->storage_limit_mb); // Berhasil diubah oleh Super Admin!
    }

    /**
     * 8. Resiliensi Upload Foto Berukuran Besar (hingga 12 MB) & Jaminan Kontrak Respons JSON.
     */
    public function test_clock_in_accepts_large_selfie_image_and_returns_valid_json(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 6, 7, 0, 0, 'Asia/Jakarta'));

        // Simulasi foto kamera resolusi tinggi 12 MB (12288 KB)
        $largePhoto = UploadedFile::fake()->image('high_res_selfie.jpg', 3000, 2000)->size(12288);

        $response = $this->actingAs($this->employee->user)->postJson(route('attendance.clockIn'), [
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'photo' => $largePhoto,
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', true);

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $this->employee->id)
            ->firstOrFail();

        $this->assertNotNull($attendance->clock_in_photo_path);
        $this->assertTrue(Storage::disk('local')->exists($attendance->clock_in_photo_path));
    }
}
