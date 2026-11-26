<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class V11Phase1Test extends TestCase
{
    use RefreshDatabase;

    protected function createTenantFixture(): array
    {
        $company = Company::create([
            'code' => 'NOVOTEL-YIA',
            'name' => 'Novotel Yogyakarta International Airport',
            'latitude' => -7.9048250,
            'longitude' => 110.0572110,
            'geofence_radius' => 150,
            'geofence_radius_meters' => 150,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $foDept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'FO',
            'name' => 'Front Office',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $hkDept = Department::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'HK',
            'name' => 'Housekeeping',
            'is_operational' => true,
            'min_staff_per_shift' => 2,
            'is_executive_oversight' => false,
        ]);

        $hrAdminUser = User::create([
            'company_id' => $company->id,
            'name' => 'Diana HR Admin',
            'username' => 'diana_admin_hr',
            'email' => 'diana.hr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin_hr',
            'is_active' => true,
        ]);
        $hrAdminEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $hrAdminUser->id,
            'department_id' => $foDept->id,
            'position' => 'HR Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $foManagerUser = User::create([
            'company_id' => $company->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra_mgr',
            'email' => 'hendra.mgr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);
        $foManagerEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $foManagerUser->id,
            'department_id' => $foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-02-01',
            'status' => 'active',
        ]);

        $foSupervisorUser = User::create([
            'company_id' => $company->id,
            'name' => 'Raka Duty Supervisor',
            'username' => 'raka_spv',
            'email' => 'raka.spv@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin_staff',
            'is_active' => true,
        ]);
        $foSupervisorEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $foSupervisorUser->id,
            'department_id' => $foDept->id,
            'manager_id' => $foManagerEmp->id,
            'position' => 'Front Office Supervisor',
            'join_date' => '2024-03-01',
            'status' => 'active',
        ]);

        $foStaffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Budi Receptionist',
            'username' => 'budi_staff',
            'email' => 'budi.staff@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);
        $foStaffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $foStaffUser->id,
            'department_id' => $foDept->id,
            'manager_id' => $foManagerEmp->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $hkStaffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Sari Room Attendant',
            'username' => 'sari_hk',
            'email' => 'sari.hk@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);
        $hkStaffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $hkStaffUser->id,
            'department_id' => $hkDept->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-02-01',
            'status' => 'active',
        ]);

        return compact(
            'company',
            'foDept',
            'hkDept',
            'hrAdminUser',
            'hrAdminEmp',
            'foManagerUser',
            'foManagerEmp',
            'foSupervisorUser',
            'foSupervisorEmp',
            'foStaffUser',
            'foStaffEmp',
            'hkStaffUser',
            'hkStaffEmp'
        );
    }

    public function test_rbac_six_tier_hierarchy_ranks_and_migration_conversion(): void
    {
        $this->assertSame([
            'user' => 1,
            'admin_staff' => 2,
            'manager' => 3,
            'admin_hr' => 4,
            'super_user' => 5,
            'super_admin' => 6,
        ], User::ROLE_RANKS);

        $fixture = $this->createTenantFixture();
        $this->assertTrue($fixture['hrAdminUser']->isAdminHr());
        $this->assertTrue($fixture['hrAdminUser']->isAdminOrAbove());
        $this->assertTrue($fixture['foSupervisorUser']->isAdminStaff());
        $this->assertTrue($fixture['foSupervisorUser']->isSupervisorOrManager());
        $this->assertTrue($fixture['foSupervisorUser']->isSupervisorOrAbove());
        $this->assertFalse($fixture['foSupervisorUser']->isManagerOrAbove());
        $this->assertFalse($fixture['foSupervisorUser']->isAdminOrAbove());

        // Verify CHECK constraint allows admin_hr and admin_staff and rejects legacy 'admin'
        $this->expectException(\Throwable::class);
        DB::table('users')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $fixture['company']->id,
            'name' => 'Legacy Admin',
            'username' => 'legacy_admin',
            'email' => 'legacy.admin@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rbac_migration_down_and_up_safely_renames_legacy_admin_to_admin_hr(): void
    {
        $fixture = $this->createTenantFixture();

        $migration = require database_path('migrations/2026_11_14_000001_rename_admin_to_admin_hr_and_add_admin_staff.php');

        // Roll back to pre-V1.1 schema/data state
        $migration->down();
        $this->assertSame('admin', $fixture['hrAdminUser']->fresh()->role);
        $this->assertSame('user', $fixture['foSupervisorUser']->fresh()->role);

        // Re-run forward migration
        $migration->up();
        $this->assertSame('admin_hr', $fixture['hrAdminUser']->fresh()->role);
    }

    public function test_admin_staff_can_draft_and_publish_roster_in_own_department_only(): void
    {
        $fixture = $this->createTenantFixture();
        $shift = Shift::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'code' => 'M1',
            'name' => 'Morning Shift',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'grace_period_minutes' => 15,
            'is_active' => true,
        ]);

        // Can assign draft in own department (FO)
        $this->actingAs($fixture['foSupervisorUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['foStaffEmp']->id,
                'schedule_date' => '2026-11-26',
                'shift_id' => $shift->id,
                'override_turnaround' => true,
            ])
            ->assertOk()
            ->assertJsonPath('schedule.status', 'draft');

        // Forbidden from assigning shift to staff in another department (HK)
        $this->actingAs($fixture['foSupervisorUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['hkStaffEmp']->id,
                'schedule_date' => '2026-11-26',
                'shift_id' => $shift->id,
                'override_turnaround' => true,
            ])
            ->assertForbidden();

        // Can publish own department drafts
        $this->actingAs($fixture['foSupervisorUser'])
            ->postJson(route('roster.publish'), [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ])
            ->assertOk()
            ->assertJsonPath('published_count', 1);

        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $fixture['foStaffEmp']->id,
            'status' => 'published',
        ]);
    }

    public function test_admin_staff_can_verify_outside_duty_attendance_in_own_department_only(): void
    {
        $fixture = $this->createTenantFixture();

        $foOutsideAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['foStaffEmp']->id,
            'clock_in_at' => '2026-11-25 08:00:00',
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
            'outside_location_name' => 'Airport Terminal',
            'late_minutes' => 0,
        ]);

        $hkOutsideAttendance = Attendance::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['hkStaffEmp']->id,
            'clock_in_at' => '2026-11-25 08:05:00',
            'status' => 'pending_verification',
            'is_outside_geofence' => true,
            'outside_location_name' => 'Laundry Vendor',
            'late_minutes' => 0,
        ]);

        $this->actingAs($fixture['foSupervisorUser'])
            ->get(route('attendance.verifications.index'))
            ->assertOk()
            ->assertSee('Budi Receptionist')
            ->assertDontSee('Sari Room Attendant');

        $this->actingAs($fixture['foSupervisorUser'])
            ->postJson(route('attendance.verifications.approve', $foOutsideAttendance))
            ->assertOk()
            ->assertJsonPath('data.status', 'on_time');

        $this->actingAs($fixture['foSupervisorUser'])
            ->postJson(route('attendance.verifications.approve', $hkOutsideAttendance))
            ->assertForbidden();
    }

    public function test_admin_staff_can_process_dp_leave_but_is_strictly_forbidden_from_annual_leave_al(): void
    {
        $fixture = $this->createTenantFixture();

        $alType = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'code' => 'AL',
            'name' => 'Annual Leave',
            'requires_quota' => true,
            'default_annual_quota' => 12,
        ]);

        $dpType = LeaveType::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'code' => 'DP',
            'name' => 'Day of Payment',
            'requires_quota' => true,
            'default_annual_quota' => 0,
        ]);

        $alRequest = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['foStaffEmp']->id,
            'leave_type_id' => $alType->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'total_days' => 2,
            'reason' => 'Family vacation',
            'status' => LeaveRequest::STATUS_PENDING_MANAGER,
        ]);

        $dpRequest = LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['foStaffEmp']->id,
            'leave_type_id' => $dpType->id,
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-05',
            'total_days' => 1,
            'reason' => 'Off in lieu',
            'status' => LeaveRequest::STATUS_PENDING_MANAGER,
        ]);

        $spv = $fixture['foSupervisorUser'];

        // Strictly forbidden on Annual Leave (AL)
        $this->assertFalse(Gate::forUser($spv)->allows('approveTier1', $alRequest));
        $this->assertFalse(Gate::forUser($spv)->allows('approveTier2', $alRequest));
        $this->assertFalse(Gate::forUser($spv)->allows('reject', $alRequest));

        $this->actingAs($spv)
            ->postJson(route('leave.approveTier1', $alRequest), ['notes' => 'Trying AL'])
            ->assertForbidden();

        $this->actingAs($spv)
            ->postJson(route('leave.reject', $alRequest), ['reason' => 'Rejecting AL attempt'])
            ->assertForbidden();

        // Allowed on Tier 1 for DP leave in own department, but never Tier 2
        $this->assertTrue(Gate::forUser($spv)->allows('approveTier1', $dpRequest));
        $this->assertFalse(Gate::forUser($spv)->allows('approveTier2', $dpRequest));

        $this->actingAs($spv)
            ->postJson(route('leave.approveTier1', $dpRequest), ['notes' => 'Supervisor DP OK'])
            ->assertOk();

        $dpRequest->refresh();
        $this->assertSame(LeaveRequest::STATUS_PENDING_HR, $dpRequest->status);

        // Admin Staff cannot execute Tier 2 even on DP
        $this->actingAs($spv)
            ->postJson(route('leave.approveTier2', $dpRequest), ['notes' => 'Trying Tier 2'])
            ->assertForbidden();

        // Admin Staff cannot access /staff, /settings/company, or /audit-logs
        $this->actingAs($spv)->get(route('staff.index'))->assertForbidden();
        $this->actingAs($spv)->get(route('settings.company'))->assertForbidden();
        $this->actingAs($spv)->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_new_hotel_company_automatically_seeds_daily_worker_and_casual_tags_and_supports_crud(): void
    {
        $fixture = $this->createTenantFixture();
        $company = $fixture['company'];

        // Auto-seeded on Company::create
        $this->assertDatabaseHas('tags', [
            'company_id' => $company->id,
            'code' => 'DAILY_WORKER',
            'name' => 'Daily Worker',
        ]);
        $this->assertDatabaseHas('tags', [
            'company_id' => $company->id,
            'code' => 'CASUAL',
            'name' => 'Casual',
        ]);

        // Settings page shows Tag Karyawan section and seeded tags
        $this->actingAs($fixture['hrAdminUser'])
            ->get(route('settings.company'))
            ->assertOk()
            ->assertSee(__('company.cat_tags'))
            ->assertSee('Daily Worker')
            ->assertSee('Casual');

        // Create custom tag
        $createResp = $this->actingAs($fixture['hrAdminUser'])
            ->postJson(route('settings.tags.store'), [
                'name' => 'Trainee Intern',
                'badge_color' => '#14B8A6',
            ]);

        $createResp->assertCreated()
            ->assertJsonPath('tag.code', 'TRAINEE_INTERN')
            ->assertJsonPath('tag.name', 'Trainee Intern');

        $tagId = (int) $createResp->json('tag.id');

        // Duplicate code rejected with 422
        $this->actingAs($fixture['hrAdminUser'])
            ->postJson(route('settings.tags.store'), [
                'name' => 'Trainee Duplicate',
                'code' => 'TRAINEE_INTERN',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Update tag
        $this->actingAs($fixture['hrAdminUser'])
            ->putJson(route('settings.tags.update', $tagId), [
                'name' => 'Hospitality Trainee',
                'code' => 'TRAINEE',
                'badge_color' => '#2563EB',
            ])
            ->assertOk()
            ->assertJsonPath('tag.code', 'TRAINEE')
            ->assertJsonPath('tag.name', 'Hospitality Trainee');

        // Manager / Admin Staff / User cannot manage company tags
        $this->actingAs($fixture['foManagerUser'])
            ->postJson(route('settings.tags.store'), ['name' => 'Unauthorized'])
            ->assertForbidden();

        // Cross-tenant Admin HR cannot modify or delete another company's tag
        $otherCompany = Company::create([
            'code' => 'OTHER-HTL',
            'name' => 'Other Hotel',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        $otherAdmin = User::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other HR',
            'username' => 'other_hr',
            'email' => 'other.hr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin_hr',
            'is_active' => true,
        ]);
        $this->actingAs($otherAdmin)
            ->deleteJson(route('settings.tags.destroy', $tagId))
            ->assertForbidden();

        // Delete tag cleans up pivot and record
        $this->actingAs($fixture['hrAdminUser'])
            ->deleteJson(route('settings.tags.destroy', $tagId))
            ->assertOk();

        $this->assertDatabaseMissing('tags', ['id' => $tagId]);
    }

    public function test_staff_directory_advanced_filters_and_simultaneous_search_with_zero_nik(): void
    {
        $fixture = $this->createTenantFixture();
        $dwTag = Tag::withoutGlobalScopes()
            ->where('company_id', $fixture['company']->id)
            ->where('code', 'DAILY_WORKER')
            ->firstOrFail();
        $casualTag = Tag::withoutGlobalScopes()
            ->where('company_id', $fixture['company']->id)
            ->where('code', 'CASUAL')
            ->firstOrFail();

        $fixture['foStaffEmp']->tags()->sync([$dwTag->id]);
        $fixture['hkStaffEmp']->update(['status' => 'on_leave']);
        $fixture['hkStaffEmp']->tags()->sync([$casualTag->id]);

        $inactiveUser = User::create([
            'company_id' => $fixture['company']->id,
            'name' => 'Tono Inactive DW',
            'username' => 'tono_dw',
            'email' => 'tono.dw@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => false,
        ]);
        $inactiveEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'user_id' => $inactiveUser->id,
            'department_id' => $fixture['foDept']->id,
            'position' => 'Bellman DW',
            'join_date' => '2025-04-01',
            'status' => 'inactive',
        ]);
        $inactiveEmp->tags()->sync([$dwTag->id]);

        // 1. Filter by status = on_leave
        $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.index', ['status' => 'on_leave']))
            ->assertOk()
            ->assertSee('Sari Room Attendant')
            ->assertDontSee('Budi Receptionist')
            ->assertDontSee('Tono Inactive DW');

        // 2. Filter by role = admin_staff
        $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.index', ['role' => 'admin_staff']))
            ->assertOk()
            ->assertSee('Raka Duty Supervisor')
            ->assertDontSee('Budi Receptionist');

        // 3. Filter simultaneously by department_id + status + tag_id + search
        $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.index', [
                'department_id' => $fixture['foDept']->id,
                'status' => 'inactive',
                'tag_id' => $dwTag->id,
                'search' => 'tono',
            ]))
            ->assertOk()
            ->assertSee('Tono Inactive DW')
            ->assertDontSee('Budi Receptionist')
            ->assertDontSee('Sari Room Attendant')
            ->assertDontSee('name="nik"', false);

        // 4. Manager remains locked to own department even when passing ?department_id of another department
        $this->actingAs($fixture['foManagerUser'])
            ->get(route('staff.index', ['department_id' => $fixture['hkDept']->id]))
            ->assertOk()
            ->assertSee('Budi Receptionist')
            ->assertDontSee('Sari Room Attendant');
    }

    public function test_staff_create_and_edit_render_alpine_searchable_manager_combobox_and_assign_tags(): void
    {
        $fixture = $this->createTenantFixture();
        $dwTag = Tag::withoutGlobalScopes()
            ->where('company_id', $fixture['company']->id)
            ->where('code', 'DAILY_WORKER')
            ->firstOrFail();

        $createHtml = $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type="hidden" id="manager_id" name="manager_id"', $createHtml);
        $this->assertStringNotContainsString('<select id="manager_id" name="manager_id"', $createHtml);
        $this->assertStringContainsString('Hendra FO Manager', $createHtml);
        $this->assertStringContainsString('Daily Worker', $createHtml);
        $this->assertStringContainsString('ADMIN STAFF', strtoupper($createHtml));

        $editHtml = $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.edit', $fixture['foStaffEmp']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type="hidden" id="manager_id" name="manager_id"', $editHtml);
        $this->assertStringNotContainsString('<select id="manager_id" name="manager_id"', $editHtml);
        $this->assertStringContainsString('Hendra FO Manager', $editHtml);

        // Submit staff creation with admin_staff role and Daily Worker tag
        $this->actingAs($fixture['hrAdminUser'])
            ->post(route('staff.store'), [
                'name' => 'Dina Casual Staff',
                'username' => 'dina_casual',
                'email' => 'dina.casual@oatcake.test',
                'department_id' => $fixture['foDept']->id,
                'position' => 'Banquet Casual',
                'join_date' => '2026-11-25',
                'role' => 'admin_staff',
                'manager_id' => $fixture['foManagerEmp']->id,
                'tags' => [$dwTag->id],
                'password' => '',
            ])
            ->assertRedirect(route('staff.index'));

        $createdUser = User::where('username', 'dina_casual')->firstOrFail();
        $this->assertSame('admin_staff', $createdUser->role);
        $createdEmp = Employee::withoutGlobalScopes()->where('user_id', $createdUser->id)->firstOrFail();
        $this->assertSame((int) $fixture['foManagerEmp']->id, (int) $createdEmp->manager_id);
        $this->assertDatabaseHas('employee_tags', [
            'employee_id' => $createdEmp->id,
            'tag_id' => $dwTag->id,
        ]);
    }

    public function test_staff_create_and_edit_render_generic_role_searchable_select_and_dynamic_role_hierarchy_modal(): void
    {
        $fixture = $this->createTenantFixture();

        $superUser = User::create([
            'company_id' => $fixture['company']->id,
            'name' => 'GM Super User',
            'username' => 'gm_super_user_guide',
            'email' => 'gm.guide@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'super_user',
            'is_active' => true,
        ]);

        // 1. Verify searchable-select renders for both role and manager_id on create & edit without <select id="role"> or nik
        $createAdminHtml = $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type="hidden" id="role" name="role"', $createAdminHtml);
        $this->assertStringNotContainsString('<select id="role" name="role"', $createAdminHtml);
        $this->assertStringContainsString('role-hierarchy-guide-modal', $createAdminHtml);
        $this->assertStringContainsString(__('staff.role_guide_modal_title'), $createAdminHtml);
        $this->assertStringNotContainsString('name="nik"', $createAdminHtml);

        $editAdminHtml = $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.edit', $fixture['foStaffEmp']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('type="hidden" id="role" name="role"', $editAdminHtml);
        $this->assertStringNotContainsString('<select id="role" name="role"', $editAdminHtml);
        $this->assertStringContainsString('role-hierarchy-guide-modal', $editAdminHtml);
        $this->assertStringNotContainsString('name="nik"', $editAdminHtml);

        // 2. Dynamic role hierarchy filtering: Manager (rank 3) sees manager, admin_staff, user ONLY
        foreach ([route('staff.create'), route('staff.edit', $fixture['foStaffEmp'])] as $url) {
            $this->actingAs($fixture['foManagerUser'])
                ->get($url)
                ->assertOk()
                ->assertSee(__('staff.role_guide_desc_manager'))
                ->assertSee(__('staff.role_guide_desc_admin_staff'))
                ->assertSee(__('staff.role_guide_desc_user'))
                ->assertDontSee(__('staff.role_guide_desc_admin_hr'))
                ->assertDontSee(__('staff.role_guide_desc_super_user'));
        }

        // 3. Dynamic role hierarchy filtering: Admin HR (rank 4) sees admin_hr..user, NOT super_user
        $this->actingAs($fixture['hrAdminUser'])
            ->get(route('staff.create'))
            ->assertOk()
            ->assertSee(__('staff.role_guide_desc_admin_hr'))
            ->assertSee(__('staff.role_guide_desc_manager'))
            ->assertSee(__('staff.role_guide_desc_admin_staff'))
            ->assertSee(__('staff.role_guide_desc_user'))
            ->assertDontSee(__('staff.role_guide_desc_super_user'));

        // 4. Dynamic role hierarchy filtering: Super User (rank 5) sees all 5 tenant roles
        $this->actingAs($superUser)
            ->get(route('staff.create'))
            ->assertOk()
            ->assertSee(__('staff.role_guide_desc_super_user'))
            ->assertSee(__('staff.role_guide_desc_admin_hr'))
            ->assertSee(__('staff.role_guide_desc_manager'))
            ->assertSee(__('staff.role_guide_desc_admin_staff'))
            ->assertSee(__('staff.role_guide_desc_user'));
    }
}
