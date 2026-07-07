<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            $table->foreignUlid('module_id')
                ->constrained('modules')
                ->restrictOnDelete();

            $table->timestampsTz();

            $table->unique(['plan_id', 'module_id']);
            $table->index('plan_id');
            $table->index('module_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
    }
};
