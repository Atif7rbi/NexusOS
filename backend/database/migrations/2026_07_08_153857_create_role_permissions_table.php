<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('role_id')
                ->constrained('roles')
                ->restrictOnDelete();

            $table->foreignUlid('permission_id')
                ->constrained('permissions')
                ->restrictOnDelete();

            $table->timestampsTz();

            $table->unique(
                ['role_id', 'permission_id'],
                'uq_role_permissions_role_permission'
            );

            $table->index(
                'permission_id',
                'idx_role_permissions_permission'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
