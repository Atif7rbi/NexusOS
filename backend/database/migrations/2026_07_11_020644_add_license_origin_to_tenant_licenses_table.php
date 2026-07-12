<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->string('license_origin', 20)
                ->nullable()
                ->after('plan_id');
        });

        /*
         * Existing rows cannot be classified reliably from their current
         * status because a trial-origin license may already be active,
         * expired, or cancelled.
         *
         * Refuse silent or guessed backfilling.
         */
        if (DB::table('tenant_licenses')->exists()) {
            throw new \RuntimeException(
                'Cannot add tenant_licenses.license_origin while existing '
                .'license rows require an explicit origin backfill.'
            );
        }

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ALTER COLUMN license_origin SET NOT NULL
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_license_origin_check
            CHECK (license_origin IN ('trial', 'subscription'))
        SQL);

        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->index([
                'tenant_id',
                'license_origin',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->dropIndex([
                'tenant_id',
                'license_origin',
            ]);
        });

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            DROP CONSTRAINT tenant_licenses_license_origin_check
        SQL);

        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->dropColumn('license_origin');
        });
    }
};
