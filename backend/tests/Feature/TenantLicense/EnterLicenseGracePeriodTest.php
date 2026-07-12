<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\EnterLicenseGracePeriodResult;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\EnterLicenseGracePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnterLicenseGracePeriodTest extends TestCase
{
    use RefreshDatabase;

    private EnterLicenseGracePeriod $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-20 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(EnterLicenseGracePeriod::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_active_finite_license_enters_grace_at_expiry_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt,
        );

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo($this->occurredAt),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-at-expiry-boundary',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $license->status,
        );

        $this->assertTrue(
            $license->grace_ends_at->equalTo(
                $this->occurredAt->addDays(7),
            ),
        );

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $result->status,
        );
    }

    public function test_it_rejects_entry_before_expiry(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->addMinute(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'grace-before-expiry',
            );

            $this->fail(
                'Expected grace-period eligibility exception was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'grace_period_not_yet_eligible',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertNull($license->grace_ends_at);

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'grace-before-expiry',
        ]);
    }

    public function test_it_rejects_a_lifetime_license(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'grace_lifetime_plan',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'grace-lifetime-rejection',
            );

            $this->fail(
                'Expected lifetime grace-period rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'lifetime_cannot_enter_grace_period',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertNull($license->grace_ends_at);
    }

    public function test_it_rejects_an_unsupported_status(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
            graceEndsAt: null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'grace-invalid-status',
            );

            $this->fail(
                'Expected invalid grace transition was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_enter_grace_period_from_status',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );
    }

    public function test_grace_end_is_anchored_on_original_expiry(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $originalExpiry = CarbonImmutable::parse(
            '2026-07-10 12:00:00',
            'UTC',
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $originalExpiry,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-original-expiry-anchor',
        );

        $license->refresh();

        $this->assertTrue(
            $license->grace_ends_at->equalTo(
                CarbonImmutable::parse(
                    '2026-07-17 12:00:00',
                    'UTC',
                ),
            ),
        );

        $this->assertFalse(
            $license->grace_ends_at->equalTo(
                $this->occurredAt->addDays(7),
            ),
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.grace_period_entered')
            ->sole();

        $this->assertSame(
            $originalExpiry->toISOString(),
            $audit->metadata['grace_anchor'],
        );
    }

    public function test_delayed_catch_up_may_produce_grace_end_in_the_past(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(10),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-delayed-catch-up',
        );

        $license->refresh();

        $expectedGraceEnd = $this->occurredAt->subDays(3);

        $this->assertTrue(
            $license->grace_ends_at->equalTo($expectedGraceEnd),
        );

        $this->assertTrue(
            $license->grace_ends_at->lessThan($this->occurredAt),
        );
    }

    public function test_it_preserves_starts_at_expires_at_and_origin(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $startsAt = CarbonImmutable::parse(
            '2026-06-01 08:00:00',
            'UTC',
        );

        $expiresAt = CarbonImmutable::parse(
            '2026-07-01 08:00:00',
            'UTC',
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-preserve-history',
        );

        $license->refresh();

        $this->assertTrue(
            $license->starts_at->equalTo($startsAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo($expiresAt),
        );

        $this->assertSame(
            TenantLicense::ORIGIN_SUBSCRIPTION,
            $license->license_origin,
        );

        $this->assertSame($plan->id, $license->plan_id);
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDay(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-result-dto',
        );

        $this->assertInstanceOf(
            EnterLicenseGracePeriodResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $result->status,
        );

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt->subDay(),
            ),
        );

        $this->assertTrue(
            $result->graceEndsAt->equalTo(
                $this->occurredAt->addDays(6),
            ),
        );
    }

    public function test_it_keeps_consistent_plan_modules_operational(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('grace_unchanged_module');

        $this->attachModuleToPlan($plan, $module);

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt,
        );

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $this->occurredAt->subMonth(),
            'disabled_at' => null,
        ]);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-unchanged-module',
        );

        $tenantModule = TenantModule::query()->sole();

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $syncAudit = AuditLog::query()
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $this->assertSame(
            1,
            $syncAudit->changes['unchanged'],
        );

        $this->assertSame(
            $license->id,
            $syncAudit->metadata['license_id'],
        );
    }

    public function test_it_repairs_disabled_plan_module_drift(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('grace_drift_module');

        $this->attachModuleToPlan($plan, $module);

        $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt,
        );

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_DISABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $this->occurredAt->subMonth(),
            'disabled_at' => $this->occurredAt->subDay(),
        ]);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'grace-repair-drift',
        );

        $tenantModule = TenantModule::query()->sole();

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $this->assertTrue(
            $tenantModule->enabled_at->equalTo(
                $this->occurredAt,
            ),
        );

        $this->assertNull($tenantModule->disabled_at);

        $syncAudit = AuditLog::query()
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $this->assertSame(
            1,
            $syncAudit->changes['enabled'],
        );
    }

    public function test_it_writes_two_audits_with_one_request_id_and_timestamp(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('grace_audit_module');

        $this->attachModuleToPlan($plan, $module);

        $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt,
        );

        $requestId = 'grace-shared-request';

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: $requestId,
        );

        $audits = AuditLog::query()
            ->where('request_id', $requestId)
            ->get();

        $this->assertCount(2, $audits);

        $this->assertEqualsCanonicalizing(
            [
                'tenant_license.grace_period_entered',
                'tenant_module.synced_from_plan',
            ],
            $audits->pluck('event')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertSame(
                $requestId,
                $audit->request_id,
            );

            $this->assertTrue(
                $audit->created_at->equalTo(
                    $this->occurredAt,
                ),
            );
        }
    }

    public function test_it_rolls_back_everything_when_module_sync_fails(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('grace_rollback_module');

        $this->attachModuleToPlan($plan, $module);

        $expiresAt = $this->occurredAt;

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $expiresAt,
        );

        /*
         * Simulates an unexpected PostgreSQL failure inside the real
         * synchronization operation after the license mutation.
         */
        $nonexistentActorUserId = (string) Str::ulid();

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                actorUserId: $nonexistentActorUserId,
                requestId: 'grace-rollback',
            );

            $this->fail(
                'Expected PostgreSQL foreign-key failure was not thrown.',
            );
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertTrue(
            $license->expires_at->equalTo($expiresAt),
        );

        $this->assertNull($license->grace_ends_at);

        $this->assertDatabaseCount('tenant_modules', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_tenant_then_tenant_license_for_update(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt,
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
            requestId: 'grace-lock-order',
        );

        $tenantLockIndex = null;
        $licenseLockIndex = null;

        foreach ($queries as $index => $sql) {
            if (
                $tenantLockIndex === null
                && str_contains($sql, 'from "tenants"')
                && str_contains($sql, 'for update')
            ) {
                $tenantLockIndex = $index;
            }

            if (
                $licenseLockIndex === null
                && str_contains($sql, 'from "tenant_licenses"')
                && str_contains($sql, 'for update')
            ) {
                $licenseLockIndex = $index;
            }
        }

        $this->assertNotNull(
            $tenantLockIndex,
            'Tenant aggregate row must be locked FOR UPDATE.',
        );

        $this->assertNotNull(
            $licenseLockIndex,
            'TenantLicense row must be locked FOR UPDATE.',
        );

        $this->assertLessThan(
            $licenseLockIndex,
            $tenantLockIndex,
            'Tenant must be locked before TenantLicense.',
        );
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Grace Test Tenant',
            'slug' => 'grace-test-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(
        string $code = 'grace_plan',
        string $unit = Plan::BILLING_PERIOD_MONTH,
        ?int $count = 1,
    ): Plan {
        return Plan::query()->create([
            'name' => str_replace('_', ' ', ucfirst($code)),
            'code' => $code.'-'.str()->random(8),
            'billing_period_unit' => $unit,
            'billing_period_count' => $count,
            'description' => null,
            'price' => '100.00',
            'currency' => 'SAR',
            'max_users' => 10,
            'max_storage_mb' => 1024,
            'is_active' => true,
        ]);
    }

    private function createActiveLicense(
        Tenant $tenant,
        Plan $plan,
        ?CarbonImmutable $expiresAt,
        ?CarbonImmutable $startsAt = null,
    ): TenantLicense {
        return $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            startsAt: $startsAt
                ?? $this->occurredAt->subMonth(),
            expiresAt: $expiresAt,
            graceEndsAt: null,
        );
    }

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
        string $status,
        ?CarbonImmutable $expiresAt,
        ?CarbonImmutable $graceEndsAt,
        ?CarbonImmutable $startsAt = null,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => $status,
            'starts_at' => $startsAt
                ?? $this->occurredAt->subMonth(),
            'expires_at' => $expiresAt,
            'grace_ends_at' => $graceEndsAt,
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
