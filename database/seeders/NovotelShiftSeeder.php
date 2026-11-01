<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class NovotelShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::where('code', 'NOVOTEL-YIA')->firstOrFail();

        $shifts = [
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
                'code' => 'GO',
                'name' => 'General Office',
                'start_time' => '08:30:00',
                'end_time' => '17:00:00',
                'cross_day' => false,
                'grace_period_minutes' => 15,
                'color_badge' => '#6B7280',
                'is_active' => true,
            ],
            [
                'code' => 'MOD',
                'name' => 'Manager on Duty',
                'start_time' => '19:00:00',
                'end_time' => '07:00:00',
                'cross_day' => true,
                'grace_period_minutes' => 15,
                'color_badge' => '#EC4899',
                'is_active' => true,
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $shift['code'],
                ],
                $shift
            );
        }
    }
}
