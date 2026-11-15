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
        Schema::create('smart_department_weights', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('criteria_code', 10);
            $table->smallInteger('raw_weight')->default(20);
            $table->decimal('normalized_weight', 6, 4)->default(0.2000);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('department_id');
            $table->unique(['department_id', 'criteria_code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE smart_department_weights ADD CONSTRAINT smart_department_weights_criteria_code_check CHECK (criteria_code IN ('C1', 'C2', 'C3', 'C4', 'C5'))"
            );
            DB::statement(
                'ALTER TABLE smart_department_weights ADD CONSTRAINT smart_department_weights_raw_weight_check CHECK (raw_weight >= 0 AND raw_weight <= 100)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_department_weights');
    }
};
