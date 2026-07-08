<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 150);
            $table->string('email');
            $table->string('password')->nullable();

            $table->timestampTz('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();

            $table->timestampsTz();

            $table->index('is_active');
            $table->index('last_login_at');
            $table->index('email_verified_at');
        });

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX users_email_unique_lower
            ON users (LOWER(email))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
