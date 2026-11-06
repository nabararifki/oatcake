<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Run the database seeds for 2026 Official Indonesian National Holidays.
     */
    public function run(): void
    {
        $holidays = [
            [
                'holiday_date' => '2026-01-01',
                'name' => 'Tahun Baru 2026 Masehi',
                'is_recurring' => true,
            ],
            [
                'holiday_date' => '2026-02-17',
                'name' => 'Tahun Baru Imlek 2577 Kongzili',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-03-20',
                'name' => 'Hari Raya Idul Fitri 1447 H (Hari Pertama)',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-03-21',
                'name' => 'Hari Raya Idul Fitri 1447 H (Hari Kedua)',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-05-01',
                'name' => 'Hari Buruh Internasional',
                'is_recurring' => true,
            ],
            [
                'holiday_date' => '2026-05-14',
                'name' => 'Kenaikan Yesus Kristus',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-05-27',
                'name' => 'Hari Raya Idul Adha 1447 H',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-06-01',
                'name' => 'Hari Lahir Pancasila',
                'is_recurring' => true,
            ],
            [
                'holiday_date' => '2026-06-16',
                'name' => 'Tahun Baru Islam 1448 H',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-08-17',
                'name' => 'Hari Kemerdekaan Republik Indonesia',
                'is_recurring' => true,
            ],
            [
                'holiday_date' => '2026-08-25',
                'name' => 'Maulid Nabi Muhammad SAW',
                'is_recurring' => false,
            ],
            [
                'holiday_date' => '2026-12-25',
                'name' => 'Hari Raya Natal',
                'is_recurring' => true,
            ],
        ];

        foreach ($holidays as $item) {
            $existing = PublicHoliday::withoutGlobalScopes()
                ->whereNull('company_id')
                ->whereDate('holiday_date', $item['holiday_date'])
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'name' => $item['name'],
                    'is_recurring' => $item['is_recurring'],
                ]);
            } else {
                PublicHoliday::create([
                    'company_id' => null,
                    'holiday_date' => $item['holiday_date'],
                    'name' => $item['name'],
                    'is_recurring' => $item['is_recurring'],
                ]);
            }
        }
    }
}
