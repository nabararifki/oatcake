<?php

use App\Models\LeaveType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Seed the Extra Off (EO) standard leave type idempotently for existing databases.
     */
    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }

        $definition = LeaveType::STANDARD_TYPES['EO'];

        $existing = LeaveType::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', 'EO')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'name' => $definition['name'],
                'is_paid' => $definition['is_paid'],
                'requires_quota' => $definition['requires_quota'],
                'requires_attachment' => $definition['requires_attachment'],
                'default_annual_quota' => $definition['default_annual_quota'],
                'validity_months' => $definition['validity_months'],
                'is_active' => $definition['is_active'],
            ]);

            return;
        }

        LeaveType::withoutGlobalScopes()->create(array_merge(
            [
                'uuid' => (string) Str::uuid(),
                'company_id' => null,
            ],
            $definition
        ));
    }

    /**
     * Reverse the migration cleanly only if EO has never been referenced.
     */
    public function down(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }

        $eo = LeaveType::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', 'EO')
            ->first();

        if ($eo === null) {
            return;
        }

        $hasRequests = Schema::hasTable('leave_requests') && $eo->leaveRequests()->exists();
        $hasLedger = Schema::hasTable('leave_ledger_entries') && $eo->ledgerEntries()->exists();

        if (! $hasRequests && ! $hasLedger) {
            $eo->delete();
        }
    }
};
