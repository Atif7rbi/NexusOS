<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 150);
            $table->string('slug', 120);
            $table->string('status', 30)->default('active');

            $table->string('default_currency', 3)->default('SAR');
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->string('locale', 10)->default('ar');

            $table->timestampsTz();

            $table->unique('slug');
            $table->index('status');
        });

        DB::statement(<<<SQL
            ALTER TABLE tenants
            ADD CONSTRAINT tenants_status_check
            CHECK (status IN ('active', 'suspended', 'cancelled'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
