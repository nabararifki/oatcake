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
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete();
            $table->date('schedule_date');
            $table->time('custom_start_time')->nullable();
            $table->time('custom_end_time')->nullable();
            $table->foreignId('duty_tag_id')
                ->nullable()
                ->constrained('tags')
                ->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampsTz();

            // Fail-safe DDL Unique Composite Constraint: 1 staf maksimal 1 jadwal per tanggal
            $table->unique(['employee_id', 'schedule_date']);

            // Indeks performa untuk kueri kalender bulanan & filter status
            $table->index(['company_id', 'schedule_date']);
            $table->index(['employee_id', 'schedule_date']);
            $table->index('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_status_check CHECK (status IN ('draft', 'published', 'on_leave'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
