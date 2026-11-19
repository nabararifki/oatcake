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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_company_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_employee_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_leave_type_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_created_by_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_company_id_foreign');
            DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT');

            return;
        }

        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['leave_type_id']);
            $table->dropForeign(['created_by']);

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_company_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_employee_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_leave_type_id_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT');

            DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT IF EXISTS leave_ledger_entries_created_by_foreign');
            DB::statement('ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_company_id_foreign');
            DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE');

            return;
        }

        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['leave_type_id']);
            $table->dropForeign(['created_by']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }
};
