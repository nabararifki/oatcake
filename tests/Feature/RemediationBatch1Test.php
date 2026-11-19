<?php

namespace Tests\Feature;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceGeofenceService;
use App\Services\AttendancePhotoService;
use App\Services\LeaveApprovalService;
use App\Services\LeaveAttachmentService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use Database\Seeders\CompanySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RemediationBatch1Test extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $foDept;
    protected Department $hkDept;
    protected Shift $shiftMo;
    protected Shift $shiftNi;
    protected Shift $shiftOff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $this->foDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'FO')
            ->firstOrFail();

        $this->hkDept = Department::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'HK')
            ->firstOrFail();

        $this->shiftMo = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'MO')
            ->firstOrFail();

        $this->shiftNi = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'NI')
            ->firstOrFail();

        $this->shiftOff = Shift::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'OFF'],
            [
                'name' => 'Day Off',
                'start_time' => '00:00:00',
                'end_time' => '00:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 0,
                'color_badge' => '#9CA3AF',
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createEmployee(string $role = 'user', ?Department $department = null, ?Company $company = null, string $prefix = 'staff'): Employee
    {
        $targetCompany = $company ?? $this->company;
        $targetDept = $department ?? $this->foDept;
        $unique = $prefix.'.'.uniqid();

        $user = User::factory()->create([
            'company_id' => $targetCompany->id,
            'name' => ucfirst($prefix).' User',
            'username' => str_replace('.', '_', $unique),
            'email' => $unique.'@novotelyia.com',
            'password' => Hash::make('Password123!'),
            'role' => $role,
            'is_active' => true,
        ]);

        return Employee::withoutGlobalScopes()->create([
            'company_id' => $targetCompany->id,
            'user_id' => $user->id,
            'department_id' => $targetDept->id,
            'position' => 'Staff',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
    }

    protected function makeJpegWithShortOpenTagBytes(string $filename = 'camera.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor(64, 64);
        $bg = imagecolorallocate($img, 24, 90, 180);
        imagefilledrectangle($img, 0, 0, 64, 64, $bg);

        ob_start();
        imagejpeg($img, null, 90);
        $rawJpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Inject a valid JPEG COM marker carrying literal '<?=' bytes before EOI (0xFF 0xD9)
        $payload = '<?=exif_binary_payload_collision';
        $comSegment = "\xFF\xFE".pack('n', strlen($payload) + 2).$payload;
        $mutatedJpeg = substr($rawJpeg, 0, -2).$comSegment."\xFF\xD9";

        $tmpPath = tempnam(sys_get_temp_dir(), 'oat_jpg_');
        file_put_contents($tmpPath, $mutatedJpeg);

        return new UploadedFile($tmpPath, $filename, 'image/jpeg', null, true);
    }

    public function test_block_1a_holiday_sync_uses_clock_in_at_range_and_commits_dp_without_crash(): void
    {
        $admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $emp = $this->createEmployee('user', $this->foDept);
        $ledgerService = app(LeaveLedgerService::class);

        $qualifying = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $emp->id,
            'clock_in_at' => Carbon::create(2026, 11, 20, 8, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 20, 16, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'is_provisional_dp' => false,
        ]);

        $rejected = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $emp->id,
            'clock_in_at' => Carbon::create(2026, 11, 20, 9, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 20, 17, 0, 0, 'Asia/Jakarta'),
            'status' => 'rejected',
            'is_provisional_dp' => false,
        ]);

        $this->actingAs($admin)
            ->postJson(route('settings.holidays.store'), [
                'holiday_date' => '2026-11-20',
                'name' => 'Special Company Event',
                'is_national' => false,
                'is_recurring' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $qualifying->refresh();
        $rejected->refresh();

        $this->assertTrue($qualifying->is_provisional_dp);
        $this->assertNotNull($qualifying->dp_committed_at);
        $this->assertNotNull($qualifying->dp_ledger_entry_id);
        $this->assertFalse($rejected->is_provisional_dp);
        $this->assertNull($rejected->dp_committed_at);
        $this->assertSame(1, $ledgerService->getBalance($emp, 'DP'));

        // Removing an uncommitted holiday clears is_provisional_dp without error
        $uncommitted = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $emp->id,
            'clock_in_at' => Carbon::create(2026, 11, 21, 8, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'is_provisional_dp' => false,
        ]);

        $this->actingAs($admin)
            ->postJson(route('settings.holidays.store'), [
                'holiday_date' => '2026-11-21',
                'name' => 'Temporary Holiday',
                'is_national' => false,
            ])
            ->assertCreated();

        $this->assertTrue($uncommitted->fresh()->is_provisional_dp);

        $holiday = PublicHoliday::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereDate('holiday_date', '2026-11-21')
            ->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson(route('settings.holidays.destroy', $holiday))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($uncommitted->fresh()->is_provisional_dp);
    }

    public function test_block_1b_update_employee_accepts_all_aligned_statuses(): void
    {
        $admin = $this->createEmployee('admin', $this->foDept, null, 'admin');
        $target = $this->createEmployee('user', $this->foDept, null, 'target');

        foreach (['inactive', 'on_leave', 'resigned', 'suspended', 'active'] as $status) {
            $response = $this->actingAs($admin->user)
                ->put(route('staff.update', $target), [
                    'name' => $target->user->name,
                    'username' => $target->user->username,
                    'email' => $target->user->email,
                    'department_id' => $this->foDept->id,
                    'position' => 'Guest Service Agent',
                    'join_date' => '2025-01-10',
                    'status' => $status,
                    'role' => 'user',
                ]);

            $response->assertRedirect(route('staff.index'));
            $this->assertSame($status, $target->fresh()->status);
        }

        $this->actingAs($admin->user)
            ->put(route('staff.update', $target), [
                'name' => $target->user->name,
                'username' => $target->user->username,
                'email' => $target->user->email,
                'department_id' => $this->foDept->id,
                'position' => 'Guest Service Agent',
                'join_date' => '2025-01-10',
                'status' => 'invalid_status',
                'role' => 'user',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_block_2a_role_rank_hierarchy_and_super_admin_policies_without_gate_before(): void
    {
        $managerA = $this->createEmployee('manager', $this->foDept, null, 'mgr_a');
        $managerB = $this->createEmployee('manager', $this->foDept, null, 'mgr_b');
        $adminEmp = $this->createEmployee('admin', $this->foDept, null, 'adm');
        $superUserEmp = $this->createEmployee('super_user', $this->foDept, null, 'su');
        $staffEmp = $this->createEmployee('user', $this->foDept, null, 'stf');

        // Equal/higher role mutation & deletion must return 403
        $this->actingAs($managerA->user)->get(route('staff.edit', $managerB))->assertForbidden();
        $this->actingAs($managerA->user)->delete(route('staff.destroy', $managerB))->assertForbidden();
        $this->actingAs($managerA->user)->get(route('staff.edit', $adminEmp))->assertForbidden();
        $this->actingAs($adminEmp->user)->get(route('staff.edit', $superUserEmp))->assertForbidden();
        $this->actingAs($adminEmp->user)->delete(route('staff.destroy', $superUserEmp))->assertForbidden();

        // Admin can edit lower rank staff, and self-delete still triggers 422 self-delete guard
        $this->actingAs($adminEmp->user)->get(route('staff.edit', $staffEmp))->assertOk();
        $this->actingAs($adminEmp->user)->delete(route('staff.destroy', $adminEmp))->assertStatus(422);

        // Super Admin passes explicit policies even without Gate::before
        $superAdmin = User::factory()->create([
            'company_id' => null,
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $attendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staffEmp->id,
            'clock_in_at' => Carbon::now('Asia/Jakarta')->subHours(2),
            'status' => 'on_time',
        ]);

        $auditLog = AuditLog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'actor_id' => $adminEmp->user_id,
            'event_type' => 'updated',
            'auditable_type' => Employee::class,
            'auditable_id' => $staffEmp->id,
            'description' => 'Updated employee test entry',
            'created_at' => Carbon::now('Asia/Jakarta'),
        ]);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Employee::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $staffEmp));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $staffEmp));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $attendance));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('delete', $attendance));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Company::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Department::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $auditLog));

        // Deactivated super_admin is denied by policies (proof Gate::before unconditional bypass is gone)
        $superAdmin->forceFill(['is_active' => false])->save();
        $this->assertFalse(Gate::forUser($superAdmin)->allows('viewAny', Employee::class));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $attendance));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $this->company));
    }

    public function test_block_2b_cross_tenant_leave_type_id_is_rejected_on_submit_and_adjust(): void
    {
        $companyB = Company::create([
            'name' => 'Ibis Styles YIA',
            'code' => 'IBIS-YIA',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 100,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $foreignLeaveType = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'code' => 'FOREIGN_CT',
            'name' => 'Foreign Custom Leave',
            'requires_quota' => true,
            'requires_attachment' => false,
            'annual_quota' => 5,
            'validity_months' => 12,
            'is_active' => true,
        ]);

        $staffA = $this->createEmployee('user', $this->foDept);
        $adminA = $this->createEmployee('admin', $this->foDept);

        $this->actingAs($staffA->user)
            ->postJson(route('leave.store'), [
                'leave_type_id' => $foreignLeaveType->id,
                'start_date' => Carbon::today('Asia/Jakarta')->addDays(5)->toDateString(),
                'end_date' => Carbon::today('Asia/Jakarta')->addDays(6)->toDateString(),
                'reason' => 'Attempting IDOR cross-tenant leave type',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['leave_type_id']);

        $this->actingAs($adminA->user)
            ->postJson(route('leave.ledger.adjust'), [
                'employee_id' => $staffA->id,
                'leave_type_id' => $foreignLeaveType->id,
                'amount' => 3,
                'notes' => 'Attempting IDOR cross-tenant ledger adjustment',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['leave_type_id']);
    }

    public function test_block_3_started_leave_cannot_be_cancelled_or_edited_and_expired_refund_never_extends_validity(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'Asia/Jakarta'));

        $staff = $this->createEmployee('user', $this->foDept);
        $ledgerService = app(LeaveLedgerService::class);
        $alType = LeaveType::findByCode('AL', $this->company->id);

        $todayLeave = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'reason' => 'Starts today',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $pastLeave = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-05-25',
            'end_date' => '2026-05-26',
            'total_days' => 2,
            'reason' => 'In the past',
            'status' => LeaveRequest::STATUS_PENDING_MANAGER,
        ]);

        $this->assertFalse($todayLeave->canBeCancelled());
        $this->assertFalse($todayLeave->canBeEdited());
        $this->assertFalse(Gate::forUser($staff->user)->allows('canBeCancelled', $todayLeave));
        $this->assertFalse(Gate::forUser($staff->user)->allows('canBeEdited', $todayLeave));
        $this->assertFalse(Gate::forUser($staff->user)->allows('cancel', $todayLeave));
        $this->assertFalse(Gate::forUser($staff->user)->allows('update', $pastLeave));

        $this->actingAs($staff->user)
            ->postJson(route('leave.cancel', $todayLeave), ['cancellation_reason' => 'Self-cancel exploit'])
            ->assertStatus(422);

        $this->actingAs($staff->user)
            ->putJson(route('leave.update', $pastLeave), [
                'leave_type_id' => $alType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Move past leave to future',
            ])
            ->assertStatus(422);

        // Refund / credit from an already-expired source bucket must NOT mint a fresh expiry window
        Carbon::setTestNow(Carbon::create(2026, 1, 10, 9, 0, 0, 'Asia/Jakarta'));
        $expiredSource = $ledgerService->recordAccrual($staff, $alType, 5, [
            'accrual_year' => 2025,
            'expires_at' => '2026-01-31',
        ]);
        $debitEntry = $ledgerService->recordDebit($staff, $alType, 2);

        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'Asia/Jakarta'));
        $pastApproved = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-01-15',
            'end_date' => '2026-01-16',
            'total_days' => 2,
            'reason' => 'Used in Jan',
            'status' => LeaveRequest::STATUS_APPROVED,
            'ledger_entry_id' => $debitEntry->id,
        ]);

        $refundEntry = $ledgerService->recordCancellationRefund($staff, $alType, 2, $pastApproved);
        $this->assertSame('2026-01-31', $refundEntry->expires_at?->toDateString());
        $this->assertSame(0, (int) $refundEntry->remaining_amount);

        $creditFromExpiredSource = $ledgerService->recordCredit($staff, $alType, 1, [
            'source_entry_id' => $expiredSource->id,
        ]);
        $this->assertSame('2026-01-31', $creditFromExpiredSource->expires_at?->toDateString());
        $this->assertSame(0, (int) $creditFromExpiredSource->remaining_amount);
    }

    public function test_block_4a_outside_clock_out_succeeds_and_rejected_attendance_never_locks_staff(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 8, 5, 0, 'Asia/Jakarta'));

        $staff = $this->createEmployee('user', $this->foDept);

        // Rejected outside-duty attendance with null clock_out_at must NOT block new clock-in or show open session on Dashboard
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'clock_in_at' => Carbon::create(2026, 11, 12, 7, 30, 0, 'Asia/Jakarta'),
            'clock_out_at' => null,
            'is_outside_geofence' => true,
            'status' => 'rejected',
            'rejection_reason' => 'Invalid location photo',
        ]);

        $this->actingAs($staff->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('hasActiveClockIn: false', false);

        // Clock in normally after rejection succeeds
        $this->actingAs($staff->user)
            ->postJson(route('attendance.clockIn'), [
                'latitude' => -7.9048250,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('valid_in.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Clock out from ~15km outside radius succeeds (200 OK) with is_outside = true
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 16, 10, 0, 'Asia/Jakarta'));
        $clockOutResp = $this->actingAs($staff->user)
            ->postJson(route('attendance.clockOut'), [
                'latitude' => -7.7955800,
                'longitude' => 110.3694900,
                'photo' => UploadedFile::fake()->image('outside_out.jpg'),
                'notes' => 'Pulang langsung dari lokasi klien',
            ]);

        $clockOutResp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_outside', true);

        $latest = Attendance::withoutGlobalScopes()
            ->where('employee_id', $staff->id)
            ->where('status', '!=', 'rejected')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($latest->clock_out_at);
        $this->assertGreaterThan((int) $this->company->geofence_radius, (int) $latest->clock_out_distance_meters);
        $this->assertStringContainsString('Pulang langsung dari lokasi klien', (string) $latest->notes);
    }

    public function test_block_4b_cross_day_shift_resolves_previous_day_overnight_shift_after_midnight(): void
    {
        $staff = $this->createEmployee('user', $this->foDept);
        $geofenceService = app(AttendanceGeofenceService::class);

        $niYesterdaySchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'shift_id' => $this->shiftNi->id, // 23:00 - 07:00, cross_day = true
            'schedule_date' => '2026-11-11',
            'status' => 'published',
        ]);

        // Day D also has an OFF or MO schedule that must NOT preempt ongoing D-1 night shift
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'shift_id' => $this->shiftMo->id, // 07:00 - 15:00
            'schedule_date' => '2026-11-12',
            'status' => 'published',
        ]);

        // Arrival at 00:15 WIB on 2026-11-12 must resolve to D-1 NI (23:00-07:00), NOT 2026-11-12 MO
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 0, 15, 0, 'Asia/Jakarta'));

        $resolved = $geofenceService->resolveTodaySchedule($staff, Carbon::now('Asia/Jakarta'), false);
        $this->assertNotNull($resolved);
        $this->assertSame($niYesterdaySchedule->id, $resolved->id);

        $this->actingAs($staff->user)
            ->postJson(route('attendance.clockIn'), [
                'latitude' => -7.9048250,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('night_in.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.work_schedule_id', $niYesterdaySchedule->id)
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.late_minutes', 75);

        // Clock-out at 07:05 WIB binds to the D-1 NI session and records 0 early_leave_minutes
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 7, 5, 0, 'Asia/Jakarta'));
        $this->actingAs($staff->user)
            ->postJson(route('attendance.clockOut'), [
                'latitude' => -7.9048250,
                'longitude' => 110.0572110,
                'photo' => UploadedFile::fake()->image('night_out.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.work_schedule_id', $niYesterdaySchedule->id)
            ->assertJsonPath('data.early_leave_minutes', 0);
    }

    public function test_block_5_web_push_dispatches_async_job_after_audit_and_hardens_ssrf_and_logout(): void
    {
        Queue::fake();

        $staff = $this->createEmployee('user', $this->foDept, null, 'push_staff');
        $manager = $this->createEmployee('manager', $this->foDept, null, 'push_mgr');
        $admin = $this->createEmployee('admin', $this->foDept, null, 'push_adm');

        // SSRF & HTTPS endpoint validation on subscription store
        $keys = [
            'p256dh' => str_repeat('A', 64),
            'auth' => str_repeat('B', 22),
        ];

        foreach ([
            'http://fcm.googleapis.com/fcm/send/insecure',
            'https://localhost/push/123',
            'https://127.0.0.1/push/123',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/push',
        ] as $blockedEndpoint) {
            $this->actingAs($staff->user)
                ->postJson(route('notifications.subscriptions.store'), [
                    'endpoint' => $blockedEndpoint,
                    'keys' => $keys,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['endpoint']);
        }

        $this->actingAs($staff->user)
            ->postJson(route('notifications.subscriptions.store'), [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/valid-endpoint-123',
                'keys' => $keys,
            ])
            ->assertCreated();

        // Logout push unsubscription uses keepalive: true so redirects cannot abort backend cleanup
        $pwaJs = file_get_contents(resource_path('js/pwa.js'));
        $this->assertNotFalse($pwaJs);
        $this->assertStringContainsString('keepalive: true', $pwaJs);

        // Roster publish queues job and records audit trail
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staff->id,
            'shift_id' => $this->shiftMo->id,
            'schedule_date' => '2026-11-22',
            'status' => 'draft',
        ]);

        $this->actingAs($admin->user)
            ->postJson(route('roster.publish'), [
                'month' => 11,
                'year' => 2026,
                'department_id' => $this->foDept->id,
            ])
            ->assertOk();

        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'roster_published');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'published', 'company_id' => $this->company->id]);

        // Leave submit & approvals queue Web Push job and record audit logs
        $ledger = app(LeaveLedgerService::class);
        $alType = LeaveType::findByCode('AL', $this->company->id);
        $ledger->recordAccrual($staff, $alType, 12, ['accrual_year' => 2026]);

        $reqService = app(LeaveRequestService::class);
        $approvalService = app(LeaveApprovalService::class);

        $leaveReq = $reqService->submitRequest($staff, [
            'leave_type_id' => $alType->id,
            'start_date' => Carbon::today('Asia/Jakarta')->addDays(10)->toDateString(),
            'end_date' => Carbon::today('Asia/Jakarta')->addDays(10)->toDateString(),
            'reason' => 'Queue test leave request',
        ], null, $staff->user);

        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'leave_submitted');

        $leaveReq = $approvalService->approveTier1($leaveReq, $manager->user, 'Approved Tier 1');
        $approvalService->approveTier2($leaveReq, $admin->user, 'Approved Tier 2');

        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'leave_tier1_approved');
        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'leave_approved');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'approved_tier1', 'auditable_id' => $leaveReq->id]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'approved_tier2', 'auditable_id' => $leaveReq->id]);

        // Outside attendance & verification queue Web Push job
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 8, 5, 0, 'Asia/Jakarta'));
        $outsideResp = $this->actingAs($staff->user)
            ->postJson(route('attendance.clockInOutside'), [
                'latitude' => -7.795580,
                'longitude' => 110.369490,
                'outside_category' => 'client_visit',
                'outside_location_name' => 'Client HQ',
                'notes' => 'Meeting klien pagi hari',
                'photo' => UploadedFile::fake()->image('outside.jpg'),
            ])
            ->assertCreated();

        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'attendance_outside_pending');

        $attendanceId = $outsideResp->json('data.id');
        $this->actingAs($manager->user)
            ->postJson(route('attendance.verifications.approve', $attendanceId))
            ->assertOk();

        Queue::assertPushed(SendWebPushNotificationJob::class, fn (SendWebPushNotificationJob $job): bool => $job->category === 'attendance_verified');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'verified', 'auditable_id' => $attendanceId]);
    }

    public function test_block_6_legitimate_images_containing_short_open_tag_bytes_succeed_and_strip_metadata(): void
    {
        $staff = $this->createEmployee('user', $this->foDept, null, 'photo_staff');
        $photoService = app(AttendancePhotoService::class);
        $attachmentService = app(LeaveAttachmentService::class);

        $photoWithCollision = $this->makeJpegWithShortOpenTagBytes('selfie_collision.jpg');
        $this->assertStringContainsString('<?=', (string) file_get_contents($photoWithCollision->getRealPath()));

        $storedPhotoPath = $photoService->processAndStore($photoWithCollision, $this->company, (string) $staff->uuid);
        $this->assertTrue(Storage::disk('local')->exists($storedPhotoPath));
        $this->assertStringEndsWith('.webp', $storedPhotoPath);
        $this->assertStringNotContainsString('<?=', (string) Storage::disk('local')->get($storedPhotoPath));

        $attachmentWithCollision = $this->makeJpegWithShortOpenTagBytes('medical_collision.jpg');
        $storedAttachmentPath = $attachmentService->processAndStore($attachmentWithCollision, $this->company, (string) $staff->uuid);
        $this->assertTrue(Storage::disk('local')->exists($storedAttachmentPath));
        $this->assertStringEndsWith('.webp', $storedAttachmentPath);
        $this->assertStringNotContainsString('<?=', (string) Storage::disk('local')->get($storedAttachmentPath));
    }
}
