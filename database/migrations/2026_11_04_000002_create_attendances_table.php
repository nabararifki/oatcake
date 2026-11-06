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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('work_schedule_id')
                ->nullable()
                ->constrained('work_schedules')
                ->nullOnDelete();
            $table->timestampTz('clock_in_at')->nullable();
            $table->timestampTz('clock_out_at')->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->integer('clock_in_distance_meters')->nullable();
            $table->integer('clock_out_distance_meters')->nullable();
            $table->string('status', 30)->default('on_time')->index();
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leave_minutes')->default(0);
            $table->boolean('is_outside_geofence')->default(false);
            $table->boolean('is_provisional_dp')->default(false)->index();
            $table->string('device_info', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('employee_id');
            $table->index('work_schedule_id');
            $table->index(['employee_id', 'clock_in_at']);
            $table->index(['company_id', 'clock_in_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('on_time', 'late', 'early_leave', 'pending_verification', 'rejected'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
