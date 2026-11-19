<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'inactive', 'on_leave', 'resigned', 'suspended'))");
        } elseif (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_status_check');
                DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'inactive', 'on_leave', 'resigned', 'suspended'))");
            } catch (Throwable) {
                // Older SQLite versions without DROP CONSTRAINT support ignore ALTER constraint drop.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'resigned', 'suspended'))");
        } elseif (DB::getDriverName() === 'sqlite') {
            try {
                DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_status_check');
                DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'resigned', 'suspended'))");
            } catch (Throwable) {
                // Older SQLite versions without DROP CONSTRAINT support ignore ALTER constraint drop.
            }
        }
    }
};
