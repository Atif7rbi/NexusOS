<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Referential Constraint (not a Business Rule)
    |--------------------------------------------------------------------------
    |
    | These composite UNIQUE constraints exist solely to enable composite
    | Foreign Keys from tenant_user_roles. They do not enforce any new
    | business uniqueness — `id` alone is already globally unique (ULID).
    | PostgreSQL requires the referenced column set to be covered by a
    | UNIQUE/PK constraint before it can be targeted by a composite FK.
    |
    */
    public function up(): void
    {
        DB::statement(<<<SQL
            ALTER TABLE tenant_users
            ADD CONSTRAINT uq_tenant_users_tenant_id_id
            UNIQUE (tenant_id, id)
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE roles
            ADD CONSTRAINT uq_roles_tenant_id_id
            UNIQUE (tenant_id, id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_users DROP CONSTRAINT uq_tenant_users_tenant_id_id');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT uq_roles_tenant_id_id');
    }
};
