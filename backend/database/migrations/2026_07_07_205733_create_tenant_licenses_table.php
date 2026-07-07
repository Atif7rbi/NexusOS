<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUlid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            $table->string('status', 30);

            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('grace_ends_at')->nullable();

            $table->timestampsTz();

            $table->index('tenant_id');
            $table->index('plan_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('grace_ends_at');
            $table->index(['tenant_id', 'status']);
        });

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX one_current_license_per_tenant
            ON tenant_licenses (tenant_id)
            WHERE status IN ('trial', 'active', 'grace_period')
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_status_check
            CHECK (status IN ('trial', 'active', 'grace_period', 'expired', 'cancelled'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_licenses');
    }
};
