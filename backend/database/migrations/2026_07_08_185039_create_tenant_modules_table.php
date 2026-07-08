<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUlid('module_id')
                ->constrained('modules')
                ->restrictOnDelete();

            $table->string('status', 30);
            $table->string('source', 30);

            $table->foreignUlid('enabled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'module_id'],
                'uq_tenant_modules_tenant_module'
            );
            $table->index(
                ['tenant_id', 'status'],
                'idx_tenant_modules_tenant_status'
            );
            $table->index(
                ['module_id', 'status'],
                'idx_tenant_modules_module_status'
            );
            $table->index(
                'source',
                'idx_tenant_modules_source'
            );
        });

        DB::statement(<<<SQL
            ALTER TABLE tenant_modules
            ADD CONSTRAINT tenant_modules_status_check
            CHECK (status IN ('enabled', 'disabled'))
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE tenant_modules
            ADD CONSTRAINT tenant_modules_source_check
            CHECK (source IN ('plan', 'manual', 'trial', 'promo', 'override'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
