<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class NovotelOrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $departments = [
            [
                'code' => 'FO',
                'name' => 'Front Office',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'HK',
                'name' => 'Housekeeping',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'ENG',
                'name' => 'Engineering',
                'is_operational' => true,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'SEC',
                'name' => 'Security',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'FBS',
                'name' => 'F&B Service',
                'is_operational' => true,
                'min_staff_per_shift' => 2,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'HR',
                'name' => 'Human Resources',
                'is_operational' => false,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'ACC',
                'name' => 'Finance & Accounting',
                'is_operational' => false,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
            ],
            [
                'code' => 'SM',
                'name' => 'Sales & Marketing',
                'is_operational' => false,
                'min_staff_per_shift' => 1,
                'is_executive_oversight' => false,
            ],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $dept['code'],
                ],
                $dept
            );
        }

        $tags = [
            [
                'code' => 'HOD',
                'name' => 'Head of Department',
                'badge_color' => '#4F46E5',
            ],
            [
                'code' => 'MOD_ELIGIBLE',
                'name' => 'Eligible Manager on Duty',
                'badge_color' => '#D97706',
            ],
            [
                'code' => 'FIRST_AIDER',
                'name' => 'Petugas P3K Terverifikasi',
                'badge_color' => '#10B981',
            ],
            [
                'code' => 'FIRE_WARDEN',
                'name' => 'Regu Tanggap Kebakaran',
                'badge_color' => '#EF4444',
            ],
            [
                'code' => 'VIEW_ALL_SCHEDULES',
                'name' => 'Akses Lihat Jadwal Lintas Departemen',
                'badge_color' => '#0284C7',
            ],
        ];

        foreach ($tags as $tag) {
            Tag::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $tag['code'],
                ],
                $tag
            );
        }
    }
}
