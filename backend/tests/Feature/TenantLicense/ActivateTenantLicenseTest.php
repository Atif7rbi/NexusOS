<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\ActivateTenantLicenseResult;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Exceptions\TenantLicense\TenantLicensePastDueException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\ActivateTenantLicense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ActivateTenantLicenseTest extends TestCase
{
    use RefreshDatabase;

    private ActivateTenantLicense $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ActivateTenantLicense::class);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-07-11 15:00:00',
                'UTC',
            ),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_activates_a_valid_trial_license(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createTrialLicense($tenant, $plan);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-valid-trial',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertTrue(
            $license->starts_at->equalTo($this->occurredAt()),
        );

        $this->assertNotNull($license->expires_at);

        $this->assertTrue(
            $license->expires_at->equalTo(
                $this->occurredAt()->addMonthNoOverflow(),
            ),
        );

        $this->assertNull($license->grace_ends_at);

        $this->assertSame(
            $license->id,
            $result->tenantLicenseId,
        );
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createTrialLicense($tenant, $plan);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-result-dto',
        );

        $this->assertInstanceOf(
            ActivateTenantLicenseResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $result->status,
        );

        $this->assertTrue(
            $result->startsAt->equalTo($this->occurredAt()),
        );

        $this->assertNotNull($result->expiresAt);

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt()->addMonthNoOverflow(),
            ),
        );

        $this->assertNull($result->graceEndsAt);
    }

    public function test_it_rejects_a_non_trial_license(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            startsAt: $this->occurredAt()->subMonth(),
            expiresAt: $this->occurredAt()->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'activate-invalid-status',
            );

            $this->fail(
                'Expected invalid transition exception was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_activate_from_status',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'activate-invalid-status',
        ]);
    }

    public function test_it_rejects_a_trial_at_the_expiry_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createTrialLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'activate-expired-trial',
            );

            $this->fail(
                'Expected past-due exception was not thrown.',
            );
        } catch (TenantLicensePastDueException $exception) {
            $this->assertSame(
                'activation_past_due',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'activate-expired-trial',
        ]);
    }

    public function test_it_activates_a_monthly_plan_without_overflow(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-01-31 12:00:00',
                'UTC',
            ),
        );

        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'monthly_plan',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 1,
        );

        $license = $this->createTrialLicense($tenant, $plan);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-monthly-plan',
        );

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo(
                CarbonImmutable::parse(
                    '2026-02-28 12:00:00',
                    'UTC',
                ),
            ),
        );
    }

    public function test_it_activates_a_yearly_plan_without_overflow(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2028-02-29 12:00:00',
                'UTC',
            ),
        );

        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'yearly_plan',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $license = $this->createTrialLicense($tenant, $plan);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-yearly-plan',
        );

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo(
                CarbonImmutable::parse(
                    '2029-02-28 12:00:00',
                    'UTC',
                ),
            ),
        );
    }

    public function test_it_activates_a_lifetime_plan_with_null_expiry(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'lifetime_plan',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->createTrialLicense($tenant, $plan);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-lifetime-plan',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertNull($license->expires_at);
        $this->assertNull($license->grace_ends_at);
        $this->assertNull($result->expiresAt);
    }

    public function test_it_preserves_the_trial_license_origin(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createTrialLicense($tenant, $plan);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-preserve-origin',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::ORIGIN_TRIAL,
            $license->license_origin,
        );
    }

    public function test_it_allows_activation_of_an_inactive_grandfathered_plan(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'grandfathered_plan',
            active: false,
        );

        $license = $this->createTrialLicense($tenant, $plan);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-grandfathered-plan',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.activated')
            ->sole();

        $this->assertFalse(
            $audit->metadata['plan_was_active'],
        );
    }

    public function test_it_synchronizes_plan_modules_after_activation(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createTrialLicense($tenant, $plan);
        $module = $this->createModule('activation_module');

        $this->attachModuleToPlan($plan, $module);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-module-sync',
        );

        $tenantModule = TenantModule::query()->sole();

        $this->assertSame($tenant->id, $tenantModule->tenant_id);
        $this->assertSame($module->id, $tenantModule->module_id);

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $this->assertSame(
            TenantModule::SOURCE_PLAN,
            $tenantModule->source,
        );

        $this->assertTrue(
            $tenantModule->enabled_at->equalTo(
                $this->occurredAt(),
            ),
        );

        $this->assertSame(
            $license->id,
            AuditLog::query()
                ->where('event', 'tenant_module.synced_from_plan')
                ->sole()
                ->metadata['license_id'],
        );
    }

    public function test_it_writes_both_audits_with_one_request_id_and_timestamp(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createTrialLicense($tenant, $plan);
        $module = $this->createModule('activation_audit_module');

        $this->attachModuleToPlan($plan, $module);

        $requestId = 'activate-shared-request';

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
                'tenant_license.activated',
                'tenant_module.synced_from_plan',
            ],
            $audits->pluck('event')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertSame($requestId, $audit->request_id);

            $this->assertTrue(
                $audit->created_at->equalTo(
                    $this->occurredAt(),
                ),
            );
        }
    }

    public function test_it_rolls_back_everything_when_module_sync_fails(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createTrialLicense($tenant, $plan);
        $module = $this->createModule('activation_rollback_module');

        $this->attachModuleToPlan($plan, $module);

        /*
         * Simulates an unexpected PostgreSQL failure inside the real
         * module synchronization dependency, after the license mutation.
         */
        $nonexistentActorUserId = (string) Str::ulid();

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                actorUserId: $nonexistentActorUserId,
                requestId: 'activate-rollback-request',
            );

            $this->fail(
                'Expected PostgreSQL foreign-key failure was not thrown.',
            );
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );

        $this->assertTrue(
            $license->starts_at->equalTo(
                CarbonImmutable::parse(
                    '2026-07-01 00:00:00',
                    'UTC',
                ),
            ),
        );

        $this->assertDatabaseCount('tenant_modules', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_tenant_then_tenant_license_for_update(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createTrialLicense($tenant, $plan);

        /** @var list<string> $queries */
        $queries = [];

        DB::listen(
            static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower($query->sql);
            },
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'activate-lock-order',
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

    private function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Activation Test Tenant',
            'slug' => 'activation-test-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(
        string $code = 'activation_plan',
        string $unit = Plan::BILLING_PERIOD_MONTH,
        ?int $count = 1,
        bool $active = true,
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
            'is_active' => $active,
        ]);
    }

    private function createTrialLicense(
        Tenant $tenant,
        Plan $plan,
        ?CarbonImmutable $expiresAt = null,
    ): TenantLicense {
        return $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_TRIAL,
            startsAt: CarbonImmutable::parse(
                '2026-07-01 00:00:00',
                'UTC',
            ),
            expiresAt: $expiresAt
                ?? $this->occurredAt()->addDays(7),
        );
    }

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
        string $status,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $expiresAt,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_TRIAL,
            'status' => $status,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
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
