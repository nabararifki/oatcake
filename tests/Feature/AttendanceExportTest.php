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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Department $foDept;

    protected Department $hkDept;

    protected Shift $shiftMo;

    protected Shift $shiftEa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            CompanySeeder::class,
            NovotelOrganizationSeeder::class,
            NovotelShiftSeeder::class,
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

        $this->shiftEa = Shift::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', '!=', 'MO')
            ->orderBy('id')
            ->first() ?? $this->shiftMo;
    }

    /**
     * Memastikan endpoint export mengalirkan CSV ber-BOM UTF-8 dengan kolom standar payroll,
     * konversi zona waktu perusahaan (Asia/Jakarta & konversi zona waktu), serta fallback payroll_code ke ID karyawan.
     */
    public function test_admin_can_stream_payroll_csv_with_jakarta_timezone_and_id_fallback(): void
    {
        $admin = $this->createUser('hr.export', 'Hr Admin', 'admin_hr');
        $staffUser = $this->createUser('budi.santoso', 'Budi Santoso', 'user');
        $staffEmployee = $this->createEmployee($staffUser, $this->foDept, 'Front Desk Agent');

        $schedule = $this->createSchedule($staffEmployee, $this->shiftMo, '2026-11-04');
        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $staffEmployee->id,
            'work_schedule_id' => $schedule->id,
            'clock_in_at' => Carbon::create(2026, 11, 4, 7, 35, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 4, 15, 15, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 5,
            'early_leave_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('attendance.export'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="attendance-', (string) $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $rows = $this->parseCsvRows($content);
        $this->assertCount(2, $rows);

        $this->assertSame([
            'Payroll Code',
            'Nama Lengkap',
            'Departemen',
            'Tanggal Presensi',
            'Kode Shift',
            'Jam Masuk (Aktual)',
            'Jam Keluar (Aktual)',
            'Keterlambatan (Menit)',
            'Pulang Cepat (Menit)',
            'Status Kehadiran',
        ], $rows[0]);

        $this->assertSame([
            (string) $staffEmployee->id,
            'Budi Santoso',
            $this->foDept->name,
            '2026-11-04',
            'MO',
            '07:35:00',
            '15:15:00',
            '5',
            '0',
            'Terlambat',
        ], $rows[1]);

        // Pastikan konversi zona waktu mengikuti timezone perusahaan (misal WIB -> WITA +1 jam)
        $this->company->update(['timezone' => 'Asia/Makassar']);
        $witaRows = $this->parseCsvRows(
            $this->actingAs($admin->fresh())->get(route('attendance.export'))->streamedContent()
        );
        $this->assertSame('08:35:00', $witaRows[1][5]);
        $this->assertSame('16:15:00', $witaRows[1][6]);
    }

    /**
     * Memastikan kamus dwibahasa menghasilkan header CSV Inggris saat locale 'en' aktif.
     */
    public function test_export_uses_english_headers_when_locale_is_en(): void
    {
        $admin = $this->createUser('hr.en', 'HR English', 'admin_hr');
        $admin->forceFill(['locale' => 'en'])->save();

        $response = $this->actingAs($admin)->get(route('attendance.export'));

        $response->assertOk();
        $rows = $this->parseCsvRows($response->streamedContent());

        $this->assertSame([
            'Payroll Code',
            'Full Name',
            'Department',
            'Attendance Date',
            'Shift Code',
            'Clock In (Actual)',
            'Clock Out (Actual)',
            'Late (Minutes)',
            'Early Leave (Minutes)',
            'Attendance Status',
        ], $rows[0]);
    }

    /**
     * Memastikan parameter filter (date_from/date_to, start_date/end_date, department_id, status, search)
     * menyaring hasil CSV dan tombol export pada UI membawa query parameter aktif.
     */
    public function test_export_respects_all_filters_and_ui_button_preserves_query_params(): void
    {
        app()->setLocale('id');

        $admin = $this->createUser('hr.filter', 'HR Filter', 'admin_hr');

        $foUser = $this->createUser('siti.fo', 'Siti Aminah', 'user');
        $foEmp = $this->createEmployee($foUser, $this->foDept, 'Receptionist');
        $hkUser = $this->createUser('agus.hk', 'Agus HK', 'user');
        $hkEmp = $this->createEmployee($hkUser, $this->hkDept, 'Room Attendant');

        $foMatchSchedule = $this->createSchedule($foEmp, $this->shiftMo, '2026-11-10');
        $foOtherDateSchedule = $this->createSchedule($foEmp, $this->shiftMo, '2026-10-15');
        $hkSchedule = $this->createSchedule($hkEmp, $this->shiftEa, '2026-11-10');

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $foEmp->id,
            'work_schedule_id' => $foMatchSchedule->id,
            'clock_in_at' => Carbon::create(2026, 11, 10, 7, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 10, 15, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $foEmp->id,
            'work_schedule_id' => $foOtherDateSchedule->id,
            'clock_in_at' => Carbon::create(2026, 10, 15, 7, 0, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 10, 15, 15, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $hkEmp->id,
            'work_schedule_id' => $hkSchedule->id,
            'clock_in_at' => Carbon::create(2026, 11, 10, 14, 20, 0, 'Asia/Jakarta'),
            'clock_out_at' => Carbon::create(2026, 11, 10, 22, 0, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 20,
            'early_leave_minutes' => 0,
        ]);

        $filters = [
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-30',
            'department_id' => (string) $this->foDept->id,
            'status' => 'on_time',
            'search' => 'Siti',
        ];

        $uiResponse = $this->actingAs($admin)->get(route('attendance.index', $filters));
        $uiResponse->assertOk();
        $uiResponse->assertSee(route('attendance.export', $filters));
        $uiResponse->assertSee(__('attendance.btn_export_csv'));

        $exportResponse = $this->actingAs($admin)->get(route('attendance.export', $filters));
        $rows = $this->parseCsvRows($exportResponse->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('Siti Aminah', $rows[1][1]);
        $this->assertSame('2026-11-10', $rows[1][3]);

        // Verifikasi alias start_date / end_date untuk pemanggil eksternal/API
        $aliasResponse = $this->actingAs($admin)->get(route('attendance.export', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]));
        $aliasRows = $this->parseCsvRows($aliasResponse->streamedContent());
        $this->assertCount(2, $aliasRows);
        $this->assertSame('2026-10-15', $aliasRows[1][3]);
    }

    /**
     * Memastikan batasan akses RBAC (user hanya melihat dirinya; manager hanya departemennya)
     * dan isolasi tenant antar-perusahaan tetap ditegakkan saat ekspor CSV.
     */
    public function test_export_enforces_rbac_and_tenant_isolation(): void
    {
        app()->setLocale('id');

        $foManagerUser = $this->createUser('fo.mgr', 'Manager FO', 'manager');
        $this->createEmployee($foManagerUser, $this->foDept, 'FO Manager');

        $foStaffUser = $this->createUser('fo.staff', 'Staff FO', 'user');
        $foStaff = $this->createEmployee($foStaffUser, $this->foDept, 'Bellboy');

        $hkStaffUser = $this->createUser('hk.staff', 'Staff HK', 'user');
        $hkStaff = $this->createEmployee($hkStaffUser, $this->hkDept, 'Housekeeper');

        $otherCompany = Company::create([
            'code' => 'OTHER-HTL',
            'name' => 'Other Hotel Property',
            'timezone' => 'Asia/Jakarta',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'radius_meters' => 150,
            'is_active' => true,
        ]);
        $otherDept = Department::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'code' => 'OTFO',
            'name' => 'Other FO',
        ]);
        $otherUser = $this->createUser('other.staff', 'Other Tenant Staff', 'user', $otherCompany->id);
        $otherEmp = $this->createEmployee($otherUser, $otherDept, 'Clerk', $otherCompany->id);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $foStaff->id,
            'clock_in_at' => Carbon::create(2026, 11, 5, 8, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $hkStaff->id,
            'clock_in_at' => Carbon::create(2026, 11, 5, 8, 5, 0, 'Asia/Jakarta'),
            'status' => 'late',
            'late_minutes' => 5,
            'early_leave_minutes' => 0,
        ]);

        Attendance::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'employee_id' => $otherEmp->id,
            'clock_in_at' => Carbon::create(2026, 11, 5, 8, 0, 0, 'Asia/Jakarta'),
            'status' => 'on_time',
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        // User reguler hanya mengekspor rekod miliknya sendiri
        $userRows = $this->parseCsvRows(
            $this->actingAs($foStaffUser)->get(route('attendance.export'))->streamedContent()
        );
        $this->assertCount(2, $userRows);
        $this->assertSame('Staff FO', $userRows[1][1]);

        // Manager FO hanya mengekspor departemen FO (tidak melihat Staff HK atau tenant lain)
        $managerRows = $this->parseCsvRows(
            $this->actingAs($foManagerUser)->get(route('attendance.export', [
                'department_id' => $this->hkDept->id,
            ]))->streamedContent()
        );
        $this->assertCount(2, $managerRows);
        $this->assertSame('Staff FO', $managerRows[1][1]);

        // Admin HR tidak pernah mengekspor rekod dari perusahaan lain
        $hrUser = $this->createUser('hr.tenant', 'HR Tenant', 'admin_hr');
        $hrCsv = $this->actingAs($hrUser)->get(route('attendance.export'))->streamedContent();
        $this->assertStringNotContainsString('Other Tenant Staff', $hrCsv);
    }

    private function createUser(string $username, string $name, string $role, ?int $companyId = null): User
    {
        return User::create([
            'company_id' => $companyId ?? $this->company->id,
            'name' => $name,
            'username' => $username,
            'email' => "{$username}@oatcake.test",
            'password' => Hash::make('password123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createEmployee(User $user, Department $dept, string $position, ?int $companyId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $companyId ?? $this->company->id,
            'user_id' => $user->id,
            'department_id' => $dept->id,
            'position' => $position,
            'join_date' => '2025-01-10',
            'status' => 'active',
        ]);
    }

    private function createSchedule(Employee $employee, Shift $shift, string $date): WorkSchedule
    {
        return WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'schedule_date' => $date,
            'status' => 'published',
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsvRows(string $csvContent): array
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\n|\r/', trim($clean)) ?: [];

        return array_map(
            static fn (string $line): array => str_getcsv($line),
            array_values(array_filter($lines, static fn (string $line): bool => $line !== ''))
        );
    }
}
