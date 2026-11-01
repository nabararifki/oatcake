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
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'schedule_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_schedules DROP CONSTRAINT IF EXISTS work_schedules_status_check');
            DB::statement("ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_status_check CHECK (status IN ('draft', 'published', 'pending_delete', 'on_leave'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE work_schedules DROP CONSTRAINT IF EXISTS work_schedules_status_check');
            DB::statement("ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_status_check CHECK (status IN ('draft', 'published', 'on_leave'))");
        }

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unique(['employee_id', 'schedule_date']);
        });
    }
};
