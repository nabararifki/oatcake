<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Official 2026 Indonesian National Holidays (Hari Libur Nasional 2026).
     *
     * @return array<int, array{holiday_date: string, name: string, is_national: bool, is_recurring: bool}>
     */
    public static function defaultHolidays(): array
    {
        return [
            ['holiday_date' => '2026-01-01', 'name' => 'Tahun Baru 2026 Masehi', 'is_national' => true, 'is_recurring' => true],
            ['holiday_date' => '2026-01-16', 'name' => 'Isra Mikraj Nabi Muhammad SAW', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-02-17', 'name' => 'Tahun Baru Imlek 2577 Kongzili', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-03-19', 'name' => 'Hari Suci Nyepi Tahun Baru Saka 1948', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-03-20', 'name' => 'Hari Raya Idul Fitri 1447 H', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-04-03', 'name' => 'Wafat Yesus Kristus (Jumat Agung)', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-05-01', 'name' => 'Hari Buruh Internasional', 'is_national' => true, 'is_recurring' => true],
            ['holiday_date' => '2026-05-14', 'name' => 'Kenaikan Yesus Kristus', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-05-27', 'name' => 'Hari Raya Idul Adha 1447 H', 'is_national' => true, 'is_recurring' => false],
            ['holiday_date' => '2026-06-01', 'name' => 'Hari Lahir Pancasila', 'is_national' => true, 'is_recurring' => true],
            ['holiday_date' => '2026-08-17', 'name' => 'Hari Kemerdekaan Republik Indonesia', 'is_national' => true, 'is_recurring' => true],
            ['holiday_date' => '2026-12-25', 'name' => 'Hari Raya Natal', 'is_national' => true, 'is_recurring' => true],
        ];
    }

    /**
     * Seed the 12 official 2026 Indonesian National Holidays for a specific company.
     */
    public static function seedForCompany(Company $company): void
    {
        foreach (self::defaultHolidays() as $holiday) {
            PublicHoliday::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'holiday_date' => $holiday['holiday_date'],
                ],
                [
                    'name' => $holiday['name'],
                    'is_national' => $holiday['is_national'],
                    'is_recurring' => $holiday['is_recurring'],
                ]
            );
        }
    }

    /**
     * Seed the 12 official 2026 Indonesian National Holidays for all companies.
     */
    public function run(): void
    {
        $companies = Company::withoutGlobalScopes()->get();

        foreach ($companies as $company) {
            self::seedForCompany($company);
        }
    }
}
