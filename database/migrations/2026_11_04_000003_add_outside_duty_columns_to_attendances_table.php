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
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('proof_photo_path', 255)->nullable()->after('is_provisional_dp');
            $table->string('outside_category', 50)->nullable()->after('proof_photo_path');
            $table->string('outside_location_name', 150)->nullable()->after('outside_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'proof_photo_path',
                'outside_category',
                'outside_location_name',
            ]);
        });
    }
};
