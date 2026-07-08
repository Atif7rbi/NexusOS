<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 30)->default('active');

            // Business event: membership start date.
            $table->timestampTz('joined_at');

            $table->timestampsTz();

            // Indexes
            $table->index(
                'status',
                'idx_tenant_users_status'
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_tenant_users_tenant_status'
            );

            $table->index(
                ['user_id', 'status'],
                'idx_tenant_users_user_status'
            );
        });

        // Allowed membership status values.
        DB::statement(<<<SQL
            ALTER TABLE tenant_users
            ADD CONSTRAINT tenant_users_status_check
            CHECK (
                status IN (
                    'active',
                    'paused',
                    'suspended',
                    'removed'
                )
            )
        SQL);

        // Only one current membership per tenant and user.
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX uq_tenant_users_current_membership
            ON tenant_users (tenant_id, user_id)
            WHERE status IN (
                'active',
                'paused',
                'suspended'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
