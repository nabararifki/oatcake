<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use App\Services\SmartDecisionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UatMitraSeeder extends Seeder
{
    /**
     * Standard UAT account password (only for non-production UAT environment).
     */
    public const UAT_DEFAULT_PASSWORD = 'Password123!';

    /**
     * Seed an idempotent, realistic operational UAT environment for Novotel YIA Kulon Progo.
     * Strictly adheres to Zero-NIK compliance and append-only ledger rules.
     */
    public function run(): void
    {
        $nowWib = Carbon::now('Asia/Jakarta');
        $today = $nowWib->copy()->startOfDay();
        $passwordHash = Hash::make(self::UAT_DEFAULT_PASSWORD);

        // 1. Leave Types & Tenant Company Profile
        $this->call([
            LeaveTypeSeeder::class,
        ]);

        $company = Company::withoutGlobalScopes()->updateOrCreate(
            ['code' => 'NOVOTEL-YIA'],
            [
                'name' => 'Novotel Yogyakarta International Airport Kulon Progo',
                'latitude' => '-7.9048250',
                'longitude' => '110.0572110',
                'geofence_radius_meters' => 100,
                'geofence_radius' => 100,
                'storage_limit_mb' => 1024,
                'photo_retention_months' => 3,
                'accent_color' => '#004F9F',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
            ]
        );

        PublicHolidaySeeder::seedForCompany($company);

        // 1 Hotel Special Event in addition to the 12 National Holidays
        $specialEventDate = $today->copy()->addDays(10)->toDateString();
        PublicHoliday::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $company->id,
                'holiday_date' => $specialEventDate,
            ],
            [
                'name' => 'Anniversary Novotel YIA',
                'is_national' => false,
                'is_recurring' => false,
            ]
        );

        // 2. 8 Hospitality Departments + Department-Tailored SPK SMART Weights
        $departmentSpecs = [
            [
                'code' => 'FO',
                'name' => 'Front Office',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 35, 'C2' => 20, 'C3' => 20, 'C4' => 15, 'C5' => 10],
            ],
            [
                'code' => 'HK',
                'name' => 'Housekeeping',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 35, 'C2' => 15, 'C3' => 25, 'C4' => 15, 'C5' => 10],
            ],
            [
                'code' => 'FBS',
                'name' => 'F&B Service',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 30, 'C2' => 20, 'C3' => 20, 'C4' => 15, 'C5' => 15],
            ],
            [
                'code' => 'FBP',
                'name' => 'F&B Product / Kitchen',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 35, 'C2' => 15, 'C3' => 20, 'C4' => 15, 'C5' => 15],
            ],
            [
                'code' => 'ENG',
                'name' => 'Engineering',
                'is_operational' => true,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 30, 'C2' => 20, 'C3' => 25, 'C4' => 10, 'C5' => 15],
            ],
            [
                'code' => 'FIN',
                'name' => 'Finance & Accounting',
                'is_operational' => false,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 20, 'C2' => 25, 'C3' => 20, 'C4' => 15, 'C5' => 20],
            ],
            [
                'code' => 'HR',
                'name' => 'Human Resources',
                'is_operational' => false,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 20, 'C2' => 25, 'C3' => 20, 'C4' => 15, 'C5' => 20],
            ],
            [
                'code' => 'SEC',
                'name' => 'Security',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
                'weights' => ['C1' => 40, 'C2' => 10, 'C3' => 25, 'C4' => 15, 'C5' => 10],
            ],
        ];

        $smartService = app(SmartDecisionService::class);
        $departmentsByCode = [];

        foreach ($departmentSpecs as $spec) {
            $dept = Department::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $spec['code'],
                ],
                [
                    'name' => $spec['name'],
                    'is_operational' => $spec['is_operational'],
                    'min_staff_per_shift' => $spec['min_staff_per_shift'],
                    'is_executive_oversight' => $spec['is_executive_oversight'],
                ]
            );

            $departmentsByCode[$spec['code']] = $dept;
            $smartService->updateDepartmentWeights($dept, $spec['weights']);
        }

        // 3. Hybrid Tags
        $modTag = Tag::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'MOD_ELIGIBLE'],
            ['name' => 'Eligible Manager on Duty', 'badge_color' => '#D97706']
        );
        $viewAllTag = Tag::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'VIEW_ALL_SCHEDULES'],
            ['name' => 'Akses Lihat Jadwal Lintas Departemen', 'badge_color' => '#0284C7']
        );

        // 4. 6 Master Shifts (MO, AF, NI, MD, MOD, OFF)
        $shiftSpecs = [
            [
                'code' => 'MO',
                'name' => 'Morning Shift',
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 15,
                'color_badge' => '#3B82F6',
                'is_active' => true,
            ],
            [
                'code' => 'AF',
                'name' => 'Afternoon Shift',
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 15,
                'color_badge' => '#F59E0B',
                'is_active' => true,
            ],
            [
                'code' => 'NI',
                'name' => 'Night Shift',
                'start_time' => '23:00:00',
                'end_time' => '07:00:00',
                'cross_day' => true,
                'grace_period_minutes' => 15,
                'color_badge' => '#6366F1',
                'is_active' => true,
            ],
            [
                'code' => 'MD',
                'name' => 'Middle Shift',
                'start_time' => '11:00:00',
                'end_time' => '19:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 15,
                'color_badge' => '#10B981',
                'is_active' => true,
            ],
            [
                'code' => 'MOD',
                'name' => 'Manager on Duty',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 15,
                'color_badge' => '#EC4899',
                'is_active' => true,
            ],
            [
                'code' => 'OFF',
                'name' => 'Day Off',
                'start_time' => '00:00:00',
                'end_time' => '00:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 0,
                'color_badge' => '#71717A',
                'is_active' => true,
            ],
        ];

        $shiftsByCode = [];
        foreach ($shiftSpecs as $shiftSpec) {
            $shiftsByCode[$shiftSpec['code']] = Shift::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $shiftSpec['code'],
                ],
                $shiftSpec
            );
        }

        // 5. 5-Tier RBAC Accounts + 6 Operational Staff
        User::updateOrCreate(
            ['email' => 'superadmin.uat@oatcake.test'],
            [
                'company_id' => null,
                'name' => 'Platform Super Admin UAT',
                'username' => 'superadmin_uat',
                'password' => $passwordHash,
                'role' => 'super_admin',
                'is_active' => true,
                'email_verified_at' => $nowWib,
                'locale' => 'id',
            ]
        );

        $gmUser = User::updateOrCreate(
            ['email' => 'gm.uat@oatcake.test'],
            [
                'company_id' => $company->id,
                'name' => 'General Manager UAT',
                'username' => 'gm_uat',
                'password' => $passwordHash,
                'role' => 'super_user',
                'is_active' => true,
                'email_verified_at' => $nowWib,
                'locale' => 'id',
            ]
        );
        $gmEmp = Employee::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $gmUser->id],
            [
                'company_id' => $company->id,
                'department_id' => $departmentsByCode['FO']->id,
                'position' => 'General Manager',
                'join_date' => '2022-01-10',
                'status' => 'active',
            ]
        );
        $gmEmp->tags()->sync([$viewAllTag->id]);

        $hrUser = User::updateOrCreate(
            ['email' => 'hr.uat@oatcake.test'],
            [
                'company_id' => $company->id,
                'name' => 'Talent & Culture Admin UAT',
                'username' => 'hr_uat',
                'password' => $passwordHash,
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => $nowWib,
                'locale' => 'id',
            ]
        );
        $hrEmp = Employee::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $hrUser->id],
            [
                'company_id' => $company->id,
                'department_id' => $departmentsByCode['HR']->id,
                'position' => 'Talent & Culture Manager',
                'join_date' => '2022-05-01',
                'status' => 'active',
            ]
        );
        $hrEmp->tags()->sync([$viewAllTag->id]);

        $fomUser = User::updateOrCreate(
            ['email' => 'fom.uat@oatcake.test'],
            [
                'company_id' => $company->id,
                'name' => 'Budi Santoso (FO Manager)',
                'username' => 'fom_uat',
                'password' => $passwordHash,
                'role' => 'manager',
                'is_active' => true,
                'email_verified_at' => $nowWib,
                'locale' => 'id',
            ]
        );
        $fomEmp = Employee::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $fomUser->id],
            [
                'company_id' => $company->id,
                'department_id' => $departmentsByCode['FO']->id,
                'position' => 'Front Office Manager',
                'join_date' => '2023-02-01',
                'status' => 'active',
            ]
        );
        $fomEmp->tags()->sync([$modTag->id]);

        $hkmUser = User::updateOrCreate(
            ['email' => 'hkm.uat@oatcake.test'],
            [
                'company_id' => $company->id,
                'name' => 'Siti Lestari (HK Manager)',
                'username' => 'hkm_uat',
                'password' => $passwordHash,
                'role' => 'manager',
                'is_active' => true,
                'email_verified_at' => $nowWib,
                'locale' => 'id',
            ]
        );
        $hkmEmp = Employee::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $hkmUser->id],
            [
                'company_id' => $company->id,
                'department_id' => $departmentsByCode['HK']->id,
                'position' => 'Executive Housekeeper',
                'join_date' => '2023-03-15',
                'status' => 'active',
            ]
        );
        $hkmEmp->tags()->sync([$modTag->id]);

        $staffSpecs = [
            'fo1' => [
                'email' => 'fo.staff1@oatcake.test',
                'username' => 'fo_staff1',
                'name' => 'Nadia Kusuma (FO 1)',
                'dept' => 'FO',
                'manager_id' => $fomEmp->id,
                'position' => 'Senior Guest Service Agent',
                'join_date' => '2023-06-01',
            ],
            'fo2' => [
                'email' => 'fo.staff2@oatcake.test',
                'username' => 'fo_staff2',
                'name' => 'Dimas Pratama (FO 2)',
                'dept' => 'FO',
                'manager_id' => $fomEmp->id,
                'position' => 'Guest Service Agent',
                'join_date' => '2025-01-10',
            ],
            'fo3' => [
                'email' => 'fo.staff3@oatcake.test',
                'username' => 'fo_staff3',
                'name' => 'Rani Wijaya (FO 3)',
                'dept' => 'FO',
                'manager_id' => $fomEmp->id,
                'position' => 'Bell Captain & Concierge',
                'join_date' => '2024-04-12',
            ],
            'hk1' => [
                'email' => 'hk.staff1@oatcake.test',
                'username' => 'hk_staff1',
                'name' => 'Agus Setiawan (HK 1)',
                'dept' => 'HK',
                'manager_id' => $hkmEmp->id,
                'position' => 'Room Attendant',
                'join_date' => '2024-07-01',
            ],
            'fbs1' => [
                'email' => 'fbs.staff1@oatcake.test',
                'username' => 'fbs_staff1',
                'name' => 'Tari Melati (FBS 1)',
                'dept' => 'FBS',
                'manager_id' => null,
                'position' => 'Food Exchange Waitress',
                'join_date' => '2024-08-15',
            ],
            'eng1' => [
                'email' => 'eng.staff1@oatcake.test',
                'username' => 'eng_staff1',
                'name' => 'Hendra Saputra (ENG 1)',
                'dept' => 'ENG',
                'manager_id' => null,
                'position' => 'Shift Technician',
                'join_date' => '2024-09-01',
            ],
        ];

        $staffUsers = [];
        $staffEmps = [];
        foreach ($staffSpecs as $key => $spec) {
            $u = User::updateOrCreate(
                ['email' => $spec['email']],
                [
                    'company_id' => $company->id,
                    'name' => $spec['name'],
                    'username' => $spec['username'],
                    'password' => $passwordHash,
                    'role' => 'user',
                    'is_active' => true,
                    'email_verified_at' => $nowWib,
                    'locale' => 'id',
                ]
            );
            $e = Employee::withoutGlobalScopes()->updateOrCreate(
                ['user_id' => $u->id],
                [
                    'company_id' => $company->id,
                    'department_id' => $departmentsByCode[$spec['dept']]->id,
                    'manager_id' => $spec['manager_id'],
                    'position' => $spec['position'],
                    'join_date' => $spec['join_date'],
                    'status' => 'active',
                ]
            );
            $staffUsers[$key] = $u;
            $staffEmps[$key] = $e;
        }

        // 6. Idempotent Initial Annual Leave (AL = 12.00) Accrual for UAT Staff & Managers
        $ledgerService = app(LeaveLedgerService::class);
        $allUatEmployees = array_merge([$gmEmp, $hrEmp, $fomEmp, $hkmEmp], array_values($staffEmps));
        $currentYear = (int) $today->year;

        foreach ($allUatEmployees as $uatEmp) {
            // Give fo1 an earlier expiry date so Mode A SPK SMART produces clear ranking differentiation
            $expiresAt = $uatEmp->id === $staffEmps['fo1']->id
                ? $today->copy()->addDays(15)->toDateString()
                : sprintf('%04d-12-31', $currentYear);

            $ledgerService->recordAccrual($uatEmp, 'AL', 12.0, [
                'accrual_year' => $currentYear,
                'expires_at' => $expiresAt,
                'notes' => "UAT Initial Annual Leave Accrual {$currentYear}",
                'created_by' => $hrUser->id,
            ]);
        }

        // 7. Operational Work Schedules (published, draft, MOD, and split shift)
        $shiftPatterns = [
            'fo1' => ['MO', 'MO', 'AF', 'MO', 'MO', 'OFF', 'MO'],
            'fo2' => ['AF', 'AF', 'MO', 'AF', 'MO', 'MO', 'OFF'],
            'fo3' => ['MD', 'MO', 'MO', 'AF', 'OFF', 'MD', 'MD'],
            'hk1' => ['MO', 'MO', 'AF', 'NI', 'OFF', 'MO', 'MO'],
            'fbs1' => ['MO', 'AF', 'MO', 'AF', 'MO', 'OFF', 'AF'],
            'eng1' => ['MO', 'MO', 'MD', 'AF', 'NI', 'OFF', 'MO'],
        ];

        for ($offset = 0; $offset < 14; $offset++) {
            $dateStr = $today->copy()->addDays($offset)->toDateString();
            $status = $offset < 10 ? 'published' : 'draft';

            foreach ($shiftPatterns as $staffKey => $pattern) {
                $emp = $staffEmps[$staffKey];
                $code = $pattern[$offset % count($pattern)];
                $shift = $shiftsByCode[$code];

                WorkSchedule::withoutGlobalScopes()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $emp->id,
                        'schedule_date' => $dateStr,
                        'shift_id' => $code === 'OFF' ? null : $shift->id,
                        'custom_start_time' => null,
                    ],
                    [
                        'status' => $status,
                        'created_by' => $hrUser->id,
                    ]
                );
            }

            // Assign alternating Manager on Duty (MOD)
            $modEmp = ($offset % 2 === 0) ? $fomEmp : $hkmEmp;
            WorkSchedule::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'employee_id' => $modEmp->id,
                    'schedule_date' => $dateStr,
                    'shift_id' => $shiftsByCode['MOD']->id,
                    'custom_start_time' => null,
                ],
                [
                    'duty_tag_id' => $modTag->id,
                    'status' => $status,
                    'created_by' => $hrUser->id,
                ]
            );
        }

        // Split-shift sample for fo3 on today + 1 day (Morning part 07:00-11:00 + Evening part 18:00-22:00)
        $splitDate = $today->copy()->addDays(1)->toDateString();
        WorkSchedule::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo3']->id,
                'schedule_date' => $splitDate,
                'shift_id' => $shiftsByCode['AF']->id,
                'custom_start_time' => '18:00:00',
            ],
            [
                'custom_end_time' => '22:00:00',
                'status' => 'published',
                'notes' => 'UAT Split Shift Demo',
                'created_by' => $fomUser->id,
            ]
        );

        // 8. Sample Attendances (on_time, late, pending_verification outside duty, and holiday DP committed)
        $yesterday = $today->copy()->subDay();
        $twoDaysAgo = $today->copy()->subDays(2);

        // On-time history for fo1 (supports high C3 discipline score in Mode A SPK SMART)
        Attendance::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo1']->id,
                'notes' => 'UAT-FO1-ONTIME-1',
            ],
            [
                'clock_in_at' => $twoDaysAgo->copy()->setTime(6, 55),
                'clock_out_at' => $twoDaysAgo->copy()->setTime(15, 5),
                'clock_in_latitude' => -7.9048250,
                'clock_in_longitude' => 110.0572110,
                'clock_out_latitude' => -7.9048250,
                'clock_out_longitude' => 110.0572110,
                'clock_in_distance_meters' => 12,
                'clock_out_distance_meters' => 14,
                'status' => 'on_time',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'is_outside_geofence' => false,
            ]
        );

        // Late history for fo2 (creates contrast in C3 discipline score vs fo1)
        Attendance::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo2']->id,
                'notes' => 'UAT-FO2-LATE-1',
            ],
            [
                'clock_in_at' => $twoDaysAgo->copy()->setTime(15, 35),
                'clock_out_at' => $twoDaysAgo->copy()->setTime(23, 5),
                'clock_in_latitude' => -7.9048250,
                'clock_in_longitude' => 110.0572110,
                'clock_out_latitude' => -7.9048250,
                'clock_out_longitude' => 110.0572110,
                'clock_in_distance_meters' => 25,
                'clock_out_distance_meters' => 22,
                'status' => 'late',
                'late_minutes' => 20,
                'early_leave_minutes' => 0,
                'is_outside_geofence' => false,
            ]
        );

        // Pending Verification Outside-Duty Attendance for HK staff (ready for Manager approval UAT)
        Attendance::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['hk1']->id,
                'notes' => 'UAT-OUTSIDE-PENDING-1',
            ],
            [
                'clock_in_at' => $yesterday->copy()->setTime(8, 10),
                'clock_in_latitude' => -7.8901000,
                'clock_in_longitude' => 110.0950000,
                'clock_in_distance_meters' => 4500,
                'status' => 'pending_verification',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'is_outside_geofence' => true,
                'outside_category' => 'procurement',
                'outside_location_name' => 'Gudang Logistik Linen Kulon Progo',
            ]
        );

        // Holiday Attendance with Committed +1.00 DP for fo3
        $holidayAtt = Attendance::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo3']->id,
                'notes' => 'UAT-HOLIDAY-DP-COMMITTED-1',
            ],
            [
                'clock_in_at' => $yesterday->copy()->setTime(7, 0),
                'clock_out_at' => $yesterday->copy()->setTime(15, 30),
                'clock_in_latitude' => -7.9048250,
                'clock_in_longitude' => 110.0572110,
                'clock_out_latitude' => -7.9048250,
                'clock_out_longitude' => 110.0572110,
                'clock_in_distance_meters' => 18,
                'clock_out_distance_meters' => 20,
                'status' => 'on_time',
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'is_outside_geofence' => false,
                'is_provisional_dp' => true,
            ]
        );

        if ($holidayAtt->dp_committed_at === null) {
            $dpType = LeaveType::findByCode('DP', $company->id);
            $existingDpEntry = LeaveLedgerEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('employee_id', $staffEmps['fo3']->id)
                ->where('reference_type', 'holiday_attendance')
                ->where('reference_id', $holidayAtt->id)
                ->first();

            if ($existingDpEntry === null && $dpType !== null) {
                $existingDpEntry = $ledgerService->recordCredit($staffEmps['fo3'], $dpType, 1.0, [
                    'reference_type' => 'holiday_attendance',
                    'reference_id' => $holidayAtt->id,
                    'notes' => 'UAT Committed Holiday DP (+1.00)',
                    'created_by' => $hrUser->id,
                ]);
            }

            if ($existingDpEntry !== null) {
                $holidayAtt->update([
                    'dp_committed_at' => $yesterday->copy()->setTime(15, 30),
                    'dp_ledger_entry_id' => $existingDpEntry->id,
                ]);
            }
        }

        // 9. SPK SMART Mode A (2 Overlapping Pending Manager Requests) & Tier-2 Pending HR Request
        $alType = LeaveType::findByCode('AL', $company->id);
        $overlapStart = $today->copy()->addDays(3)->toDateString();
        $overlapEnd = $today->copy()->addDays(4)->toDateString();

        $reqFo1 = LeaveRequest::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo1']->id,
                'reason' => 'UAT-SMART-MODE-A-CANDIDATE-1: Acara keluarga di Magelang',
            ],
            [
                'leave_type_id' => $alType->id,
                'start_date' => $overlapStart,
                'end_date' => $overlapEnd,
                'total_days' => 2.0,
                'status' => LeaveRequest::STATUS_PENDING_MANAGER,
            ]
        );

        $reqFo2 = LeaveRequest::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo2']->id,
                'reason' => 'UAT-SMART-MODE-A-CANDIDATE-2: Keperluan pribadi beririsan',
            ],
            [
                'leave_type_id' => $alType->id,
                'start_date' => $overlapStart,
                'end_date' => $overlapEnd,
                'total_days' => 2.0,
                'status' => LeaveRequest::STATUS_PENDING_MANAGER,
            ]
        );

        $smartService->evaluateSingle($reqFo1, true);
        $smartService->evaluateSingle($reqFo2, true);

        // Tier-2 Ready Leave Request (already Tier-1 approved by FO Manager)
        $tier2Start = $today->copy()->addDays(7)->toDateString();
        $tier2End = $today->copy()->addDays(7)->toDateString();

        $reqFo3 = LeaveRequest::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_id' => $staffEmps['fo3']->id,
                'reason' => 'UAT-PENDING-HR-TIER2-1: Pengurusan administrasi keluarga',
            ],
            [
                'leave_type_id' => $alType->id,
                'start_date' => $tier2Start,
                'end_date' => $tier2End,
                'total_days' => 1.0,
                'status' => LeaveRequest::STATUS_PENDING_HR,
                'manager_approved_by' => $fomUser->id,
                'manager_approved_at' => $nowWib->copy()->subHours(2),
            ]
        );

        LeaveRequestApproval::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'leave_request_id' => $reqFo3->id,
                'tier' => 1,
            ],
            [
                'approver_id' => $fomUser->id,
                'action' => LeaveRequestApproval::ACTION_APPROVED,
                'notes' => 'Disetujui Tier 1 oleh FO Manager untuk uji Tier 2 HR',
                'smart_score_snapshot' => $reqFo3->smart_score,
                'acted_at' => $nowWib->copy()->subHours(2),
            ]
        );

        $smartService->evaluateSingle($reqFo3, true);
    }
}
