<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('module_id')
                ->constrained('modules')
                ->restrictOnDelete();

            $table->string('code', 150);
            $table->string('name', 120);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampTz('deprecated_at')->nullable();

            $table->timestampsTz();
        });

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX uq_permissions_code
            ON permissions (code)
        SQL);

        DB::statement(<<<SQL
            CREATE INDEX idx_permissions_module_active
            ON permissions (module_id, is_active)
        SQL);

        DB::statement(<<<SQL
            CREATE INDEX idx_permissions_active
            ON permissions (is_active)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
