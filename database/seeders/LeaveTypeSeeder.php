<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Seed the standard system leave types (AL, DP, EO, SL, SPL, UL).
     */
    public function run(): void
    {
        foreach (LeaveType::STANDARD_TYPES as $item) {
            $existing = LeaveType::withoutGlobalScopes()
                ->whereNull('company_id')
                ->where('code', $item['code'])
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'name' => $item['name'],
                    'is_paid' => $item['is_paid'],
                    'requires_quota' => $item['requires_quota'],
                    'requires_attachment' => $item['requires_attachment'],
                    'default_annual_quota' => $item['default_annual_quota'],
                    'validity_months' => $item['validity_months'],
                    'is_active' => $item['is_active'],
                ]);
            } else {
                LeaveType::withoutGlobalScopes()->create(array_merge(
                    ['company_id' => null],
                    $item
                ));
            }
        }
    }
}
