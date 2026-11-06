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
        Schema::table('departments', function (Blueprint $table) {
            if (! Schema::hasColumn('departments', 'is_24_hours')) {
                $table->boolean('is_24_hours')->default(false)->after('is_operational');
            }
            if (! Schema::hasColumn('departments', 'description')) {
                $table->string('description', 255)->nullable()->after('min_staff_per_shift');
            }
        });

        DB::table('departments')->update([
            'is_24_hours' => DB::raw('is_operational'),
        ]);

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'address')) {
                $table->string('address', 255)->nullable()->after('name');
            }
            if (! Schema::hasColumn('companies', 'geofence_radius')) {
                $table->integer('geofence_radius')->default(100)->after('geofence_radius_meters');
            }
            if (! Schema::hasColumn('companies', 'timezone')) {
                $table->string('timezone', 50)->default('Asia/Jakarta')->after('geofence_radius_meters');
            }
        });

        DB::table('companies')->update([
            'geofence_radius' => DB::raw('geofence_radius_meters'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('departments', 'is_24_hours')) {
                $cols[] = 'is_24_hours';
            }
            if (Schema::hasColumn('departments', 'description')) {
                $cols[] = 'description';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('companies', 'address')) {
                $cols[] = 'address';
            }
            if (Schema::hasColumn('companies', 'geofence_radius')) {
                $cols[] = 'geofence_radius';
            }
            if (Schema::hasColumn('companies', 'timezone')) {
                $cols[] = 'timezone';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
