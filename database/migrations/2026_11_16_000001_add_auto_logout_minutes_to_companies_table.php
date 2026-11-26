<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add configurable idle auto-logout timeout (in minutes) per tenant company.
     */
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'auto_logout_minutes')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->integer('auto_logout_minutes')->default(30)->after('photo_retention_months');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'auto_logout_minutes')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('auto_logout_minutes');
            });
        }
    }
};
