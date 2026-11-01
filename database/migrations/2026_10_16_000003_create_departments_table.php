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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->boolean('is_operational')->default(false);
            $table->integer('min_staff_per_shift')->default(1);
            $table->boolean('is_executive_oversight')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index('company_id');
            $table->index('is_operational');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
