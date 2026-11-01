<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\CompanySeeder;
use Database\Seeders\NovotelOrganizationSeeder;
use Database\Seeders\NovotelShiftSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RosterBusinessLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpBusinessLogicFixture(): array
    {
        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
        ]);

        $hotel = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();
        $foDept = Department::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'FO')->firstOrFail();
        $hkDept = Department::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'HK')->firstOrFail();
        $modTag = Tag::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'MOD_ELIGIBLE')->firstOrFail();

        $shiftMO = Shift::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'MO')->firstOrFail();
        $shiftNI = Shift::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'NI')->firstOrFail();
        $shiftMOD = Shift::withoutGlobalScopes()->where('company_id', $hotel->id)->where('code', 'MOD')->firstOrFail();

        $managerUser = User::create([
            'company_id' => $hotel->id,
            'name' => 'Hendra Wijaya',
            'username' => 'hendra_fo_mgr',
            'email' => 'hendra.wijaya@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'manager',
            'is_active' => true,
        ]);

        $managerEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'user_id' => $managerUser->id,
            'department_id' => $foDept->id,
            'position' => 'Front Office Manager',
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
        $managerEmployee->tags()->sync([$modTag->id]);

        $staffUser1 = User::create([
            'company_id' => $hotel->id,
            'name' => 'Rina Kusuma',
            'username' => 'rina_kusuma',
            'email' => 'rina.kusuma@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmployee1 = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'user_id' => $staffUser1->id,
            'department_id' => $foDept->id,
            'manager_id' => $managerEmployee->id,
            'position' => 'Guest Service Agent',
            'join_date' => '2025-02-01',
            'status' => 'active',
        ]);
        $staffEmployee1->tags()->sync([$modTag->id]);

        $staffUser2 = User::create([
            'company_id' => $hotel->id,
            'name' => 'Budi Pratama',
            'username' => 'budi_pratama',
            'email' => 'budi.pratama@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $staffEmployee2 = Employee::withoutGlobalScopes()->create([
            'company_id' => $hotel->id,
            'user_id' => $staffUser2->id,
            'department_id' => $foDept->id,
            'manager_id' => $managerEmployee->id,
            'position' => 'Bellboy',
            'join_date' => '2025-03-01',
            'status' => 'active',
        ]);
        // Budi Pratama sengaja TIDAK diberi tag MOD_ELIGIBLE

        return compact(
            'hotel',
            'foDept',
            'hkDept',
            'modTag',
            'shiftMO',
            'shiftNI',
            'shiftMOD',
            'managerUser',
            'managerEmployee',
            'staffUser1',
            'staffEmployee1',
            'staffUser2',
            'staffEmployee2'
        );
    }

    /**
     * Skenario 1: Penugasan shift dengan jeda < 8 jam mengembalikan warning HTTP 422 saat override_warning bernilai false.
     */
    public function test_scenario_1_turnaround_less_than_8_hours_returns_422_warning_when_override_is_false(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // Jadwalkan shift NI (23:00 - 07:00 lintas hari) pada tanggal 2026-11-10
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftNI']->id,
            'schedule_date' => '2026-11-10',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // Coba tugaskan shift MO (07:00 - 15:00) pada 2026-11-11 tanpa override_warning
        $response = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee1']->id,
                'schedule_date' => '2026-11-11',
                'shift_id' => $fixture['shiftMO']->id,
                'override_warning' => false,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('warning', true)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('rest_hours', 0);

        $this->assertDatabaseMissing('work_schedules', [
            'employee_id' => $fixture['staffEmployee1']->id,
            'schedule_date' => '2026-11-11',
        ]);
    }

    /**
     * Skenario 2: Penugasan shift dengan jeda < 8 jam berhasil tersimpan ke database saat override_warning bernilai true (non-blocking).
     */
    public function test_scenario_2_turnaround_less_than_8_hours_succeeds_when_override_warning_is_true(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // Jadwalkan shift NI (23:00 - 07:00 lintas hari) pada tanggal 2026-11-10
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftNI']->id,
            'schedule_date' => '2026-11-10',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // Kirim ulang penugasan MO pada 2026-11-11 dengan override_warning: true
        $response = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.assign'), [
                'employee_id' => $fixture['staffEmployee1']->id,
                'schedule_date' => '2026-11-11',
                'shift_id' => $fixture['shiftMO']->id,
                'override_warning' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('schedule.employee_id', $fixture['staffEmployee1']->id)
            ->assertJsonPath('schedule.schedule_date', '2026-11-11')
            ->assertJsonPath('schedule.shift_code', 'MO')
            ->assertJsonPath('schedule.has_turnaround_warning', true)
            ->assertJsonPath('schedule.rest_hours', 0);

        $this->assertDatabaseHas('work_schedules', [
            'employee_id' => $fixture['staffEmployee1']->id,
            'schedule_date' => '2026-11-11',
            'shift_id' => $fixture['shiftMO']->id,
        ]);
    }

    /**
     * Skenario 3: Deteksi celah formasi mendeteksi kekurangan staf pada departemen 24 jam.
     */
    public function test_scenario_3_formation_gap_detector_identifies_understaffed_24h_shifts(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // Departemen Front Office: is_operational = true, min_staff_per_shift = 2
        $this->assertTrue((bool) $fixture['foDept']->is_24_hours);
        $this->assertSame(2, $fixture['foDept']->min_staff_per_shift);

        // Tugaskan hanya 1 staf (Rina) pada shift MO pada 2026-11-15 (masih kurang 1 dari kuota 2)
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-15',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $response = $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ]));

        $response->assertOk();
        $formationByDate = $response->viewData('formationByDate');

        $this->assertArrayHasKey('2026-11-15', $formationByDate);
        $this->assertTrue($formationByDate['2026-11-15']['has_gap']);
        $this->assertSame(2, $formationByDate['2026-11-15']['min_quota']);
        $this->assertSame(1, $formationByDate['2026-11-15']['counts']['MO']);
        $this->assertSame(0, $formationByDate['2026-11-15']['counts']['NI']);

        $response->assertSee('GAP');
    }

    /**
     * Skenario 4: Filter MOD mengisolasi baris staf bertag MOD_ELIGIBLE dan memvalidasi alokasi shift MOD.
     */
    public function test_scenario_4_mod_filter_isolates_mod_eligible_staff_and_tracks_daily_mod_allocation(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // Beri penugasan MOD pada Hendra (Manager yang memiliki tag MOD_ELIGIBLE) pada 2026-11-16
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['managerEmployee']->id,
            'shift_id' => $fixture['shiftMOD']->id,
            'duty_tag_id' => $fixture['modTag']->id,
            'schedule_date' => '2026-11-16',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // Panggil endpoint dengan filter ?mod_only=1
        $response = $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
                'mod_only' => 1,
            ]));

        $response->assertOk();
        $employeesInView = $response->viewData('employees');

        // Harus memuat Hendra dan Rina (keduanya memiliki MOD_ELIGIBLE)
        $this->assertTrue($employeesInView->contains('id', $fixture['managerEmployee']->id));
        $this->assertTrue($employeesInView->contains('id', $fixture['staffEmployee1']->id));

        // Harus menyaring keluar Budi Pratama (tidak punya MOD_ELIGIBLE)
        $this->assertFalse($employeesInView->contains('id', $fixture['staffEmployee2']->id));

        // Verifikasi audit harian MOD pada 2026-11-16 berstatus is_covered = true
        $modAuditByDate = $response->viewData('modAuditByDate');
        $this->assertTrue($modAuditByDate['2026-11-16']['is_covered']);
        $this->assertSame(1, $modAuditByDate['2026-11-16']['count']);
    }

    /**
     * Skenario 5: Aksi publikasi mengubah seluruh jadwal draft menjadi published; user biasa tidak dapat melihat jadwal berstatus draft.
     */
    public function test_scenario_5_publish_lifecycle_transitions_draft_to_published_and_hides_drafts_from_regular_users(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // Buat 2 jadwal berstatus DRAFT pada bulan November 2026
        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-18',
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee2']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-19',
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 5a. Staf biasa (role: user) mengakses GET /roster -> jadwal DRAFT tidak boleh terlihat
        $userGetResponse = $this->actingAs($fixture['staffUser1'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
            ]));

        $userGetResponse->assertOk();
        $userSchedules = $userGetResponse->viewData('schedulesMatrix');
        $this->assertEmpty($userSchedules[$fixture['staffEmployee1']->id] ?? []);

        // 5b. Staf biasa memanggil POST /roster/publish -> harus ditolak 403 Forbidden
        $userPublishResponse = $this->actingAs($fixture['staffUser1'])
            ->postJson(route('roster.publish'), [
                'month' => 11,
                'year' => 2026,
            ]);
        $userPublishResponse->assertForbidden();

        // 5c. Manager memanggil POST /roster/publish -> jadwal beralih dari draft ke published
        $managerPublishResponse = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.publish'), [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ]);

        $managerPublishResponse->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('count', 2);

        $this->assertSame(2, WorkSchedule::withoutGlobalScopes()->where('status', 'published')->count());
        $this->assertSame(0, WorkSchedule::withoutGlobalScopes()->where('status', 'draft')->count());

        // 5d. Sekarang staf biasa mengakses GET /roster -> jadwal kini terlihat
        $userVisibleResponse = $this->actingAs($fixture['staffUser1'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
            ]));

        $userVisibleResponse->assertOk();
        $updatedUserSchedules = $userVisibleResponse->viewData('schedulesMatrix');
        $this->assertNotEmpty($updatedUserSchedules[$fixture['staffEmployee1']->id]['2026-11-18'] ?? []);
        $this->assertSame('published', $updatedUserSchedules[$fixture['staffEmployee1']->id]['2026-11-18'][0]['status']);
    }

    /**
     * Skenario 6: Jadwal yang dihapus dalam kondisi published berubah status menjadi pending_delete (ghost),
     * dapat di-restore kembali ke published, dan benar-benar terhapus permanen saat publish dieksekusi.
     */
    public function test_scenario_6_published_schedule_deletion_becomes_pending_delete_ghost_and_purges_on_publish(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // 6a. Buat 1 jadwal published dan 1 jadwal draft
        $publishedSched = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-20',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $draftSched = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftNI']->id,
            'schedule_date' => '2026-11-21',
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 6b. Hapus jadwal yang masih 'draft' -> langsung terhapus dari database
        $deleteDraftResponse = $this->actingAs($fixture['managerUser'])
            ->deleteJson(route('roster.destroy', $draftSched));

        $deleteDraftResponse->assertOk()
            ->assertJsonPath('action', 'deleted');
        $this->assertDatabaseMissing('work_schedules', ['id' => $draftSched->id]);

        // 6c. Hapus jadwal yang sudah 'published' -> status berubah menjadi 'pending_delete' (tidak hilang dari DB)
        $deletePublishedResponse = $this->actingAs($fixture['managerUser'])
            ->deleteJson(route('roster.destroy', $publishedSched));

        $deletePublishedResponse->assertOk()
            ->assertJsonPath('action', 'pending_delete')
            ->assertJsonPath('schedule.status', 'pending_delete');

        $this->assertDatabaseHas('work_schedules', [
            'id' => $publishedSched->id,
            'status' => 'pending_delete',
        ]);

        // 6d. Uji batalkan hapus (restore) -> kembali ke 'published'
        $restoreResponse = $this->actingAs($fixture['managerUser'])
            ->patchJson(route('roster.restore', $publishedSched));

        $restoreResponse->assertOk()
            ->assertJsonPath('action', 'restored')
            ->assertJsonPath('schedule.status', 'published');

        // 6e. Tandai kembali sebagai 'pending_delete', lalu tekan tombol Publish -> terhapus permanen dari DB
        $this->actingAs($fixture['managerUser'])->deleteJson(route('roster.destroy', $publishedSched));
        $this->assertDatabaseHas('work_schedules', [
            'id' => $publishedSched->id,
            'status' => 'pending_delete',
        ]);

        $publishPurgeResponse = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.publish'), [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ]);

        $publishPurgeResponse->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertDatabaseMissing('work_schedules', ['id' => $publishedSched->id]);
    }

    /**
     * Skenario 7: Antarmuka Gantt Chart merender komponen peningkatan UX (hover tooltip palet, linimasa 24 jam, sambungan lintas hari, ghost pending_delete, dan eliminasi kuas).
     */
    public function test_scenario_7_gantt_ui_renders_tooltips_24h_continuation_pending_delete_and_eliminates_brush(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        $response = $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ]));

        $response->assertOk()
            ->assertSee('role="tooltip"', escape: false)
            ->assertSee('data-shift-id="' . $fixture['shiftMO']->id . '"', escape: false)
            ->assertSee('toggleDateExpand(', escape: false)
            ->assertSee('getOvernightCarryovers(', escape: false)
            ->assertSee('toggleStaffColumn()', escape: false)
            ->assertSee('getScheduleBarStyle(', escape: false)
            ->assertSee('opacity-30 dark:opacity-20 line-through grayscale border border-dashed border-red-500 bg-red-500/10', escape: false)
            ->assertSee('opacity-70 dark:opacity-65 border-dashed border border-amber-400/70', escape: false)
            ->assertSee('modal-title-shift-settings-modal', escape: false)
            ->assertDontSee('activeShiftCode', escape: false);
    }

    /**
     * Skenario 8: Tag VIEW_ALL_SCHEDULES memberikan akses Read-Only lintas departemen pada GET /roster?department_id=...
     * kepada Manager atau Staf biasa yang memegang tag tersebut.
     */
    public function test_scenario_8_view_all_schedules_tag_allows_cross_department_read_access(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        $viewAllTag = Tag::withoutGlobalScopes()
            ->where('company_id', $fixture['hotel']->id)
            ->where('code', 'VIEW_ALL_SCHEDULES')
            ->firstOrFail();

        // Buat staf di Departemen HK (Housekeeping) beserta jadwal published
        $hkUser = User::create([
            'company_id' => $fixture['hotel']->id,
            'name' => 'Slamet Riyadi HK',
            'username' => 'slamet_hk',
            'email' => 'slamet.hk@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'user_id' => $hkUser->id,
            'department_id' => $fixture['hkDept']->id,
            'position' => 'Room Attendant',
            'join_date' => '2025-04-01',
            'status' => 'active',
        ]);

        WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $hkEmployee->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-14',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 8a. Tanpa tag VIEW_ALL_SCHEDULES, Manager FO tidak dapat melihat staf HK walaupun mengirim ?department_id=HK
        $withoutTagResponse = $this->actingAs($fixture['managerUser'])
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['hkDept']->id,
            ]));

        $withoutTagResponse->assertOk()
            ->assertDontSee('Slamet Riyadi HK');

        // 8b. Sematkan tag VIEW_ALL_SCHEDULES ke Manager FO & Staf FO
        $fixture['managerEmployee']->tags()->syncWithoutDetaching([$viewAllTag->id]);
        $fixture['staffEmployee1']->tags()->syncWithoutDetaching([$viewAllTag->id]);

        // 8c. Sekarang Manager FO dapat melihat kalender Departemen HK beserta dropdown filter departemen
        $managerWithTagResponse = $this->actingAs($fixture['managerUser']->fresh())
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['hkDept']->id,
            ]));

        $managerWithTagResponse->assertOk()
            ->assertSee('Slamet Riyadi HK')
            ->assertSee('name="department_id"', escape: false);

        // 8d. Staf FO (role: user) dengan tag VIEW_ALL_SCHEDULES juga dapat melihat jadwal published Departemen HK
        $staffWithTagResponse = $this->actingAs($fixture['staffUser1']->fresh())
            ->get(route('roster.index', [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['hkDept']->id,
            ]));

        $staffWithTagResponse->assertOk()
            ->assertSee('Slamet Riyadi HK')
            ->assertSee('name="department_id"', escape: false);
    }

    /**
     * Skenario 9: Tag VIEW_ALL_SCHEDULES murni bersifat Read-Only lintas departemen;
     * Manager Departemen FO yang memiliki tag VIEW_ALL_SCHEDULES tetap ditolak (403 Forbidden)
     * saat mencoba melakukan assign atau destroy terhadap staf Departemen HK.
     */
    public function test_scenario_9_view_all_schedules_tag_enforces_strict_read_only_for_cross_department_mutations(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        $viewAllTag = Tag::withoutGlobalScopes()
            ->where('company_id', $fixture['hotel']->id)
            ->where('code', 'VIEW_ALL_SCHEDULES')
            ->firstOrFail();

        $fixture['managerEmployee']->tags()->syncWithoutDetaching([$viewAllTag->id]);

        $hkUser = User::create([
            'company_id' => $fixture['hotel']->id,
            'name' => 'Dewi Lestari HK',
            'username' => 'dewi_hk',
            'email' => 'dewi.hk@novotel-yia.com',
            'password' => Hash::make('Password123!'),
            'role' => 'user',
            'is_active' => true,
        ]);

        $hkEmployee = Employee::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'user_id' => $hkUser->id,
            'department_id' => $fixture['hkDept']->id,
            'position' => 'Housekeeping Supervisor',
            'join_date' => '2025-04-10',
            'status' => 'active',
        ]);

        $hkSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $hkEmployee->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-22',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 9a. Manager FO (dengan tag VIEW_ALL_SCHEDULES) mencoba POST /roster/assign untuk staf HK -> 403 Forbidden
        $assignResponse = $this->actingAs($fixture['managerUser']->fresh())
            ->postJson(route('roster.assign'), [
                'employee_id' => $hkEmployee->id,
                'schedule_date' => '2026-11-23',
                'shift_id' => $fixture['shiftMO']->id,
            ]);

        $assignResponse->assertForbidden()
            ->assertJsonPath('message', 'Anda hanya memiliki izin melihat jadwal departemen ini, bukan mengubahnya.');

        // 9b. Manager FO (dengan tag VIEW_ALL_SCHEDULES) mencoba DELETE /roster/{schedule} milik staf HK -> 403 Forbidden
        $deleteResponse = $this->actingAs($fixture['managerUser']->fresh())
            ->deleteJson(route('roster.destroy', $hkSchedule));

        $deleteResponse->assertForbidden()
            ->assertJsonPath('message', 'Anda hanya memiliki izin melihat jadwal departemen ini, bukan mengubahnya.');

        $this->assertDatabaseHas('work_schedules', [
            'id' => $hkSchedule->id,
            'status' => 'published',
        ]);
    }

    /**
     * Skenario 10: Endpoint POST /roster/discard berhasil menghapus rekod berstatus 'draft' baru secara permanen,
     * mengembalikan jadwal 'published' yang di-resize kembali ke ukuran/jam sebelum di-resize,
     * serta memulihkan rekod berstatus 'pending_delete' kembali menjadi 'published'.
     */
    public function test_scenario_10_discard_drafts_deletes_draft_schedules_and_restores_pending_delete_to_published(): void
    {
        $fixture = $this->setUpBusinessLogicFixture();

        // 1. Buat jadwal baru berstatus 'draft' (belum pernah dipublikasikan)
        $draftSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee1']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-24',
            'status' => 'draft',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 2. Buat jadwal 'published' yang kemudian ditandai 'pending_delete'
        $pendingDeleteSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['staffEmployee2']->id,
            'shift_id' => $fixture['shiftNI']->id,
            'schedule_date' => '2026-11-25',
            'status' => 'pending_delete',
            'created_by' => $fixture['managerUser']->id,
        ]);

        // 3. Buat jadwal 'published' (07:00:00 - 15:00:00) lalu lakukan RESIZE menjadi 08:30:00 - 17:30:00
        $resizedPublishedSchedule = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $fixture['hotel']->id,
            'employee_id' => $fixture['managerEmployee']->id,
            'shift_id' => $fixture['shiftMO']->id,
            'schedule_date' => '2026-11-26',
            'custom_start_time' => '07:00:00',
            'custom_end_time' => '15:00:00',
            'status' => 'published',
            'created_by' => $fixture['managerUser']->id,
        ]);

        $resizeResponse = $this->actingAs($fixture['managerUser'])
            ->patchJson(route('roster.resize', $resizedPublishedSchedule), [
                'custom_start_time' => '08:30:00',
                'custom_end_time' => '17:30:00',
            ]);

        $resizeResponse->assertOk()
            ->assertJsonPath('schedule.status', 'draft')
            ->assertJsonPath('schedule.custom_start_time', '08:30:00')
            ->assertJsonPath('schedule.custom_end_time', '17:30:00')
            ->assertJsonPath('schedule.published_start_time', '07:00:00')
            ->assertJsonPath('schedule.published_end_time', '15:00:00');

        // 4. Eksekusi POST /roster/discard oleh Manager FO
        $response = $this->actingAs($fixture['managerUser'])
            ->postJson(route('roster.discard'), [
                'month' => 11,
                'year' => 2026,
                'department_id' => $fixture['foDept']->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('deleted_drafts_count', 1)
            ->assertJsonPath('restored_count', 2)
            ->assertJsonPath('status', 'published');

        // Jadwal draft baru harus terhapus permanen
        $this->assertDatabaseMissing('work_schedules', [
            'id' => $draftSchedule->id,
        ]);

        // Jadwal pending_delete harus kembali berstatus published
        $this->assertDatabaseHas('work_schedules', [
            'id' => $pendingDeleteSchedule->id,
            'status' => 'published',
        ]);

        // Jadwal published yang di-resize TIDAK boleh terhapus, melainkan kembali ke ukuran sebelumnya (07:00:00 - 15:00:00) dan berstatus published
        $this->assertDatabaseHas('work_schedules', [
            'id' => $resizedPublishedSchedule->id,
            'custom_start_time' => '07:00:00',
            'custom_end_time' => '15:00:00',
            'published_start_time' => null,
            'published_end_time' => null,
            'status' => 'published',
        ]);
    }
}
