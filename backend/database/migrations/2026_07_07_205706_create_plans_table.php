<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 120);
            $table->string('code', 80);
            $table->string('billing_cycle', 20);
            $table->text('description')->nullable();

            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('SAR');

            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique('code');
            $table->index('billing_cycle');
            $table->index('is_active');
        });

        DB::statement(<<<SQL
            ALTER TABLE plans
            ADD CONSTRAINT plans_billing_cycle_check
            CHECK (billing_cycle IN ('monthly', 'yearly'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
