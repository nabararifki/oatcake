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
            $table->foreignId('verified_by')
                ->nullable()
                ->after('outside_location_name')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('verified_at')->nullable()->after('verified_by');
            $table->text('rejection_reason')->nullable()->after('verified_at');

            $table->index('verified_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['verified_by']);
            $table->dropColumn([
                'verified_by',
                'verified_at',
                'rejection_reason',
            ]);
        });
    }
};
