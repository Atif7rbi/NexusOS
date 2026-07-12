<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\RenewTenantLicenseResult;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Exceptions\TenantLicense\TenantLicensePastDueException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\RenewTenantLicense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RenewTenantLicenseTest extends TestCase
{
    use RefreshDatabase;

    private RenewTenantLicense $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-11 15:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(RenewTenantLicense::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_early_renewal_succeeds_one_minute_before_expiry(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $oldStartsAt = $this->occurredAt->subMonth();

        $oldExpiresAt = $this->occurredAt->addMinute();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $oldStartsAt,
            expiresAt: $oldExpiresAt,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'early-before-expiry',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertTrue(
            $license->starts_at->equalTo($oldStartsAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo(
                $oldExpiresAt->addMonthNoOverflow(),
            ),
        );

        $this->assertNull($license->grace_ends_at);

        $this->assertSame(
            RenewTenantLicense::TYPE_EARLY,
            $result->renewalType,
        );
    }

    public function test_early_renewal_rejects_exactly_at_expiry_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt,
        );

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo($this->occurredAt),
            'PostgreSQL/Eloquent must preserve the exact second-level expiry boundary.',
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'early-at-expiry',
            );

            $this->fail(
                'Expected renewal-past-due exception was not thrown.',
            );
        } catch (TenantLicensePastDueException $exception) {
            $this->assertSame(
                'renewal_past_due',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo($this->occurredAt),
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'early-at-expiry',
        ]);
    }

    public function test_early_renewal_rejects_one_minute_after_expiry(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->subMinute(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'early-after-expiry',
            );

            $this->fail(
                'Expected renewal-past-due exception was not thrown.',
            );
        } catch (TenantLicensePastDueException $exception) {
            $this->assertSame(
                'renewal_past_due',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'early-after-expiry',
        ]);
    }

    public function test_early_renewal_is_anchored_on_the_old_expiry(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'three_month_plan',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 3,
        );

        $oldExpiresAt = CarbonImmutable::parse(
            '2026-08-31 10:00:00',
            'UTC',
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: CarbonImmutable::parse(
                '2026-05-31 10:00:00',
                'UTC',
            ),
            expiresAt: $oldExpiresAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'early-anchor',
        );

        $license->refresh();

        $this->assertTrue(
            $license->expires_at->equalTo(
                CarbonImmutable::parse(
                    '2026-11-30 10:00:00',
                    'UTC',
                ),
            ),
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.renewed')
            ->sole();

        $this->assertSame(
            $oldExpiresAt->toISOString(),
            $audit->metadata['renewal_anchor'],
        );
    }

    public function test_early_renewal_preserves_starts_at(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $originalStartsAt = CarbonImmutable::parse(
            '2026-06-01 08:00:00',
            'UTC',
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $originalStartsAt,
            expiresAt: $this->occurredAt->addDays(10),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'early-preserve-start',
        );

        $license->refresh();

        $this->assertTrue(
            $license->starts_at->equalTo($originalStartsAt),
        );
    }

    public function test_grace_recovery_succeeds_one_minute_before_grace_end(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(6),
            graceEndsAt: $this->occurredAt->addMinute(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'recovery-before-end',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertTrue(
            $license->starts_at->equalTo($this->occurredAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo(
                $this->occurredAt->addMonthNoOverflow(),
            ),
        );

        $this->assertNull($license->grace_ends_at);

        $this->assertSame(
            RenewTenantLicense::TYPE_RECOVERY,
            $result->renewalType,
        );
    }

    public function test_grace_recovery_rejects_exactly_at_grace_end_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(7),
            graceEndsAt: $this->occurredAt,
        );

        $license->refresh();

        $this->assertTrue(
            $license->grace_ends_at->equalTo($this->occurredAt),
            'PostgreSQL/Eloquent must preserve the exact second-level grace boundary.',
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'recovery-at-end',
            );

            $this->fail(
                'Expected grace-recovery-past-due exception was not thrown.',
            );
        } catch (TenantLicensePastDueException $exception) {
            $this->assertSame(
                'grace_recovery_past_due',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $license->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'recovery-at-end',
        ]);
    }

    public function test_grace_recovery_rejects_one_minute_after_grace_end(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(8),
            graceEndsAt: $this->occurredAt->subMinute(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'recovery-after-end',
            );

            $this->fail(
                'Expected grace-recovery-past-due exception was not thrown.',
            );
        } catch (TenantLicensePastDueException $exception) {
            $this->assertSame(
                'grace_recovery_past_due',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $license->status,
        );
    }

    public function test_grace_recovery_uses_grace_end_not_old_expiry_for_eligibility(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subMonths(3),
            graceEndsAt: $this->occurredAt->addMinute(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'recovery-correct-field',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertSame(
            RenewTenantLicense::TYPE_RECOVERY,
            $result->renewalType,
        );
    }

    public function test_grace_recovery_is_anchored_on_occurred_at(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'yearly_recovery_plan',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(4),
            graceEndsAt: $this->occurredAt->addDays(3),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'recovery-anchor',
        );

        $license->refresh();

        $this->assertTrue(
            $license->starts_at->equalTo($this->occurredAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo(
                $this->occurredAt->addYearNoOverflow(),
            ),
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.renewed')
            ->sole();

        $this->assertSame(
            $this->occurredAt->toISOString(),
            $audit->metadata['renewal_anchor'],
        );
    }

    public function test_grace_recovery_clears_grace_end(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createGraceLicense(
            tenant: $tenant,
            plan: $plan,
            expiresAt: $this->occurredAt->subDays(2),
            graceEndsAt: $this->occurredAt->addDays(5),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'recovery-clear-grace',
        );

        $license->refresh();

        $this->assertNull($license->grace_ends_at);
        $this->assertNull($result->graceEndsAt);
    }

    public function test_it_rejects_a_lifetime_license(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'lifetime_renewal_plan',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subYear(),
            expiresAt: null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'reject-lifetime-renewal',
            );

            $this->fail(
                'Expected lifetime-renewal rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'lifetime_cannot_be_renewed',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertNull($license->expires_at);

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'reject-lifetime-renewal',
        ]);
    }

    public function test_it_rejects_an_unsupported_status(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_TRIAL,
            startsAt: $this->occurredAt->subDay(),
            expiresAt: $this->occurredAt->addDays(10),
            graceEndsAt: null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                requestId: 'reject-trial-renewal',
            );

            $this->fail(
                'Expected invalid renewal transition was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_renew_from_status',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );
    }

    public function test_it_returns_the_expected_early_renewal_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->addDays(10),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'early-dto',
        );

        $this->assertInstanceOf(
            RenewTenantLicenseResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $result->status,
        );

        $this->assertSame(
            RenewTenantLicense::TYPE_EARLY,
            $result->renewalType,
        );
    }

    public function test_it_synchronizes_plan_modules(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->addDays(5),
        );

        $module = $this->createModule('renewal_module');

        $this->attachModuleToPlan($plan, $module);

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'renewal-module-sync',
        );

        $tenantModule = TenantModule::query()->sole();

        $this->assertSame($tenant->id, $tenantModule->tenant_id);
        $this->assertSame($module->id, $tenantModule->module_id);

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $syncAudit = AuditLog::query()
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $this->assertSame(
            $license->id,
            $syncAudit->metadata['license_id'],
        );
    }

    public function test_it_writes_audits_with_one_request_id_and_timestamp(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->addDays(6),
        );

        $module = $this->createModule('renewal_audit_module');

        $this->attachModuleToPlan($plan, $module);

        $requestId = 'shared-renewal-request';

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
                'tenant_license.renewed',
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

        $renewalAudit = $audits
            ->firstWhere('event', 'tenant_license.renewed');

        $this->assertSame(
            RenewTenantLicense::TYPE_EARLY,
            $renewalAudit->metadata['renewal_type'],
        );
    }

    public function test_it_rolls_back_everything_when_module_sync_fails(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $originalStartsAt = $this->occurredAt->subMonth();
        $originalExpiresAt = $this->occurredAt->addDays(3);

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $plan,
            startsAt: $originalStartsAt,
            expiresAt: $originalExpiresAt,
        );

        $module = $this->createModule('renewal_rollback_module');

        $this->attachModuleToPlan($plan, $module);

        /*
         * Simulates an unexpected PostgreSQL failure inside the real
         * module synchronization operation after license mutation.
         */
        $nonexistentActorUserId = (string) Str::ulid();

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                actorUserId: $nonexistentActorUserId,
                requestId: 'renewal-rollback',
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
            $license->starts_at->equalTo($originalStartsAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo($originalExpiresAt),
        );

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
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->addDays(4),
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
            requestId: 'renewal-lock-order',
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
            'name' => 'Renewal Test Tenant',
            'slug' => 'renewal-test-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(
        string $code = 'renewal_plan',
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
        CarbonImmutable $startsAt,
        ?CarbonImmutable $expiresAt,
    ): TenantLicense {
        return $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            graceEndsAt: null,
        );
    }

    private function createGraceLicense(
        Tenant $tenant,
        Plan $plan,
        CarbonImmutable $expiresAt,
        CarbonImmutable $graceEndsAt,
    ): TenantLicense {
        return $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            startsAt: $expiresAt->subMonth(),
            expiresAt: $expiresAt,
            graceEndsAt: $graceEndsAt,
        );
    }

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
        string $status,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $expiresAt,
        ?CarbonImmutable $graceEndsAt,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => $status,
            'starts_at' => $startsAt,
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
