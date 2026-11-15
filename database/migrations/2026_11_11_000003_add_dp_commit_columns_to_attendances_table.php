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
            $table->timestampTz('dp_committed_at')->nullable()->after('is_provisional_dp');
            $table->foreignId('dp_ledger_entry_id')
                ->nullable()
                ->after('dp_committed_at')
                ->constrained('leave_ledger_entries')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dp_ledger_entry_id');
            $table->dropColumn('dp_committed_at');
        });
    }
};
