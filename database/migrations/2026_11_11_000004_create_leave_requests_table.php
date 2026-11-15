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
        Schema::create('leave_requests', function (Blueprint $table) {
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
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->decimal('total_days', 5, 2);
            $table->text('reason');
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 30)->index();
            $table->decimal('smart_score', 5, 2)->nullable();
            $table->jsonb('smart_metrics')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('employee_id');
            $table->index('leave_type_id');
            $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'leave_req_emp_status_dates_idx');
            $table->index(['company_id', 'status', 'created_at'], 'leave_req_comp_status_created_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending_manager', 'pending_hr', 'approved', 'rejected', 'cancelled'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
