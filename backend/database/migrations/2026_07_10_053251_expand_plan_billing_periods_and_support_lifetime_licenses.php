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
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('billing_period_unit', 20)
                ->nullable()
                ->after('billing_cycle');

            $table->unsignedInteger('billing_period_count')
                ->nullable()
                ->after('billing_period_unit');
        });

        DB::statement(<<<SQL
            UPDATE plans
            SET
                billing_period_unit = CASE billing_cycle
                    WHEN 'monthly' THEN 'month'
                    WHEN 'yearly' THEN 'year'
                END,
                billing_period_count = 1
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE plans
            ALTER COLUMN billing_period_unit SET NOT NULL
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE plans
            DROP CONSTRAINT plans_billing_cycle_check
        SQL);

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropIndex(['billing_cycle']);
            $table->dropColumn('billing_cycle');
        });

        DB::statement(<<<SQL
            ALTER TABLE plans
            ADD CONSTRAINT plans_billing_period_check
            CHECK (
                (
                    billing_period_unit = 'month'
                    AND billing_period_count IN (1, 3, 6, 10)
                )
                OR
                (
                    billing_period_unit = 'year'
                    AND billing_period_count IS NOT NULL
                    AND billing_period_count >= 1
                )
                OR
                (
                    billing_period_unit = 'lifetime'
                    AND billing_period_count IS NULL
                )
            )
        SQL);

        Schema::table('plans', function (Blueprint $table): void {
            $table->index('billing_period_unit');

            $table->index([
                'billing_period_unit',
                'billing_period_count',
            ]);
        });

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ALTER COLUMN expires_at DROP NOT NULL
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ADD CONSTRAINT tenant_licenses_grace_period_check
            CHECK (
                grace_ends_at IS NULL
                OR (
                    expires_at IS NOT NULL
                    AND grace_ends_at > expires_at
                )
            )
        SQL);
    }

    public function down(): void
    {
        $incompatiblePlans = DB::table('plans')
            ->where(function ($query): void {
                $query
                    ->where('billing_period_unit', 'lifetime')
                    ->orWhere(function ($query): void {
                        $query
                            ->whereIn('billing_period_unit', ['month', 'year'])
                            ->where('billing_period_count', '<>', 1);
                    });
            })
            ->exists();

        if ($incompatiblePlans) {
            throw new \RuntimeException(
                'Cannot roll back while multi-period or lifetime plans exist.'
            );
        }

        $lifetimeLicenses = DB::table('tenant_licenses')
            ->whereNull('expires_at')
            ->exists();

        if ($lifetimeLicenses) {
            throw new \RuntimeException(
                'Cannot roll back while lifetime tenant licenses exist.'
            );
        }

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            DROP CONSTRAINT tenant_licenses_grace_period_check
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE tenant_licenses
            ALTER COLUMN expires_at SET NOT NULL
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE plans
            DROP CONSTRAINT plans_billing_period_check
        SQL);

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropIndex([
                'billing_period_unit',
                'billing_period_count',
            ]);

            $table->dropIndex(['billing_period_unit']);

            $table->string('billing_cycle', 20)
                ->nullable()
                ->after('code');
        });

        DB::statement(<<<SQL
            UPDATE plans
            SET billing_cycle = CASE billing_period_unit
                WHEN 'month' THEN 'monthly'
                WHEN 'year' THEN 'yearly'
            END
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE plans
            ALTER COLUMN billing_cycle SET NOT NULL
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE plans
            ADD CONSTRAINT plans_billing_cycle_check
            CHECK (billing_cycle IN ('monthly', 'yearly'))
        SQL);

        Schema::table('plans', function (Blueprint $table): void {
            $table->index('billing_cycle');

            $table->dropColumn([
                'billing_period_unit',
                'billing_period_count',
            ]);
        });
    }
};
