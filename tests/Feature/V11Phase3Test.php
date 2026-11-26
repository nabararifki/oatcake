<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class V11Phase3Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createFixture(): array
    {
        (new LeaveTypeSeeder)->run();

        $company = Company::create([
            'code' => 'HOTEL-PHASE3',
            'name' => 'Grand Oatcake Hotel & Resort',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'auto_logout_minutes' => 30,
            'is_active' => true,
        ]);

        $dept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_operational' => true,
            'min_staff_per_shift' => 1,
            'is_executive_oversight' => false,
        ]);

        $adminHrUser = User::create([
            'company_id' => $company->id,
            'name' => 'Siti Admin HR',
            'username' => 'siti_hr',
            'email' => 'siti.hr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin_hr',
            'is_active' => true,
        ]);

        $adminStaffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Andi Duty Manager',
            'username' => 'andi_supervisor',
            'email' => 'andi.sup@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin_staff',
            'is_active' => true,
        ]);

        $adminStaffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $adminStaffUser->id,
            'department_id' => $dept->id,
            'position' => 'Duty Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $staffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Budi Frontdesk',
            'username' => 'budi_fd',
            'email' => 'budi.fd@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $staffUser->id,
            'department_id' => $dept->id,
            'manager_id' => $adminStaffEmp->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-01',
            'status' => 'active',
        ]);

        $morningShift = Shift::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'M',
            'name' => 'Morning Shift',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'color_badge' => '#2563EB',
            'is_active' => true,
        ]);

        return compact('company', 'dept', 'adminHrUser', 'adminStaffUser', 'adminStaffEmp', 'staffUser', 'staffEmp', 'morningShift');
    }

    public function test_eo_leave_type_seeded_idempotently_and_excluded_from_annual_accrual(): void
    {
        $fixture = $this->createFixture();

        $this->assertArrayHasKey('EO', LeaveType::STANDARD_TYPES);
        $this->assertNull(LeaveType::STANDARD_TYPES['EO']['default_annual_quota']);
        $this->assertTrue(LeaveType::STANDARD_TYPES['EO']['requires_quota']);

        // Running seeder twice must remain idempotent
        (new LeaveTypeSeeder)->run();
        $eoTypes = LeaveType::withoutGlobalScopes()->where('code', 'EO')->get();
        $this->assertCount(1, $eoTypes);
        $this->assertSame('Extra Off (EO)', $eoTypes->first()->name);

        // Annual accrual command accrues AL, NOT EO
        $this->artisan('leave:accrue-annual', ['--year' => 2026])->assertSuccessful();

        $ledger = app(LeaveLedgerService::class);
        $this->assertSame(12, $ledger->getBalance($fixture['staffEmp'], 'AL'));
        $this->assertSame(0, $ledger->getBalance($fixture['staffEmp'], 'EO'));
    }

    public function test_eo_ledger_credit_debit_expiry_workflow_and_ui(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 25, 9, 0, 0, 'Asia/Jakarta'));
        $fixture = $this->createFixture();
        $staffEmp = $fixture['staffEmp'];
        $eoType = LeaveType::findByCode('EO', $fixture['company']->id);
        $this->assertNotNull($eoType);

        // Schedule active shift so effective working days = 1
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $staffEmp->id,
            'shift_id' => $fixture['morningShift']->id,
            'schedule_date' => '2026-11-28',
            'status' => 'published',
        ]);

        $ledger = app(LeaveLedgerService::class);

        // Credit 2 EO days (one expiring earlier, one later)
        $ledger->recordCredit($staffEmp, $eoType, 1, [
            'expires_at' => '2026-12-31',
            'notes' => 'EO duty replacement 1',
        ]);
        $ledger->recordCredit($staffEmp, $eoType, 1, [
            'expires_at' => '2027-01-31',
            'notes' => 'EO duty replacement 2',
        ]);
        $this->assertSame(2, $ledger->getBalance($staffEmp, 'EO'));

        // Staff submits EO leave request for 1 day
        $submitResp = $this->actingAs($fixture['staffUser'])->post(route('leave.store'), [
            'leave_type_id' => $eoType->id,
            'start_date' => '2026-11-28',
            'end_date' => '2026-11-28',
            'reason' => 'Using earned Extra Off day',
        ]);
        $submitResp->assertRedirect(route('leave.index'));

        $leaveRequest = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $staffEmp->id)
            ->firstOrFail();
        $this->assertSame('pending_manager', $leaveRequest->status);

        // admin_staff CAN approve Tier 1 for EO (blocked only for AL)
        $this->actingAs($fixture['adminStaffUser'])
            ->post(route('leave.approveTier1', $leaveRequest))
            ->assertRedirect(route('leave.index'));

        $leaveRequest->refresh();
        $this->assertSame('pending_hr', $leaveRequest->status);

        // admin_hr approves Tier 2 -> FIFO debits EO ledger
        $this->actingAs($fixture['adminHrUser'])
            ->post(route('leave.approveTier2', $leaveRequest))
            ->assertRedirect(route('leave.index'));

        $leaveRequest->refresh();
        $this->assertSame('approved', $leaveRequest->status);
        $this->assertSame(1, $ledger->getBalance($staffEmp, 'EO'));

        // Ledger history JSON includes EO balance
        $this->actingAs($fixture['adminHrUser'])
            ->getJson(route('leave.ledger.history', $staffEmp))
            ->assertOk()
            ->assertJsonPath('balances.EO.available_balance', 1);

        // /leave view displays Extra Off (EO) card
        $this->actingAs($fixture['staffUser'])
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Extra Off (EO)');

        // Expired EO bucket pruning works identically to AL/DP
        LeaveLedgerEntry::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $staffEmp->id,
            'leave_type_id' => $eoType->id,
            'transaction_type' => LeaveLedgerEntry::TYPE_ADJUSTMENT,
            'amount' => 1,
            'remaining_amount' => 1,
            'balance_after' => 2,
            'expires_at' => '2026-11-20',
            'notes' => 'Old expired EO bucket',
        ]);
        $this->artisan('leave:prune-expired')->assertSuccessful();
        $this->assertSame(1, $ledger->getBalance($staffEmp, 'EO'));
    }

    public function test_dashboard_selfie_widget_defaults_to_front_camera_with_flip_and_fallback(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['staffUser'])->get(route('dashboard'));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString("cameraFacingMode: 'user'", $html);
        $this->assertStringContainsString('navigator.mediaDevices.getUserMedia', $html);
        $this->assertStringContainsString('facingMode: this.cameraFacingMode', $html);
        $this->assertStringContainsString('video: true', $html);
        $this->assertStringContainsString('flipCamera()', $html);
        $this->assertStringContainsString('capture="user"', $html);
        $this->assertStringContainsString('id="clock-in-camera-input"', $html);
        $this->assertStringContainsString('id="clock-out-camera-input"', $html);
        $this->assertStringContainsString('id="camera-capture-input"', $html);
    }

    public function test_company_settings_validates_and_persists_auto_logout_minutes(): void
    {
        $fixture = $this->createFixture();
        $company = $fixture['company'];

        $this->assertSame(30, $company->fresh()->auto_logout_minutes);

        // Reject out-of-range (< 5 or > 1440)
        $this->actingAs($fixture['adminHrUser'])
            ->from(route('settings.company'))
            ->put(route('settings.company.update'), [
                'name' => $company->name,
                'code' => $company->code,
                'latitude' => $company->latitude,
                'longitude' => $company->longitude,
                'geofence_radius' => 150,
                'timezone' => 'Asia/Jakarta',
                'auto_logout_minutes' => 4,
            ])
            ->assertSessionHasErrors('auto_logout_minutes');

        $this->actingAs($fixture['adminHrUser'])
            ->from(route('settings.company'))
            ->put(route('settings.company.update'), [
                'name' => $company->name,
                'code' => $company->code,
                'latitude' => $company->latitude,
                'longitude' => $company->longitude,
                'geofence_radius' => 150,
                'timezone' => 'Asia/Jakarta',
                'auto_logout_minutes' => 1441,
            ])
            ->assertSessionHasErrors('auto_logout_minutes');

        // Accept valid value (15 minutes)
        $this->actingAs($fixture['adminHrUser'])
            ->put(route('settings.company.update'), [
                'name' => $company->name,
                'code' => $company->code,
                'latitude' => $company->latitude,
                'longitude' => $company->longitude,
                'geofence_radius' => 150,
                'timezone' => 'Asia/Jakarta',
                'auto_logout_minutes' => 15,
            ])
            ->assertRedirect(route('settings.company'));

        $this->assertSame(15, $company->fresh()->auto_logout_minutes);

        // App layout injects tenant timeout metadata for frontend idle monitor
        $this->actingAs($fixture['staffUser'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<meta name="session-timeout-minutes" content="15">', false);
    }

    public function test_middleware_enforces_tenant_specific_idle_session_timeout(): void
    {
        $fixture = $this->createFixture();
        $companyA = $fixture['company'];
        $companyA->update(['auto_logout_minutes' => 10]);

        $companyB = Company::create([
            'code' => 'HOTEL-B',
            'name' => 'Hotel B 60m',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'auto_logout_minutes' => 60,
            'is_active' => true,
        ]);

        $userB = User::create([
            'company_id' => $companyB->id,
            'name' => 'Hotel B Staff',
            'username' => 'user_hotel_b',
            'email' => 'user.b@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        // Within 10 minutes (5 mins ago): allowed & refreshes activity
        $this->actingAs($fixture['staffUser'])
            ->withSession(['last_activity' => time() - (5 * 60)])
            ->get(route('dashboard'))
            ->assertOk();
        $this->assertAuthenticatedAs($fixture['staffUser']);

        // Beyond 10 minutes (12 mins ago) on web request: logs out and redirects to login with warning
        $expiredWeb = $this->actingAs($fixture['staffUser'])
            ->withSession(['last_activity' => time() - (12 * 60)])
            ->get(route('dashboard'));
        $expiredWeb->assertRedirect(route('login', ['timeout' => 1]));
        $expiredWeb->assertSessionHas('error', __('auth.session_idle_timeout'));
        $this->assertGuest();

        // Beyond 10 minutes on AJAX/JSON request: logs out and returns HTTP 419 JSON
        $expiredJson = $this->actingAs($fixture['staffUser'])
            ->withSession(['last_activity' => time() - (12 * 60)])
            ->getJson(route('leave.index'));
        $expiredJson->assertStatus(419)
            ->assertJson([
                'message' => __('auth.session_idle_timeout'),
            ]);
        $this->assertGuest();

        // Tenant B (60 mins timeout) at 12 mins idle remains authenticated
        $this->actingAs($userB)
            ->withSession(['last_activity' => time() - (12 * 60)])
            ->get(route('dashboard'))
            ->assertOk();
        $this->assertAuthenticatedAs($userB);

        // Logout with reason=idle_timeout flashes localized warning and redirects to /login?timeout=1
        $logoutResp = $this->post('/logout', ['reason' => 'idle_timeout']);
        $logoutResp->assertRedirect(route('login', ['timeout' => 1]));
        $logoutResp->assertSessionHas('error', __('auth.session_idle_timeout', [], $userB->fresh()->locale ?: 'id'));

        $this->get(route('login', ['timeout' => 1]))
            ->assertOk()
            ->assertSee(__('auth.session_idle_timeout', [], $userB->fresh()->locale ?: 'id'));

        // Fresh guest visit to /login?timeout=1 renders timeout warning in active locale
        $this->flushSession();
        $this->get(route('login', ['timeout' => 1]))
            ->assertOk()
            ->assertSee(__('auth.session_idle_timeout'));
    }
}
