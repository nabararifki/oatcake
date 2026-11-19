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
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasIndex('employees', 'employees_user_id_idx')) {
                $table->index('user_id', 'employees_user_id_idx');
            }
            if (! Schema::hasIndex('employees', 'employees_manager_id_idx')) {
                $table->index('manager_id', 'employees_manager_id_idx');
            }
        });

        Schema::table('employee_tags', function (Blueprint $table) {
            if (! Schema::hasIndex('employee_tags', 'employee_tags_tag_id_idx')) {
                $table->index('tag_id', 'employee_tags_tag_id_idx');
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS leave_ledger_balance_lookup_idx ON leave_ledger_entries (company_id, employee_id, leave_type_id, id DESC)');

        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasIndex('attendances', 'attendances_created_at_idx')) {
                $table->index('created_at', 'attendances_created_at_idx');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS audit_logs_old_values_gin_idx');
            DB::statement('DROP INDEX IF EXISTS audit_logs_new_values_gin_idx');
            DB::statement('DROP INDEX IF EXISTS audit_logs_metadata_gin_idx');

            DB::statement('CREATE INDEX audit_logs_old_values_gin_idx ON audit_logs USING gin (old_values jsonb_path_ops)');
            DB::statement('CREATE INDEX audit_logs_new_values_gin_idx ON audit_logs USING gin (new_values jsonb_path_ops)');
            DB::statement('CREATE INDEX audit_logs_metadata_gin_idx ON audit_logs USING gin (metadata jsonb_path_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasIndex('employees', 'employees_user_id_idx')) {
                $table->dropIndex('employees_user_id_idx');
            }
            if (Schema::hasIndex('employees', 'employees_manager_id_idx')) {
                $table->dropIndex('employees_manager_id_idx');
            }
        });

        Schema::table('employee_tags', function (Blueprint $table) {
            if (Schema::hasIndex('employee_tags', 'employee_tags_tag_id_idx')) {
                $table->dropIndex('employee_tags_tag_id_idx');
            }
        });

        DB::statement('DROP INDEX IF EXISTS leave_ledger_balance_lookup_idx');

        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasIndex('attendances', 'attendances_created_at_idx')) {
                $table->dropIndex('attendances_created_at_idx');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS audit_logs_old_values_gin_idx');
            DB::statement('DROP INDEX IF EXISTS audit_logs_new_values_gin_idx');
            DB::statement('DROP INDEX IF EXISTS audit_logs_metadata_gin_idx');

            DB::statement('CREATE INDEX audit_logs_old_values_gin_idx ON audit_logs USING gin (old_values)');
            DB::statement('CREATE INDEX audit_logs_new_values_gin_idx ON audit_logs USING gin (new_values)');
            DB::statement('CREATE INDEX audit_logs_metadata_gin_idx ON audit_logs USING gin (metadata)');
        }
    }
};
