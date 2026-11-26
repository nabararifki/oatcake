<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class DefaultTagSeeder extends Seeder
{
    /**
     * Default employment classification tags automatically seeded for every hotel property.
     *
     * @var list<array{code: string, name: string, badge_color: string}>
     */
    public const DEFAULT_TAGS = [
        [
            'code' => 'HOD',
            'name' => 'Head of Department',
            'badge_color' => '#4F46E5',
        ],
        [
            'code' => 'DAILY_WORKER',
            'name' => 'Daily Worker',
            'badge_color' => '#0EA5E9',
        ],
        [
            'code' => 'CASUAL',
            'name' => 'Casual',
            'badge_color' => '#8B5CF6',
        ],
    ];

    /**
     * Seed default employment tags for a specific company idempotently.
     */
    public static function seedForCompany(Company $company): void
    {
        foreach (self::DEFAULT_TAGS as $tag) {
            Tag::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $tag['code'],
                ],
                [
                    'name' => $tag['name'],
                    'badge_color' => $tag['badge_color'],
                ]
            );
        }
    }

    /**
     * Run the database seeds across all existing companies.
     */
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            self::seedForCompany($company);
        });
    }
}
