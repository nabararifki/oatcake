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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->string('event_type', 50)->index();
            $table->string('auditable_type', 80)->index();
            $table->unsignedBigInteger('auditable_id')->nullable()->index();
            $table->string('description', 255);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->index();

            $table->index('company_id');
            $table->index('actor_id');
            $table->index(['company_id', 'created_at'], 'audit_logs_company_created_idx');
            $table->index(['company_id', 'auditable_type', 'auditable_id'], 'audit_logs_company_auditable_idx');
            $table->index(['company_id', 'event_type', 'created_at'], 'audit_logs_company_event_created_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX audit_logs_old_values_gin_idx ON audit_logs USING gin (old_values)');
            DB::statement('CREATE INDEX audit_logs_new_values_gin_idx ON audit_logs USING gin (new_values)');
            DB::statement('CREATE INDEX audit_logs_metadata_gin_idx ON audit_logs USING gin (metadata)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
