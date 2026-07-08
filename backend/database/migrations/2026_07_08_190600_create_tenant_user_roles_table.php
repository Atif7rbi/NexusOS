<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Security Boundary
    |--------------------------------------------------------------------------
    |
    | tenant_id is duplicated here intentionally (also derivable via
    | tenant_user_id -> tenant_users.tenant_id). This enables composite
    | Foreign Keys that enforce, at the database level, that a role
    | assignment can never cross tenant boundaries.
    |
    | Principle: Multi-tenancy isolation > avoiding small duplication.
    |
    */
    public function up(): void
    {
        Schema::create('tenant_user_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('tenant_id');
            $table->ulid('tenant_user_id');
            $table->ulid('role_id');

            $table->foreignUlid('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('assigned_at');

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'tenant_user_id', 'role_id'],
                'uq_tenant_user_roles_tenant_user_role'
            );

            $table->index('tenant_user_id', 'idx_tenant_user_roles_tenant_user');
            $table->index('role_id', 'idx_tenant_user_roles_role');
        });

        // Composite FK: tenant_user_id must belong to the same tenant_id
        DB::statement(<<<SQL
            ALTER TABLE tenant_user_roles
            ADD CONSTRAINT fk_tur_tenant_user
            FOREIGN KEY (tenant_id, tenant_user_id)
            REFERENCES tenant_users (tenant_id, id)
            ON DELETE RESTRICT
        SQL);

        // Composite FK: role_id must belong to the same tenant_id
        DB::statement(<<<SQL
            ALTER TABLE tenant_user_roles
            ADD CONSTRAINT fk_tur_role
            FOREIGN KEY (tenant_id, role_id)
            REFERENCES roles (tenant_id, id)
            ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_roles');
    }
};
