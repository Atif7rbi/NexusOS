<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Audit Log — System Event Ledger
    |--------------------------------------------------------------------------
    |
    | This table is intentionally exempt from the standard Foreign Key
    | convention. tenant_id, actor_user_id, and entity_id carry NO real FK,
    | because a ledger must survive the deletion of the entities it describes.
    |
    | This table is append-only: no UPDATE, no DELETE from the application.
    | Enforced at two layers: Service Layer discipline + PostgreSQL trigger.
    |
    */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('tenant_id')->nullable();
            $table->ulid('actor_user_id')->nullable();

            $table->string('category', 20);
            $table->string('event', 120);

            $table->string('entity_type', 100)->nullable();
            $table->ulid('entity_id')->nullable();

            $table->string('request_id', 36)->nullable();

            $table->jsonb('changes')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'created_at'], 'idx_audit_logs_tenant_created');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
            $table->index(['actor_user_id', 'created_at'], 'idx_audit_logs_actor_created');
            $table->index(['category', 'created_at'], 'idx_audit_logs_category_created');
            $table->index('request_id', 'idx_audit_logs_request');
            $table->index('created_at', 'idx_audit_logs_created');
        });

        DB::statement(<<<SQL
            ALTER TABLE audit_logs
            ADD CONSTRAINT audit_logs_category_check
            CHECK (category IN ('business', 'security', 'system'))
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE audit_logs
            ADD CONSTRAINT audit_logs_entity_consistency_check
            CHECK (
                (entity_type IS NULL AND entity_id IS NULL) OR
                (entity_type IS NOT NULL AND entity_id IS NOT NULL)
            )
        SQL);

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION audit_logs_prevent_update_delete()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not allowed', TG_OP;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER trg_audit_logs_no_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW EXECUTE PROCEDURE audit_logs_prevent_update_delete();
        SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER trg_audit_logs_no_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW EXECUTE PROCEDURE audit_logs_prevent_update_delete();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_logs_no_update ON audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_logs_no_delete ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_prevent_update_delete()');

        Schema::dropIfExists('audit_logs');
    }
};
