<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::updateOrCreate(
            ['code' => 'NOVOTEL-YIA'],
            [
                'name' => 'Novotel Yogyakarta International Airport Kulon Progo',
                'latitude' => '-7.9048250',
                'longitude' => '110.0572110',
                'geofence_radius_meters' => 100,
                'accent_color' => '#004F9F',
                'is_active' => true,
            ]
        );
    }
}
