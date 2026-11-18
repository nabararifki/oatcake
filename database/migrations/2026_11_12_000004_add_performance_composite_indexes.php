<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            if (! Schema::hasIndex('work_schedules', 'ws_company_date_status_idx')) {
                $table->index(['company_id', 'schedule_date', 'status'], 'ws_company_date_status_idx');
            }
            if (! Schema::hasIndex('work_schedules', 'ws_emp_date_status_idx')) {
                $table->index(['employee_id', 'schedule_date', 'status'], 'ws_emp_date_status_idx');
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasIndex('attendances', 'att_company_status_clockin_idx')) {
                $table->index(['company_id', 'status', 'clock_in_at'], 'att_company_status_clockin_idx');
            }
            if (! Schema::hasIndex('attendances', 'att_emp_status_clockin_idx')) {
                $table->index(['employee_id', 'status', 'clock_in_at'], 'att_emp_status_clockin_idx');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (! Schema::hasIndex('leave_requests', 'lr_company_status_dates_idx')) {
                $table->index(['company_id', 'status', 'start_date', 'end_date'], 'lr_company_status_dates_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            if (Schema::hasIndex('work_schedules', 'ws_company_date_status_idx')) {
                $table->dropIndex('ws_company_date_status_idx');
            }
            if (Schema::hasIndex('work_schedules', 'ws_emp_date_status_idx')) {
                $table->dropIndex('ws_emp_date_status_idx');
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasIndex('attendances', 'att_company_status_clockin_idx')) {
                $table->dropIndex('att_company_status_clockin_idx');
            }
            if (Schema::hasIndex('attendances', 'att_emp_status_clockin_idx')) {
                $table->dropIndex('att_emp_status_clockin_idx');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasIndex('leave_requests', 'lr_company_status_dates_idx')) {
                $table->dropIndex('lr_company_status_dates_idx');
            }
        });
    }
};
