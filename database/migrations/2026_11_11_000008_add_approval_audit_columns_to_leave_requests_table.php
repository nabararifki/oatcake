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
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignId('ledger_entry_id')
                ->nullable()
                ->after('smart_metrics')
                ->constrained('leave_ledger_entries')
                ->nullOnDelete();

            $table->foreignId('manager_approved_by')
                ->nullable()
                ->after('ledger_entry_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('manager_approved_at')
                ->nullable()
                ->after('manager_approved_by');

            $table->foreignId('hr_approved_by')
                ->nullable()
                ->after('manager_approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('hr_approved_at')
                ->nullable()
                ->after('hr_approved_by');

            $table->foreignId('rejected_by')
                ->nullable()
                ->after('hr_approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('rejected_at')
                ->nullable()
                ->after('rejected_by');

            $table->text('rejection_reason')
                ->nullable()
                ->after('rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ledger_entry_id');
            $table->dropConstrainedForeignId('manager_approved_by');
            $table->dropColumn('manager_approved_at');
            $table->dropConstrainedForeignId('hr_approved_by');
            $table->dropColumn('hr_approved_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};
