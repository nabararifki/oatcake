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
        Schema::table('shifts', function (Blueprint $table) {
            $table->softDeletesTz();
            $table->dropUnique(['company_id', 'code']);
        });

        DB::statement('CREATE UNIQUE INDEX shifts_company_id_code_active_unique ON shifts (company_id, code) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_company_id_code_active_unique');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
            $table->unique(['company_id', 'code']);
        });
    }
};
