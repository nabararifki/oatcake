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
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->restrictOnDelete();
            $table->string('transaction_type', 30)->index();
            $table->decimal('amount', 6, 2);
            $table->decimal('remaining_amount', 6, 2)->default(0);
            $table->decimal('balance_after', 6, 2);
            $table->foreignId('source_entry_id')
                ->nullable()
                ->constrained('leave_ledger_entries')
                ->nullOnDelete();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->smallInteger('accrual_year')->nullable()->index();
            $table->date('expires_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('employee_id');
            $table->index('leave_type_id');
            $table->index(['employee_id', 'leave_type_id', 'created_at'], 'leave_ledger_emp_type_created_idx');
            $table->index(['leave_type_id', 'expires_at', 'remaining_amount'], 'leave_ledger_type_exp_rem_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_transaction_type_check CHECK (transaction_type IN ('accrual', 'credit', 'debit', 'expired', 'adjustment'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
    }
};
