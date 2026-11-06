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
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('clock_in_photo_path', 255)->nullable()->after('is_provisional_dp');
            $table->string('clock_out_photo_path', 255)->nullable()->after('clock_in_photo_path');
        });

        if (Schema::hasColumn('attendances', 'proof_photo_path')) {
            DB::table('attendances')
                ->whereNotNull('proof_photo_path')
                ->update(['clock_in_photo_path' => DB::raw('proof_photo_path')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['clock_in_photo_path', 'clock_out_photo_path']);
        });
    }
};
