<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\CancelTenantLicenseResult;
use App\Exceptions\TenantLicense\InvalidCancellationReasonException;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\CancelTenantLicense;
use App\Services\TenantModule\Operations\RevokePlanModulesFromTenantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CancelTenantLicenseTest extends TestCase
{
    use RefreshDatabase;

    private CancelTenantLicense $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-23 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(CancelTenantLicense::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_rejects_an_empty_reason(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: '',
                requestId: 'cancel-empty-reason',
            );

            $this->fail(
                'Expected empty cancellation reason rejection.'
            );
        } catch (InvalidCancellationReasonException $exception) {
            $this->assertSame(
                'empty_cancellation_reason',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_rejects_a_whitespace_only_reason(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: " \n\t ",
                requestId: 'cancel-whitespace-reason',
            );

            $this->fail(
                'Expected whitespace cancellation reason rejection.'
            );
        } catch (InvalidCancellationReasonException $exception) {
            $this->assertSame(
                'empty_cancellation_reason',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );
    }

    public function test_it_rejects_a_normalized_reason_longer_than_1000_characters(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: '  '.str_repeat('a', 1001).'  ',
                requestId: 'cancel-long-reason',
            );

            $this->fail(
                'Expected cancellation reason length rejection.'
            );
        } catch (InvalidCancellationReasonException $exception) {
            $this->assertSame(
                'cancellation_reason_too_long',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_accepts_exactly_1000_characters(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $reason = str_repeat('a', 1000);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: $reason,
            requestId: 'cancel-max-reason',
        );

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $result->status,
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.cancelled')
            ->sole();

        $this->assertSame(
            1000,
            mb_strlen($audit->metadata['reason']),
        );

        $this->assertSame(
            $reason,
            $audit->metadata['reason'],
        );
    }

    public function test_it_trims_and_stores_the_normalized_reason(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt->addDays(10),
            origin: TenantLicense::ORIGIN_TRIAL,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: '   Customer requested immediate cancellation.   ',
            requestId: 'cancel-normalized-reason',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.cancelled')
            ->sole();

        $this->assertSame(
            'Customer requested immediate cancellation.',
            $audit->metadata['reason'],
        );
    }

    public function test_trial_cancellation_succeeds_immediately(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt->addDays(12),
            origin: TenantLicense::ORIGIN_TRIAL,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Trial cancelled by owner.',
            requestId: 'cancel-trial',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $license->status,
        );

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $result->status,
        );
    }

    public function test_active_cancellation_succeeds_immediately_regardless_of_remaining_period(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $futureExpiry = $this->occurredAt->addMonths(6);

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $futureExpiry,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Commercial cancellation.',
            requestId: 'cancel-active-immediately',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $license->status,
        );

        $this->assertTrue(
            $license->expires_at->equalTo($futureExpiry),
        );
    }

    public function test_grace_period_cancellation_succeeds_immediately(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $expiresAt = $this->occurredAt->subDays(3);
        $graceEndsAt = $this->occurredAt->addDays(4);

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            expiresAt: $expiresAt,
            graceEndsAt: $graceEndsAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Cancellation during grace period.',
            requestId: 'cancel-grace',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $license->status,
        );

        $this->assertTrue(
            $license->grace_ends_at->equalTo($graceEndsAt),
        );
    }

    public function test_expired_license_cancellation_is_rejected(): void
    {
        $tenant = $this->createTenant(
            Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: $this->occurredAt->subDays(10),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: 'Invalid terminal transition.',
                requestId: 'cancel-expired',
            );

            $this->fail(
                'Expected expired-license cancellation rejection.'
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_cancel_from_status',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_already_cancelled_license_is_rejected(): void
    {
        $tenant = $this->createTenant(
            Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_CANCELLED,
            expiresAt: $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: 'Repeated cancellation.',
                requestId: 'cancel-cancelled',
            );

            $this->fail(
                'Expected repeated cancellation rejection.'
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_cancel_from_status',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_preserves_license_history_fields_and_origin(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $startsAt = CarbonImmutable::parse(
            '2026-05-01 08:00:00',
            'UTC',
        );

        $expiresAt = CarbonImmutable::parse(
            '2026-07-01 08:00:00',
            'UTC',
        );

        $graceEndsAt = CarbonImmutable::parse(
            '2026-07-25 08:00:00',
            'UTC',
        );

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            graceEndsAt: $graceEndsAt,
            origin: TenantLicense::ORIGIN_TRIAL,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Preserve historical fields.',
            requestId: 'cancel-preserve-history',
        );

        $license->refresh();

        $this->assertTrue(
            $license->starts_at->equalTo($startsAt),
        );

        $this->assertTrue(
            $license->expires_at->equalTo($expiresAt),
        );

        $this->assertTrue(
            $license->grace_ends_at->equalTo($graceEndsAt),
        );

        $this->assertSame(
            TenantLicense::ORIGIN_TRIAL,
            $license->license_origin,
        );

        $this->assertSame($plan->id, $license->plan_id);
    }

    public function test_it_revokes_plan_modules_and_preserves_protected_sources(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $planModule = $this->createModule('cancel_plan_module');
        $manualModule = $this->createModule('cancel_manual_module');
        $promoModule = $this->createModule('cancel_promo_module');

        $planRow = $this->createTenantModule(
            $tenant,
            $planModule,
            TenantModule::SOURCE_PLAN,
        );

        $manualRow = $this->createTenantModule(
            $tenant,
            $manualModule,
            TenantModule::SOURCE_MANUAL,
        );

        $promoRow = $this->createTenantModule(
            $tenant,
            $promoModule,
            TenantModule::SOURCE_PROMO,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Revoke plan modules.',
            requestId: 'cancel-revoke-modules',
        );

        $planRow->refresh();
        $manualRow->refresh();
        $promoRow->refresh();

        $this->assertSame(
            TenantModule::STATUS_DISABLED,
            $planRow->status,
        );

        $this->assertTrue(
            $planRow->disabled_at->equalTo($this->occurredAt),
        );

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $manualRow->status,
        );

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $promoRow->status,
        );
    }

    public function test_module_audit_uses_cancellation_trigger_and_contains_no_reason(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Private cancellation reason.',
            requestId: 'cancel-trigger-check',
        );

        $audit = AuditLog::query()
            ->where(
                'event',
                'tenant_module.plan_entitlement_revoked',
            )
            ->sole();

        $this->assertSame(
            RevokePlanModulesFromTenantOperation::
                TRIGGER_LICENSE_CANCELLATION,
            $audit->metadata['trigger'],
        );

        $this->assertArrayNotHasKey(
            'reason',
            $audit->metadata,
        );

        $this->assertSame(
            $license->id,
            $audit->metadata['license_id'],
        );
    }

    public function test_active_tenant_is_suspended_due_to_cancellation(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Suspend tenant after cancellation.',
            requestId: 'cancel-suspend-tenant',
        );

        $tenant->refresh();

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $tenant->status,
        );

        $this->assertDatabaseHas('audit_logs', [
            'request_id' => 'cancel-suspend-tenant',
            'event' => 'tenant.suspended_due_to_license_cancellation',
            'entity_type' => Tenant::class,
            'entity_id' => $tenant->id,
        ]);
    }

    public function test_already_suspended_tenant_is_not_rewritten_or_reaudited(): void
    {
        $tenant = $this->createTenant(
            Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Cancel without synthetic tenant audit.',
            requestId: 'cancel-already-suspended',
        );

        $tenant->refresh();

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $tenant->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'cancel-already-suspended',
            'event' => 'tenant.suspended_due_to_license_cancellation',
        ]);

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('request_id', 'cancel-already-suspended')
                ->count(),
        );
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'DTO verification.',
            requestId: 'cancel-result-dto',
        );

        $this->assertInstanceOf(
            CancelTenantLicenseResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $result->status,
        );
    }

    public function test_it_shares_request_id_and_timestamp_across_all_audits(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $requestId = 'cancel-shared-context';

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            reason: 'Shared audit context.',
            requestId: $requestId,
        );

        $audits = AuditLog::query()
            ->where('request_id', $requestId)
            ->get();

        $this->assertCount(3, $audits);

        $this->assertEqualsCanonicalizing(
            [
                'tenant.suspended_due_to_license_cancellation',
                'tenant_module.plan_entitlement_revoked',
                'tenant_license.cancelled',
            ],
            $audits->pluck('event')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertSame(
                $requestId,
                $audit->request_id,
            );

            $this->assertTrue(
                $audit->created_at->equalTo($this->occurredAt),
            );
        }
    }

    public function test_it_rolls_back_license_tenant_modules_and_audits_on_failure(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $module = $this->createModule('cancel_rollback_module');

        $tenantModule = $this->createTenantModule(
            $tenant,
            $module,
            TenantModule::SOURCE_PLAN,
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION nexusos_fail_cancellation_revoke_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event = 'tenant_module.plan_entitlement_revoked'
                   AND NEW.request_id = 'cancel-rollback'
                THEN
                    RAISE EXCEPTION
                        'Simulated cancellation revocation audit failure';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nexusos_fail_cancellation_revoke_audit_trigger
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            EXECUTE PROCEDURE nexusos_fail_cancellation_revoke_audit();
        SQL);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                reason: 'Rollback cancellation.',
                requestId: 'cancel-rollback',
            );

            $this->fail(
                'Expected simulated PostgreSQL audit failure.'
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Simulated cancellation revocation audit failure',
                $exception->getMessage(),
            );
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .'nexusos_fail_cancellation_revoke_audit_trigger '
                .'ON audit_logs'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS '
                .'nexusos_fail_cancellation_revoke_audit()'
            );
        }

        $license->refresh();
        $tenant->refresh();
        $tenantModule->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );

        $this->assertSame(
            Tenant::STATUS_ACTIVE,
            $tenant->status,
        );

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $this->assertNull($tenantModule->disabled_at);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_tenant_then_license_then_modules(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt->addMonth(),
        );

        $module = $this->createModule('cancel_lock_module');

        $this->createTenantModule(
            $tenant,
            $module,
            TenantModule::SOURCE_PLAN,
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
            tenantLicenseId: $license->id,
            reason: 'Lock-order verification.',
            requestId: 'cancel-lock-order',
        );

        $tenantLockIndex = null;
        $licenseLockIndex = null;
        $moduleLockIndex = null;

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

            if (
                $moduleLockIndex === null
                && str_contains($sql, 'from "tenant_modules"')
                && str_contains($sql, 'order by "id" asc')
                && str_contains($sql, 'for update')
            ) {
                $moduleLockIndex = $index;
            }
        }

        $this->assertNotNull($tenantLockIndex);
        $this->assertNotNull($licenseLockIndex);
        $this->assertNotNull($moduleLockIndex);

        $this->assertLessThan(
            $licenseLockIndex,
            $tenantLockIndex,
        );

        $this->assertLessThan(
            $moduleLockIndex,
            $licenseLockIndex,
        );
    }

    private function createTenant(
        string $status = Tenant::STATUS_ACTIVE,
    ): Tenant {
        return Tenant::query()->create([
            'name' => 'Cancellation Test Tenant',
            'slug' => 'cancellation-test-tenant-'.str()->random(8),
            'status' => $status,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Cancellation Test Plan',
            'code' => 'cancellation-test-plan-'.str()->random(8),
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

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
        string $status,
        ?CarbonImmutable $expiresAt,
        ?CarbonImmutable $graceEndsAt = null,
        ?CarbonImmutable $startsAt = null,
        string $origin = TenantLicense::ORIGIN_SUBSCRIPTION,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => $origin,
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

    private function createTenantModule(
        Tenant $tenant,
        Module $module,
        string $source,
    ): TenantModule {
        return TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => $source,
            'enabled_by' => null,
            'enabled_at' => $this->occurredAt->subMonth(),
            'disabled_at' => null,
        ]);
    }
}
