<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Round existing fractional day columns before converting column types to integer
        DB::statement('UPDATE leave_types SET default_annual_quota = ROUND(default_annual_quota) WHERE default_annual_quota IS NOT NULL');
        DB::statement('UPDATE company_leave_type_settings SET default_annual_quota = ROUND(default_annual_quota) WHERE default_annual_quota IS NOT NULL');
        DB::statement('UPDATE leave_ledger_entries SET amount = ROUND(amount), remaining_amount = ROUND(remaining_amount), balance_after = ROUND(balance_after)');
        DB::statement('UPDATE leave_requests SET total_days = ROUND(total_days)');

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->integer('default_annual_quota')->nullable()->change();
        });

        Schema::table('company_leave_type_settings', function (Blueprint $table): void {
            $table->integer('default_annual_quota')->nullable()->change();
        });

        Schema::table('leave_ledger_entries', function (Blueprint $table): void {
            $table->integer('amount')->change();
            $table->integer('remaining_amount')->default(0)->change();
            $table->integer('balance_after')->change();
        });

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->integer('total_days')->change();
        });

        if (! Schema::hasColumn('companies', 'auto_assign_shift_on_clock_in')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->boolean('auto_assign_shift_on_clock_in')->default(true)->after('geofence_radius_meters');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('companies', 'auto_assign_shift_on_clock_in')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('auto_assign_shift_on_clock_in');
            });
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->decimal('total_days', 5, 2)->change();
        });

        Schema::table('leave_ledger_entries', function (Blueprint $table): void {
            $table->decimal('amount', 6, 2)->change();
            $table->decimal('remaining_amount', 6, 2)->default(0)->change();
            $table->decimal('balance_after', 6, 2)->change();
        });

        Schema::table('company_leave_type_settings', function (Blueprint $table): void {
            $table->decimal('default_annual_quota', 5, 2)->nullable()->change();
        });

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->decimal('default_annual_quota', 5, 2)->nullable()->change();
        });
    }
};
