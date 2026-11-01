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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('nik', 50);
            $table->foreignId('department_id')
                ->constrained('departments')
                ->restrictOnDelete();
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();
            $table->string('position', 100);
            $table->date('join_date');
            $table->string('phone', 30)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestampsTz();

            $table->unique(['company_id', 'nik']);
            $table->unique(['company_id', 'user_id']);
            $table->index('company_id');
            $table->index('department_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'resigned', 'suspended'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
