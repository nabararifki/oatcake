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
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('category', 40)->index();
            $table->string('title', 200);
            $table->text('body');
            $table->string('action_url', 255)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('read_at')->nullable()->index();
            $table->timestampTz('pushed_at')->nullable();
            $table->timestampsTz();

            $table->index('company_id');
            $table->index('user_id');
            $table->index(['user_id', 'read_at', 'created_at'], 'user_notif_user_read_created_idx');
            $table->index(['company_id', 'created_at'], 'user_notif_company_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
