<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'superadmin'],
            [
                'name' => 'Super Administrator',
                'email' => 'admin@zonabara.com',
                'password' => Hash::make('Password123!'),
                'role' => 'super_admin',
                'company_id' => null,
                'is_active' => true,
            ]
        );
    }
}
