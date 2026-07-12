<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\StartTenantSubscriptionResult;
use App\Exceptions\TenantLicense\CurrentTenantLicenseAlreadyExistsException;
use App\Exceptions\TenantLicense\PlanNotAvailableForLicenseException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\StartTenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StartTenantSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private StartTenantSubscription $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-25 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(StartTenantSubscription::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | A. Current-license eligibility
    |--------------------------------------------------------------------------
    */

    public function test_it_succeeds_when_tenant_has_no_license_history(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan('no-history');

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-no-history',
        );

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $result->status,
        );

        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => TenantLicense::STATUS_ACTIVE,
        ]);
    }

    public function test_it_succeeds_when_only_prior_license_is_expired(): void
    {
        $tenant = $this->createTenant();
        $oldPlan = $this->createPlan('expired-history-old');
        $newPlan = $this->createPlan('expired-history-new');

        $this->createHistoricalLicense(
            tenant: $tenant,
            plan: $oldPlan,
            status: TenantLicense::STATUS_EXPIRED,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $newPlan->id,
            requestId: 'subscription-after-expired',
        );

        $this->assertSame($newPlan->id, $result->planId);

        $this->assertSame(
            2,
            TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->count(),
        );
    }

    public function test_it_succeeds_when_only_prior_license_is_cancelled(): void
    {
        $tenant = $this->createTenant();
        $oldPlan = $this->createPlan('cancelled-history-old');
        $newPlan = $this->createPlan('cancelled-history-new');

        $this->createHistoricalLicense(
            tenant: $tenant,
            plan: $oldPlan,
            status: TenantLicense::STATUS_CANCELLED,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $newPlan->id,
            requestId: 'subscription-after-cancelled',
        );

        $this->assertSame($newPlan->id, $result->planId);

        $this->assertSame(
            1,
            TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->current()
                ->count(),
        );
    }

    public function test_expired_trial_history_does_not_block_subscription(): void
    {
        $tenant = $this->createTenant();
        $oldPlan = $this->createPlan('expired-trial-old');
        $newPlan = $this->createPlan('expired-trial-new');

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $oldPlan->id,
            'license_origin' => TenantLicense::ORIGIN_TRIAL,
            'status' => TenantLicense::STATUS_EXPIRED,
            'starts_at' => $this->occurredAt->subDays(30),
            'expires_at' => $this->occurredAt->subDays(16),
            'grace_ends_at' => null,
        ]);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $newPlan->id,
            requestId: 'subscription-after-expired-trial',
        );

        $this->assertSame(
            TenantLicense::ORIGIN_SUBSCRIPTION,
            TenantLicense::query()
                ->whereKey($result->tenantLicenseId)
                ->value('license_origin'),
        );
    }

    public function test_it_rejects_when_current_trial_exists(): void
    {
        $this->assertCurrentLicenseRejected(
            TenantLicense::STATUS_TRIAL,
            'subscription-current-trial',
        );
    }

    public function test_it_rejects_when_current_active_license_exists(): void
    {
        $this->assertCurrentLicenseRejected(
            TenantLicense::STATUS_ACTIVE,
            'subscription-current-active',
        );
    }

    public function test_it_rejects_when_current_grace_period_license_exists(): void
    {
        $this->assertCurrentLicenseRejected(
            TenantLicense::STATUS_GRACE_PERIOD,
            'subscription-current-grace',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B. Plan availability and subscription periods
    |--------------------------------------------------------------------------
    */

    public function test_it_rejects_an_inactive_plan(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'inactive-subscription-plan',
            isActive: false,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $plan->id,
                requestId: 'subscription-inactive-plan',
            );

            $this->fail('Expected inactive-plan rejection.');
        } catch (PlanNotAvailableForLicenseException $exception) {
            $this->assertSame(
                'The selected plan is not available for a new subscription.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('tenant_licenses', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_a_missing_plan(): void
    {
        $tenant = $this->createTenant();

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: (string) str()->ulid(),
                requestId: 'subscription-missing-plan',
            );

            $this->fail('Expected missing-plan rejection.');
        } catch (PlanNotAvailableForLicenseException $exception) {
            $this->assertSame(
                'The selected plan is not available for a new subscription.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('tenant_licenses', 0);
    }

    public function test_monthly_subscription_calculates_expiry_without_overflow(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'monthly-subscription',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 1,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-monthly',
        );

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt->addMonthNoOverflow(),
            ),
        );
    }

    public function test_yearly_subscription_calculates_expiry_without_overflow(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'yearly-subscription',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-yearly',
        );

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt->addYearNoOverflow(),
            ),
        );
    }

    public function test_lifetime_subscription_has_no_expiry(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'lifetime-subscription',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-lifetime',
        );

        $this->assertNull($result->expiresAt);

        $this->assertDatabaseHas('tenant_licenses', [
            'id' => $result->tenantLicenseId,
            'expires_at' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | C. Persisted license contract
    |--------------------------------------------------------------------------
    */

    public function test_it_stores_subscription_origin(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-origin'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-origin',
        );

        $license = TenantLicense::query()
            ->findOrFail($result->tenantLicenseId);

        $this->assertSame(
            TenantLicense::ORIGIN_SUBSCRIPTION,
            $license->license_origin,
        );
    }

    public function test_it_stores_active_status(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-status'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-status',
        );

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $result->status,
        );
    }

    public function test_it_uses_occurred_at_as_start_time(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-start-time'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-start-time',
        );

        $this->assertTrue(
            $result->startsAt->equalTo($this->occurredAt),
        );
    }

    public function test_grace_end_is_always_null(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-no-grace'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-no-grace',
        );

        $this->assertNull($result->graceEndsAt);
    }

    /*
    |--------------------------------------------------------------------------
    | D. Module synchronization
    |--------------------------------------------------------------------------
    */

    public function test_it_synchronizes_active_plan_modules(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-module-sync'
        );

        $module = $this->createModule('subscription_module');

        $this->attachModuleToPlan($plan, $module);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-module-sync',
        );

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'source' => TenantModule::SOURCE_PLAN,
            'status' => TenantModule::STATUS_ENABLED,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tenant_module.synced_from_plan',
            'entity_id' => $tenant->id,
            'request_id' => 'subscription-module-sync',
        ]);

        $this->assertSame($plan->id, $result->planId);
    }

    public function test_it_preserves_a_protected_existing_module_source(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-protected-module'
        );

        $module = $this->createModule('protected_subscription_module');

        $this->attachModuleToPlan($plan, $module);

        $tenantModule = TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => TenantModule::SOURCE_MANUAL,
            'enabled_by' => null,
            'enabled_at' => $this->occurredAt->subMonth(),
            'disabled_at' => null,
        ]);

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-protected-module',
        );

        $tenantModule->refresh();

        $this->assertSame(
            TenantModule::SOURCE_MANUAL,
            $tenantModule->source,
        );

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $this->assertSame(
            1,
            $audit->changes['skipped_protected'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | E. Audit and result
    |--------------------------------------------------------------------------
    */

    public function test_it_writes_subscription_started_audit(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-audit'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-audit',
        );

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'event' => 'tenant_license.subscription_started',
            'entity_type' => TenantLicense::class,
            'entity_id' => $result->tenantLicenseId,
            'request_id' => 'subscription-audit',
        ]);
    }

    public function test_subscription_and_module_audits_share_context(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-shared-context'
        );

        $requestId = 'subscription-shared-context';

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: $requestId,
        );

        $audits = AuditLog::query()
            ->where('request_id', $requestId)
            ->get();

        $this->assertCount(2, $audits);

        $this->assertEqualsCanonicalizing(
            [
                'tenant_module.synced_from_plan',
                'tenant_license.subscription_started',
            ],
            $audits->pluck('event')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertTrue(
                $audit->created_at->equalTo($this->occurredAt),
            );
        }
    }

    public function test_subscription_audit_contains_module_sync_counters(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-sync-counters'
        );

        $module = $this->createModule('sync_counter_module');

        $this->attachModuleToPlan($plan, $module);

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-sync-counters',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.subscription_started')
            ->sole();

        $this->assertSame(
            1,
            $audit->metadata['module_sync']['created'],
        );

        $this->assertSame(
            0,
            $audit->metadata['module_sync']['enabled'],
        );
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-result'
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-result',
        );

        $this->assertInstanceOf(
            StartTenantSubscriptionResult::class,
            $result,
        );

        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $result->status,
        );

        $this->assertTrue(
            $result->startsAt->equalTo($this->occurredAt),
        );
    }

    public function test_it_generates_a_request_id_when_none_is_supplied(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-generated-request'
        );

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
        );

        $audits = AuditLog::query()->get();

        $this->assertCount(2, $audits);
        $this->assertNotNull($audits->first()->request_id);

        $this->assertSame(
            $audits->first()->request_id,
            $audits->last()->request_id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | F. Atomicity and locking
    |--------------------------------------------------------------------------
    */

    public function test_it_rolls_back_license_modules_and_audits_on_sync_failure(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-rollback'
        );

        $module = $this->createModule('subscription_rollback_module');

        $this->attachModuleToPlan($plan, $module);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION nexusos_fail_subscription_sync_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event = 'tenant_module.synced_from_plan'
                   AND NEW.request_id = 'subscription-rollback'
                THEN
                    RAISE EXCEPTION
                        'Simulated subscription synchronization failure';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nexusos_fail_subscription_sync_audit_trigger
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            EXECUTE PROCEDURE nexusos_fail_subscription_sync_audit();
        SQL);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $plan->id,
                requestId: 'subscription-rollback',
            );

            $this->fail(
                'Expected simulated subscription synchronization failure.'
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Simulated subscription synchronization failure',
                $exception->getMessage(),
            );
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .'nexusos_fail_subscription_sync_audit_trigger '
                .'ON audit_logs'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS '
                .'nexusos_fail_subscription_sync_audit()'
            );
        }

        $this->assertDatabaseMissing('tenant_licenses', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => TenantLicense::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_tenant_before_current_license_check(): void
    {
        [$tenant, $plan] = $this->createStandardScenario(
            'subscription-lock-order'
        );

        /** @var list<string> $queries */
        $queries = [];

        DB::listen(
            static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower($query->sql);
            },
        );

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'subscription-lock-order',
        );

        $tenantLockIndex = null;
        $currentLicenseCheckIndex = null;

        foreach ($queries as $index => $sql) {
            if (
                $tenantLockIndex === null
                && str_contains($sql, 'from "tenants"')
                && str_contains($sql, 'for update')
            ) {
                $tenantLockIndex = $index;
            }

            if (
                $currentLicenseCheckIndex === null
                && str_contains($sql, 'from "tenant_licenses"')
                && str_contains($sql, 'exists')
            ) {
                $currentLicenseCheckIndex = $index;
            }
        }

        $this->assertNotNull($tenantLockIndex);
        $this->assertNotNull($currentLicenseCheckIndex);

        $this->assertLessThan(
            $currentLicenseCheckIndex,
            $tenantLockIndex,
            'Tenant must be locked before checking for a current license.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertCurrentLicenseRejected(
        string $status,
        string $requestId,
    ): void {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan($requestId.'-current');
        $targetPlan = $this->createPlan($requestId.'-target');

        $expiresAt = $this->occurredAt->addMonth();
        $graceEndsAt = null;
        $origin = TenantLicense::ORIGIN_SUBSCRIPTION;

        if ($status === TenantLicense::STATUS_TRIAL) {
            $origin = TenantLicense::ORIGIN_TRIAL;
            $expiresAt = $this->occurredAt->addDays(14);
        }

        if ($status === TenantLicense::STATUS_GRACE_PERIOD) {
            $expiresAt = $this->occurredAt->subDay();
            $graceEndsAt = $this->occurredAt->addDays(6);
        }

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $currentPlan->id,
            'license_origin' => $origin,
            'status' => $status,
            'starts_at' => $this->occurredAt->subMonth(),
            'expires_at' => $expiresAt,
            'grace_ends_at' => $graceEndsAt,
        ]);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $targetPlan->id,
                requestId: $requestId,
            );

            $this->fail(
                'Expected current-license rejection.'
            );
        } catch (CurrentTenantLicenseAlreadyExistsException $exception) {
            $this->assertSame(
                'Tenant already has a current license.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            1,
            TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->count(),
        );

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @return array{Tenant, Plan}
     */
    private function createStandardScenario(
        string $prefix,
    ): array {
        return [
            $this->createTenant(),
            $this->createPlan($prefix),
        ];
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Subscription Test Tenant',
            'slug' => 'subscription-test-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(
        string $code,
        string $unit = Plan::BILLING_PERIOD_MONTH,
        ?int $count = 1,
        bool $isActive = true,
    ): Plan {
        return Plan::query()->create([
            'name' => str_replace('-', ' ', ucfirst($code)),
            'code' => $code.'-'.str()->random(8),
            'billing_period_unit' => $unit,
            'billing_period_count' => $count,
            'description' => null,
            'price' => '100.00',
            'currency' => 'SAR',
            'max_users' => 10,
            'max_storage_mb' => 1024,
            'is_active' => $isActive,
        ]);
    }

    private function createHistoricalLicense(
        Tenant $tenant,
        Plan $plan,
        string $status,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => $status,
            'starts_at' => $this->occurredAt->subMonths(2),
            'expires_at' => $this->occurredAt->subMonth(),
            'grace_ends_at' => null,
        ]);
    }

    private function createModule(string $code): Module
    {
        return Module::query()->create([
            'name' => str_replace('_', ' ', ucfirst($code)),
            'code' => $code.'-'.str()->random(8),
            'category' => Module::CATEGORY_BUSINESS,
            'version' => '1.0.0',
            'description' => null,
            'is_active' => true,
            'deprecated_at' => null,
        ]);
    }

    private function attachModuleToPlan(
        Plan $plan,
        Module $module,
    ): PlanModule {
        return PlanModule::query()->create([
            'plan_id' => $plan->id,
            'module_id' => $module->id,
        ]);
    }
}
