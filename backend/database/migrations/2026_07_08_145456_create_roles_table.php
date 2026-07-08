<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->restrictOnDelete();

            $table->ulid('source_role_id')->nullable();

            $table->string('code', 100);
            $table->string('name', 120);
            $table->text('description')->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_template')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampTz('deprecated_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'is_active'], 'idx_roles_tenant_active');
            $table->index('is_active', 'idx_roles_active');
            $table->index('source_role_id', 'idx_roles_source');
        });

        DB::statement(<<<SQL
            ALTER TABLE roles
            ADD CONSTRAINT roles_source_role_id_foreign
            FOREIGN KEY (source_role_id)
            REFERENCES roles(id)
            ON DELETE RESTRICT
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE roles
            ADD CONSTRAINT roles_template_tenant_check
            CHECK (
                (tenant_id IS NULL AND is_template = true)
                OR
                (tenant_id IS NOT NULL AND is_template = false)
            )
        SQL);

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX uq_roles_template_code
            ON roles (code)
            WHERE tenant_id IS NULL
        SQL);

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX uq_roles_tenant_code
            ON roles (tenant_id, code)
            WHERE tenant_id IS NOT NULL
        SQL);

    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
