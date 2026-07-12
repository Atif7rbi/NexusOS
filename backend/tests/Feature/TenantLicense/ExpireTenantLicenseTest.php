<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\ExpireTenantLicenseResult;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\ExpireTenantLicense;
use App\Services\TenantModule\Operations\RevokePlanModulesFromTenantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExpireTenantLicenseTest extends TestCase
{
    use RefreshDatabase;

    private ExpireTenantLicense $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-22 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(ExpireTenantLicense::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_trial_expires_at_the_expiry_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-trial-boundary',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_EXPIRED,
            $license->status,
        );

        $this->assertTrue($result->changed);

        $this->assertDatabaseHas('audit_logs', [
            'request_id' => 'expire-trial-boundary',
            'event' => 'tenant_license.expired',
        ]);
    }

    public function test_trial_expiration_is_rejected_before_expiry(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt->addMinute(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                requestId: 'expire-trial-too-early',
            );

            $this->fail(
                'Expected premature expiration rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'expiration_not_yet_eligible',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'expire-trial-too-early',
        ]);
    }

    public function test_grace_period_expires_at_the_grace_end_boundary(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            expiresAt: $this->occurredAt->subDays(7),
            graceEndsAt: $this->occurredAt,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-grace-boundary',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_EXPIRED,
            $license->status,
        );

        $this->assertTrue($result->changed);
    }

    public function test_grace_expiration_is_rejected_before_grace_end(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            expiresAt: $this->occurredAt->subDays(6),
            graceEndsAt: $this->occurredAt->addMinute(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                requestId: 'expire-grace-too-early',
            );

            $this->fail(
                'Expected premature grace expiration rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'grace_expiration_not_yet_eligible',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_GRACE_PERIOD,
            $license->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'expire-grace-too-early',
        ]);
    }

    public function test_already_expired_license_is_an_idempotent_no_op(): void
    {
        $tenant = $this->createTenant(
            status: Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: $this->occurredAt->subDays(10),
            graceEndsAt: $this->occurredAt->subDays(3),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-idempotent',
        );

        $this->assertFalse($result->changed);

        $this->assertSame(
            TenantLicense::STATUS_EXPIRED,
            $result->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'expire-idempotent',
        ]);
    }

    public function test_active_license_cannot_expire_directly(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: $this->occurredAt,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                requestId: 'expire-active-rejection',
            );

            $this->fail(
                'Expected active-license rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'active_must_enter_grace_before_expiration',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_ACTIVE,
            $license->status,
        );
    }

    public function test_cancelled_license_cannot_be_expired(): void
    {
        $tenant = $this->createTenant(
            status: Tenant::STATUS_CANCELLED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_CANCELLED,
            expiresAt: $this->occurredAt->subDay(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                requestId: 'expire-cancelled-rejection',
            );

            $this->fail(
                'Expected cancelled-license rejection was not thrown.',
            );
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_expire_from_status',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame(
            TenantLicense::STATUS_CANCELLED,
            $license->status,
        );
    }

    public function test_it_revokes_plan_modules_and_preserves_protected_sources(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $planModule = $this->createModule('expire_plan_module');
        $manualModule = $this->createModule('expire_manual_module');
        $overrideModule = $this->createModule('expire_override_module');

        $planRow = $this->createTenantModule(
            tenant: $tenant,
            module: $planModule,
            source: TenantModule::SOURCE_PLAN,
        );

        $manualRow = $this->createTenantModule(
            tenant: $tenant,
            module: $manualModule,
            source: TenantModule::SOURCE_MANUAL,
        );

        $overrideRow = $this->createTenantModule(
            tenant: $tenant,
            module: $overrideModule,
            source: TenantModule::SOURCE_OVERRIDE,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-revoke-plan-only',
        );

        $planRow->refresh();
        $manualRow->refresh();
        $overrideRow->refresh();

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
            $overrideRow->status,
        );
    }

    public function test_module_revocation_audit_uses_license_expiration_trigger(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-trigger-check',
        );

        $audit = AuditLog::query()
            ->where(
                'event',
                'tenant_module.plan_entitlement_revoked',
            )
            ->sole();

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
    }

    public function test_active_tenant_is_suspended_when_license_expires(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-suspend-tenant',
        );

        $tenant->refresh();

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $tenant->status,
        );

        $this->assertDatabaseHas('audit_logs', [
            'request_id' => 'expire-suspend-tenant',
            'event' => 'tenant.suspended_due_to_license_expiration',
            'entity_type' => Tenant::class,
            'entity_id' => $tenant->id,
        ]);
    }

    public function test_suspension_audit_is_written_only_for_actual_tenant_change(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-actual-suspension',
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'event',
                    'tenant.suspended_due_to_license_expiration',
                )
                ->count(),
        );

        $audit = AuditLog::query()
            ->where(
                'event',
                'tenant.suspended_due_to_license_expiration',
            )
            ->sole();

        $this->assertSame(
            Tenant::STATUS_ACTIVE,
            $audit->changes['before']['status'],
        );

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $audit->changes['after']['status'],
        );
    }

    public function test_already_suspended_tenant_is_not_rewritten_or_reaudited(): void
    {
        $tenant = $this->createTenant(
            status: Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            expiresAt: $this->occurredAt->subDays(7),
            graceEndsAt: $this->occurredAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-already-suspended',
        );

        $tenant->refresh();

        $this->assertSame(
            Tenant::STATUS_SUSPENDED,
            $tenant->status,
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'expire-already-suspended',
            'event' => 'tenant.suspended_due_to_license_expiration',
        ]);

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('request_id', 'expire-already-suspended')
                ->count(),
        );
    }

    public function test_it_preserves_license_history_fields(): void
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

        $graceEndsAt = CarbonImmutable::parse(
            '2026-07-08 08:00:00',
            'UTC',
        );

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_GRACE_PERIOD,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            graceEndsAt: $graceEndsAt,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-preserve-history',
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
            TenantLicense::ORIGIN_SUBSCRIPTION,
            $license->license_origin,
        );

        $this->assertSame($plan->id, $license->plan_id);
    }

    public function test_it_returns_the_expected_changed_result_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-changed-dto',
        );

        $this->assertInstanceOf(
            ExpireTenantLicenseResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(
            TenantLicense::STATUS_EXPIRED,
            $result->status,
        );

        $this->assertTrue($result->changed);
    }

    public function test_idempotent_result_returns_current_snapshot_with_changed_false(): void
    {
        $tenant = $this->createTenant(
            status: Tenant::STATUS_SUSPENDED,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: $this->occurredAt->subDays(14),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-idempotent-dto',
        );

        $this->assertInstanceOf(
            ExpireTenantLicenseResult::class,
            $result,
        );

        $this->assertSame($license->id, $result->tenantLicenseId);

        $this->assertSame(
            TenantLicense::STATUS_EXPIRED,
            $result->status,
        );

        $this->assertFalse($result->changed);

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt->subDays(14),
            ),
        );
    }

    public function test_real_expiration_shares_one_request_id_and_timestamp_across_audits(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $requestId = 'expire-shared-context';

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: $requestId,
        );

        $audits = AuditLog::query()
            ->where('request_id', $requestId)
            ->get();

        $this->assertCount(3, $audits);

        $this->assertEqualsCanonicalizing(
            [
                'tenant.suspended_due_to_license_expiration',
                'tenant_module.plan_entitlement_revoked',
                'tenant_license.expired',
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
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $module = $this->createModule('expire_rollback_module');

        $tenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
        );

        /*
         * Force a real PostgreSQL failure precisely when the revocation
         * operation writes its AuditLog.
         *
         * At this point the service has already mutated:
         * - TenantLicense: trial -> expired
         * - Tenant: active -> suspended
         * - TenantModule: enabled -> disabled
         *
         * The raised database exception must roll all of them back.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION nexusos_fail_expiration_revoke_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event = 'tenant_module.plan_entitlement_revoked'
                   AND NEW.request_id = 'expire-rollback'
                THEN
                    RAISE EXCEPTION
                        'Simulated expiration revocation audit failure';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nexusos_fail_expiration_revoke_audit_trigger
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            EXECUTE PROCEDURE nexusos_fail_expiration_revoke_audit();
        SQL);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                requestId: 'expire-rollback',
            );

            $this->fail(
                'Expected simulated PostgreSQL audit failure was not thrown.',
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Simulated expiration revocation audit failure',
                $exception->getMessage(),
            );
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .'nexusos_fail_expiration_revoke_audit_trigger '
                .'ON audit_logs'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS '
                .'nexusos_fail_expiration_revoke_audit()'
            );
        }

        $license->refresh();
        $tenant->refresh();
        $tenantModule->refresh();

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
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
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: $this->occurredAt,
        );

        $module = $this->createModule('expire_lock_module');

        $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
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
            requestId: 'expire-lock-order',
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
            'Tenant must be locked before TenantLicense.',
        );

        $this->assertLessThan(
            $moduleLockIndex,
            $licenseLockIndex,
            'TenantLicense must be locked before TenantModule rows.',
        );
    }

    public function test_idempotent_path_does_not_repair_active_tenant_or_touch_modules(): void
    {
        /*
         * Deliberately inconsistent historical state:
         * the license is expired but the Tenant is still active.
         *
         * A retry must not become an implicit reconciliation service.
         */
        $tenant = $this->createTenant(
            status: Tenant::STATUS_ACTIVE,
        );

        $plan = $this->createPlan();

        $license = $this->createLicense(
            tenant: $tenant,
            plan: $plan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: $this->occurredAt->subDays(10),
            graceEndsAt: $this->occurredAt->subDays(3),
        );

        $module = $this->createModule('idempotent_untouched_module');

        $tenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
        );

        $originalEnabledAt = $tenantModule->enabled_at;

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            requestId: 'expire-no-reconciliation',
        );

        $tenant->refresh();
        $tenantModule->refresh();

        $this->assertFalse($result->changed);

        $this->assertSame(
            Tenant::STATUS_ACTIVE,
            $tenant->status,
        );

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $this->assertTrue(
            $tenantModule->enabled_at->equalTo($originalEnabledAt),
        );

        $this->assertNull($tenantModule->disabled_at);

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'expire-no-reconciliation',
        ]);
    }

    private function createTenant(
        string $status = Tenant::STATUS_ACTIVE,
    ): Tenant {
        return Tenant::query()->create([
            'name' => 'Expiration Test Tenant',
            'slug' => 'expiration-test-tenant-'.str()->random(8),
            'status' => $status,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Expiration Test Plan',
            'code' => 'expiration-test-plan-'.str()->random(8),
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
        string $origin,
        string $status,
        CarbonImmutable $expiresAt,
        ?CarbonImmutable $graceEndsAt = null,
        ?CarbonImmutable $startsAt = null,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => $origin,
            'status' => $status,
            'starts_at' => $startsAt
                ?? $expiresAt->subMonth(),
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
