<?php

declare(strict_types=1);

namespace Tests\Feature\TenantModule;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantModule\Operations\RevokePlanModulesFromTenantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RevokePlanModulesFromTenantOperationTest extends TestCase
{
    use RefreshDatabase;

    private RevokePlanModulesFromTenantOperation $operation;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-21 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->operation = app(
            RevokePlanModulesFromTenantOperation::class
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_revokes_enabled_plan_modules_only(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        $firstModule = $this->createModule('revoke_first');
        $secondModule = $this->createModule('revoke_second');

        $firstTenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $firstModule,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_ENABLED,
        );

        $secondTenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $secondModule,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_ENABLED,
        );

        $result = DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'revoke-enabled-plan-modules',
            )
        );

        $firstTenantModule->refresh();
        $secondTenantModule->refresh();

        $this->assertSame(2, $result->revoked);
        $this->assertSame(0, $result->alreadyDisabled);

        $this->assertEqualsCanonicalizing(
            [$firstModule->id, $secondModule->id],
            $result->revokedModuleIds,
        );

        foreach ([$firstTenantModule, $secondTenantModule] as $tenantModule) {
            $this->assertSame(
                TenantModule::STATUS_DISABLED,
                $tenantModule->status,
            );

            $this->assertTrue(
                $tenantModule->disabled_at->equalTo(
                    $this->occurredAt,
                ),
            );
        }
    }

    public function test_it_preserves_all_protected_sources(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        $sources = [
            TenantModule::SOURCE_MANUAL,
            TenantModule::SOURCE_TRIAL,
            TenantModule::SOURCE_PROMO,
            TenantModule::SOURCE_OVERRIDE,
        ];

        $protectedRows = [];

        foreach ($sources as $index => $source) {
            $module = $this->createModule(
                'protected_source_'.$index
            );

            $protectedRows[] = $this->createTenantModule(
                tenant: $tenant,
                module: $module,
                source: $source,
                status: TenantModule::STATUS_ENABLED,
            );
        }

        $result = DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'preserve-protected-sources',
            )
        );

        $this->assertSame(0, $result->revoked);
        $this->assertSame(0, $result->alreadyDisabled);

        foreach ($protectedRows as $tenantModule) {
            $tenantModule->refresh();

            $this->assertSame(
                TenantModule::STATUS_ENABLED,
                $tenantModule->status,
            );

            $this->assertNull($tenantModule->disabled_at);
        }
    }

    public function test_it_reports_already_disabled_plan_modules_without_mutation(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);
        $module = $this->createModule('already_disabled_plan_module');

        $originalDisabledAt = $this->occurredAt->subDays(3);

        $tenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_DISABLED,
            disabledAt: $originalDisabledAt,
        );

        $result = DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'already-disabled-plan-module',
            )
        );

        $tenantModule->refresh();

        $this->assertSame(0, $result->revoked);
        $this->assertSame(1, $result->alreadyDisabled);

        $this->assertSame(
            [$module->id],
            $result->alreadyDisabledModuleIds,
        );

        $this->assertTrue(
            $tenantModule->disabled_at->equalTo(
                $originalDisabledAt,
            ),
        );
    }

    public function test_it_writes_audit_even_when_nothing_requires_revocation(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        $result = DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'revoke-no-op-audit',
            )
        );

        $this->assertSame(0, $result->revoked);
        $this->assertSame(0, $result->alreadyDisabled);

        $audit = AuditLog::query()
            ->where('request_id', 'revoke-no-op-audit')
            ->sole();

        $this->assertSame(
            'tenant_module.plan_entitlement_revoked',
            $audit->event,
        );

        $this->assertSame(0, $audit->changes['revoked']);

        $this->assertSame(
            0,
            $audit->changes['already_disabled'],
        );

        $this->assertSame(
            RevokePlanModulesFromTenantOperation::
                TRIGGER_LICENSE_EXPIRATION,
            $audit->metadata['trigger'],
        );

        $this->assertSame(
            $license->id,
            $audit->metadata['license_id'],
        );

        $this->assertSame(
            $plan->id,
            $audit->metadata['plan_id'],
        );

        $this->assertArrayNotHasKey(
            'revoke_source',
            $audit->metadata,
        );
    }

    public function test_it_uses_the_supplied_timestamp_and_request_id(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);
        $module = $this->createModule('revoke_timestamp');

        $tenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_ENABLED,
        );

        DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'revoke-supplied-context',
            )
        );

        $tenantModule->refresh();

        $audit = AuditLog::query()
            ->where('request_id', 'revoke-supplied-context')
            ->sole();

        $this->assertTrue(
            $tenantModule->disabled_at->equalTo(
                $this->occurredAt,
            ),
        );

        $this->assertSame(
            'revoke-supplied-context',
            $audit->request_id,
        );

        $this->assertTrue(
            $audit->created_at->equalTo(
                $this->occurredAt,
            ),
        );
    }

    public function test_it_accepts_the_license_cancellation_trigger(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_CANCELLATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'revoke-cancellation-trigger',
            )
        );

        $audit = AuditLog::query()
            ->where('request_id', 'revoke-cancellation-trigger')
            ->sole();

        $this->assertSame(
            'tenant_module.plan_entitlement_revoked',
            $audit->event,
        );

        $this->assertSame(
            RevokePlanModulesFromTenantOperation::
                TRIGGER_LICENSE_CANCELLATION,
            $audit->metadata['trigger'],
        );
    }

    public function test_it_rejects_an_unsupported_trigger(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        try {
            DB::transaction(
                fn () => $this->operation->execute(
                    tenantId: $tenant->id,
                    licenseId: $license->id,
                    planId: $plan->id,
                    trigger: 'license_expired',
                    occurredAt: $this->occurredAt,
                    actorUserId: null,
                    requestId: 'revoke-invalid-trigger',
                )
            );

            $this->fail(
                'Expected unsupported-trigger exception was not thrown.',
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported plan entitlement revocation trigger.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'revoke-invalid-trigger',
        ]);
    }

    public function test_it_does_not_open_or_own_a_transaction(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);

        $transactionLevelBefore = DB::connection()
            ->transactionLevel();

        $this->operation->execute(
            tenantId: $tenant->id,
            licenseId: $license->id,
            planId: $plan->id,
            trigger: RevokePlanModulesFromTenantOperation::
                TRIGGER_LICENSE_EXPIRATION,
            occurredAt: $this->occurredAt,
            actorUserId: null,
            requestId: 'revoke-transaction-ownership',
        );

        $transactionLevelAfter = DB::connection()
            ->transactionLevel();

        $this->assertSame(
            $transactionLevelBefore,
            $transactionLevelAfter,
        );
    }

    public function test_it_locks_plan_rows_in_deterministic_id_order(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createLicense($tenant, $plan);
        $module = $this->createModule('revoke_lock_order');

        $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_ENABLED,
        );

        /** @var list<string> $queries */
        $queries = [];

        DB::listen(
            static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower($query->sql);
            },
        );

        DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                trigger: RevokePlanModulesFromTenantOperation::
                    TRIGGER_LICENSE_EXPIRATION,
                occurredAt: $this->occurredAt,
                actorUserId: null,
                requestId: 'revoke-lock-order',
            )
        );

        $matchingQueries = array_values(
            array_filter(
                $queries,
                static fn (string $sql): bool =>
                    str_contains($sql, 'from "tenant_modules"')
                    && str_contains($sql, 'order by "id" asc')
                    && str_contains($sql, 'for update'),
            ),
        );

        $this->assertNotEmpty(
            $matchingQueries,
            'Plan-derived TenantModule rows must be locked by ID order.',
        );
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Revoke Operation Tenant',
            'slug' => 'revoke-operation-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Revoke Operation Plan',
            'code' => 'revoke-operation-plan-'.str()->random(8),
            'billing_period_unit' => Plan::BILLING_PERIOD_MONTH,
            'billing_period_count' => 1,
            'description' => null,
            'price' => '100.00',
            'currency' => 'SAR',
            'max_users' => 10,
            'max_storage_mb' => 1024,
            'is_active' => true,
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

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => TenantLicense::STATUS_GRACE_PERIOD,
            'starts_at' => $this->occurredAt->subMonth(),
            'expires_at' => $this->occurredAt->subDays(7),
            'grace_ends_at' => $this->occurredAt,
        ]);
    }

    private function createTenantModule(
        Tenant $tenant,
        Module $module,
        string $source,
        string $status,
        ?CarbonImmutable $disabledAt = null,
    ): TenantModule {
        return TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => $status,
            'source' => $source,
            'enabled_by' => null,
            'enabled_at' => $this->occurredAt->subMonth(),
            'disabled_at' => $disabledAt,
        ]);
    }
}
