<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('public_holidays', 'is_national')) {
            Schema::table('public_holidays', function (Blueprint $table) {
                $table->boolean('is_national')->default(true)->after('name');
            });
        }

        // Drop the old global (holiday_date) partial unique index if present
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS public_holidays_national_date_unique');
        }

        // Replicate existing global holidays (company_id IS NULL) to every company
        $globalHolidays = DB::table('public_holidays')
            ->whereNull('company_id')
            ->get();

        $companyIds = DB::table('companies')->pluck('id');
        $now = now();

        foreach ($companyIds as $companyId) {
            foreach ($globalHolidays as $holiday) {
                $exists = DB::table('public_holidays')
                    ->where('company_id', $companyId)
                    ->where('holiday_date', $holiday->holiday_date)
                    ->exists();

                if ($exists) {
                    DB::table('public_holidays')
                        ->where('company_id', $companyId)
                        ->where('holiday_date', $holiday->holiday_date)
                        ->update([
                            'name' => $holiday->name,
                            'is_national' => (bool) ($holiday->is_national ?? true),
                            'is_recurring' => (bool) ($holiday->is_recurring ?? true),
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('public_holidays')->insert([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'holiday_date' => $holiday->holiday_date,
                        'name' => $holiday->name,
                        'is_national' => (bool) ($holiday->is_national ?? true),
                        'is_recurring' => (bool) ($holiday->is_recurring ?? true),
                        'created_at' => $holiday->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // Delete orphan rows where company_id IS NULL
        DB::table('public_holidays')->whereNull('company_id')->delete();

        // Make company_id NOT NULL while preserving foreign key cascadeOnDelete and composite unique index
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        if (Schema::hasColumn('public_holidays', 'is_national')) {
            Schema::table('public_holidays', function (Blueprint $table) {
                $table->dropColumn('is_national');
            });
        }
    }
};
