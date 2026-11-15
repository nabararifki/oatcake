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
        Schema::create('leave_request_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('tier');
            $table->string('action', 20);
            $table->text('notes')->nullable();
            $table->decimal('smart_score_snapshot', 5, 2)->nullable();
            $table->timestampTz('acted_at');
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('leave_request_id');
            $table->index('approver_id');
            $table->index(['leave_request_id', 'tier', 'acted_at'], 'leave_req_approvals_req_tier_acted_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE leave_request_approvals ADD CONSTRAINT leave_request_approvals_tier_check CHECK (tier IN (1, 2))'
            );
            DB::statement(
                "ALTER TABLE leave_request_approvals ADD CONSTRAINT leave_request_approvals_action_check CHECK (action IN ('approved', 'rejected'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_request_approvals');
    }
};
