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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        } elseif ($driver === 'sqlite') {
            try {
                DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            } catch (Throwable) {
                // Older SQLite versions without DROP CONSTRAINT support ignore ALTER constraint drop.
            }
        }

        DB::table('users')->where('role', 'admin')->update(['role' => 'admin_hr']);

        $newConstraint = "ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'super_user', 'admin_hr', 'manager', 'admin_staff', 'user'))";

        if ($driver === 'pgsql') {
            DB::statement($newConstraint);
        } elseif ($driver === 'sqlite') {
            try {
                DB::statement($newConstraint);
            } catch (Throwable) {
                // Ignore if SQLite version cannot add constraint via ALTER TABLE.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        } elseif ($driver === 'sqlite') {
            try {
                DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            } catch (Throwable) {
                // Older SQLite versions without DROP CONSTRAINT support ignore ALTER constraint drop.
            }
        }

        DB::table('users')->where('role', 'admin_hr')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'admin_staff')->update(['role' => 'user']);

        $oldConstraint = "ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'super_user', 'admin', 'manager', 'user'))";

        if ($driver === 'pgsql') {
            DB::statement($oldConstraint);
        } elseif ($driver === 'sqlite') {
            try {
                DB::statement($oldConstraint);
            } catch (Throwable) {
                // Ignore if SQLite version cannot add constraint via ALTER TABLE.
            }
        }
    }
};
