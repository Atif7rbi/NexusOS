<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 150);
            $table->string('code', 100);
            $table->string('category', 50);

            $table->string('version', 30)->default('1.0.0');
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('deprecated_at')->nullable();

            $table->timestampsTz();

            $table->unique('code');
            $table->index('category');
            $table->index('is_active');
        });

        DB::statement(<<<SQL
            ALTER TABLE modules
            ADD CONSTRAINT modules_category_check
            CHECK (category IN ('core', 'business', 'industry', 'reporting', 'ai', 'integration'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
