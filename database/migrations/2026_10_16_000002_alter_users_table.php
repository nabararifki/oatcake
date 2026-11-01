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
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->string('username', 100)->unique();
            $table->string('password', 255)->nullable()->change();
            $table->string('role', 30)->default('user');
            $table->string('google_id', 100)->nullable()->unique();
            $table->string('avatar_url', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->index('company_id');
            $table->index('role');
            $table->index('is_active');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'super_user', 'admin', 'manager', 'user'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);

            $table->dropColumn([
                'uuid',
                'company_id',
                'username',
                'role',
                'google_id',
                'avatar_url',
                'is_active',
            ]);

            $table->string('password', 255)->nullable(false)->change();
        });
    }
};
