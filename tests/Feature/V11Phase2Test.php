<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceGeofenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class V11Phase2Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createFixture(): array
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
            'min_staff_per_shift' => 1,
            'is_executive_oversight' => false,
        ]);

        $managerUser = User::create([
            'company_id' => $company->id,
            'name' => 'Hendra FO Manager',
            'username' => 'hendra_mgr',
            'email' => 'hendra.mgr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $managerEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $managerUser->id,
            'department_id' => $foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);

        $staffUser = User::create([
            'company_id' => $company->id,
            'name' => 'Budi Receptionist',
            'username' => 'budi_staff',
            'email' => 'budi.staff@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $staffUser->id,
            'department_id' => $foDept->id,
            'manager_id' => $managerEmp->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);

        $shiftMorning = Shift::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'MO',
            'name' => 'Morning Shift',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'grace_period_minutes' => 15,
            'color_badge' => '#0EA5E9',
            'is_active' => true,
        ]);

        $shiftAfternoon = Shift::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'AF',
            'name' => 'Afternoon Shift',
            'start_time' => '15:00:00',
            'end_time' => '23:00:00',
            'grace_period_minutes' => 15,
            'color_badge' => '#F59E0B',
            'is_active' => true,
        ]);

        return compact('company', 'foDept', 'managerUser', 'managerEmp', 'staffUser', 'staffEmp', 'shiftMorning', 'shiftAfternoon');
    }

    public function test_shift_update_supports_editing_code_and_rejects_duplicate_or_unauthorized_requests(): void
    {
        $fixture = $this->createFixture();

        // Update shift code, name, times, grace, and color
        $this->actingAs($fixture['managerUser'])
            ->putJson(route('roster.shifts.update', $fixture['shiftMorning']), [
                'code' => 'm1',
                'name' => 'Morning Early',
                'start_time' => '06:30',
                'end_time' => '14:30',
                'grace_period_minutes' => 10,
                'color_badge' => '#2563EB',
            ])
            ->assertOk()
            ->assertJsonPath('shift.code', 'M1')
            ->assertJsonPath('shift.name', 'Morning Early')
            ->assertJsonPath('shift.start_time', '06:30:00')
            ->assertJsonPath('shift.end_time', '14:30:00')
            ->assertJsonPath('shift.grace_period_minutes', 10)
            ->assertJsonPath('shift.color_badge', '#2563EB');

        $this->assertDatabaseHas('shifts', [
            'id' => $fixture['shiftMorning']->id,
            'code' => 'M1',
            'name' => 'Morning Early',
        ]);

        // Reject duplicate active code within same company (422)
        $this->actingAs($fixture['managerUser'])
            ->putJson(route('roster.shifts.update', $fixture['shiftMorning']), [
                'code' => 'AF',
                'name' => 'Duplicate Code Attempt',
                'start_time' => '06:30',
                'end_time' => '14:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Regular user is forbidden (403)
        $this->actingAs($fixture['staffUser'])
            ->putJson(route('roster.shifts.update', $fixture['shiftMorning']), [
                'code' => 'HACK',
                'name' => 'Hacked',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->assertForbidden();
    }

    public function test_shift_soft_delete_blocks_active_month_draft_but_preserves_historical_schedules_and_allows_code_reuse(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 25, 9, 0, 0, 'Asia/Jakarta'));
        $fixture = $this->createFixture();

        // 1. Create an active-month draft schedule referencing shiftMorning -> delete MUST fail with 422
        $activeDraft = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['staffEmp']->id,
            'shift_id' => $fixture['shiftMorning']->id,
            'schedule_date' => '2026-11-26',
            'custom_start_time' => '07:00:00',
            'custom_end_time' => '15:00:00',
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $this->actingAs($fixture['managerUser'])
            ->deleteJson(route('roster.shifts.destroy', $fixture['shiftMorning']), [
                'month' => 11,
                'year' => 2026,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shift']);

        $this->assertNull($fixture['shiftMorning']->fresh()->deleted_at);

        // 2. Publish the schedule (now only historical/published exists, no active draft) -> soft-delete succeeds
        $activeDraft->update(['status' => 'published']);

        // Also create a historical past-month published schedule and attendance record
        $historicalSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['staffEmp']->id,
            'shift_id' => $fixture['shiftMorning']->id,
            'schedule_date' => '2026-10-15',
            'custom_start_time' => '07:00:00',
            'custom_end_time' => '15:00:00',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['staffEmp']->id,
            'work_schedule_id' => $historicalSchedule->id,
            'clock_in_at' => '2026-10-15 07:02:00',
            'status' => 'on_time',
        ]);

        // Cross-tenant manager cannot delete
        $otherCompany = Company::create([
            'code' => 'OTHER-HTL',
            'name' => 'Other Hotel',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
        $otherMgr = User::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Mgr',
            'username' => 'other_mgr',
            'email' => 'other.mgr@oatcake.test',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);
        $this->actingAs($otherMgr)
            ->deleteJson(route('roster.shifts.destroy', $fixture['shiftMorning']))
            ->assertForbidden();

        // Authorized manager soft-deletes shiftMorning
        $this->actingAs($fixture['managerUser'])
            ->deleteJson(route('roster.shifts.destroy', $fixture['shiftMorning']), [
                'month' => 11,
                'year' => 2026,
            ])
            ->assertOk()
            ->assertJsonPath('deleted_id', $fixture['shiftMorning']->id);

        // Verify soft delete (record still in DB with deleted_at, schedules NOT deleted or nullified)
        $this->assertSoftDeleted('shifts', ['id' => $fixture['shiftMorning']->id]);
        $this->assertDatabaseHas('work_schedules', [
            'id' => $historicalSchedule->id,
            'shift_id' => $fixture['shiftMorning']->id,
        ]);

        // Historical Eloquent relation & RosterController still resolve soft-deleted shift via withTrashed()
        $loadedHistorical = WorkSchedule::withoutGlobalScopes()->with('shift')->findOrFail($historicalSchedule->id);
        $this->assertNotNull($loadedHistorical->shift);
        $this->assertSame('MO', $loadedHistorical->shift->code);

        $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', ['month' => 10, 'year' => 2026]))
            ->assertOk()
            ->assertSee('MO');

        // Soft-deleted shift is excluded from active assignment & findNearestShiftForClockIn
        $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmp']->id,
                'schedule_date' => '2026-11-28',
                'shift_id' => $fixture['shiftMorning']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shift_id']);

        $geofenceService = app(AttendanceGeofenceService::class);
        $nearest = $geofenceService->findNearestShiftForClockIn(
            $fixture['staffEmp'],
            Carbon::create(2026, 11, 25, 7, 5, 0, 'Asia/Jakarta')
        );
        $this->assertNotNull($nearest);
        $this->assertSame($fixture['shiftAfternoon']->id, $nearest->id);

        // Soft-deleted shift code 'MO' can now be cleanly reused when creating a new shift
        $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.shifts.store'), [
                'code' => 'MO',
                'name' => 'Morning Reborn',
                'start_time' => '08:00',
                'end_time' => '16:00',
                'grace_period_minutes' => 15,
                'color_badge' => '#10B981',
            ])
            ->assertCreated()
            ->assertJsonPath('shift.code', 'MO')
            ->assertJsonPath('shift.name', 'Morning Reborn');
    }

    public function test_roster_renders_dual_mode_paint_and_drag_drop_plus_shift_crud_modals_and_conflict_guard(): void
    {
        $fixture = $this->createFixture();

        $html = $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', ['month' => 11, 'year' => 2026]))
            ->assertOk()
            ->getContent();

        // Click-to-Assign Paint Mode hooks & modals
        $this->assertStringContainsString('activePaintShiftId', $html);
        $this->assertStringContainsString('togglePaintShift(', $html);
        $this->assertStringContainsString('handleCellClick(', $html);
        $this->assertStringContainsString('isCellPainting(', $html);
        $this->assertStringContainsString('isCellLeaveLocked(', $html);
        $this->assertStringContainsString('edit-shift-modal', $html);
        $this->assertStringContainsString('delete-shift-modal', $html);
        $this->assertStringNotContainsString('activeShiftCode', $html);

        // Drag-and-Drop remains intact (Dual-Mode UX)
        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertStringContainsString('@dragstart="dragStartShift(', $html);
        $this->assertStringContainsString('@drop.prevent="dropOnCell(', $html);

        // Overwrite existing single-shift cell in-place via assign endpoint (used by Click-to-Assign)
        $initialResp = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmp']->id,
                'schedule_date' => '2026-11-20',
                'shift_id' => $fixture['shiftMorning']->id,
            ])
            ->assertOk();

        $scheduleId = $initialResp->json('schedule.id');

        $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'schedule_id' => $scheduleId,
                'employee_id' => $fixture['staffEmp']->id,
                'schedule_date' => '2026-11-20',
                'shift_id' => $fixture['shiftAfternoon']->id,
            ])
            ->assertOk()
            ->assertJsonPath('schedule.id', $scheduleId)
            ->assertJsonPath('schedule.shift_code', 'AF');

        // Conflict Guard blocks overwriting a LEAVE: locked schedule
        $leaveSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['company']->id,
            'employee_id' => $fixture['staffEmp']->id,
            'shift_id' => null,
            'schedule_date' => '2026-11-21',
            'status' => 'on_leave',
            'notes' => 'LEAVE:AL:1|Annual Leave: Family trip',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'schedule_id' => $leaveSchedule->id,
                'employee_id' => $fixture['staffEmp']->id,
                'schedule_date' => '2026-11-21',
                'shift_id' => $fixture['shiftMorning']->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_date']);
    }
}
