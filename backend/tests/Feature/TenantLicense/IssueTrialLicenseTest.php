<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\IssueTrialLicenseResult;
use App\Exceptions\TenantLicense\CurrentTenantLicenseAlreadyExistsException;
use App\Exceptions\TenantLicense\PlanNotAvailableForLicenseException;
use App\Exceptions\TenantLicense\TrialAlreadyConsumedException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\IssueTrialLicense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IssueTrialLicenseTest extends TestCase
{
    use RefreshDatabase;

    private IssueTrialLicense $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(IssueTrialLicense::class);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-07-11 12:30:45',
                'UTC',
            ),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_creates_a_valid_operational_trial_license(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'trial-create-request',
        );

        $license = TenantLicense::query()->sole();

        $occurredAt = $this->occurredAt();
        $expectedExpiry = $occurredAt->addDays(14);

        $this->assertSame($result->tenantLicenseId, $license->id);
        $this->assertSame($tenant->id, $license->tenant_id);
        $this->assertSame($plan->id, $license->plan_id);
        $this->assertSame(
            TenantLicense::ORIGIN_TRIAL,
            $license->license_origin,
        );
        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $license->status,
        );

        $this->assertTrue(
            $license->starts_at->equalTo($occurredAt),
        );

        $this->assertNotNull($license->expires_at);

        $this->assertTrue(
            $license->expires_at->equalTo($expectedExpiry),
        );

        $this->assertNull($license->grace_ends_at);
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'trial-dto-request',
        );

        $this->assertInstanceOf(
            IssueTrialLicenseResult::class,
            $result,
        );

        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);
        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $result->status,
        );

        $this->assertTrue(
            $result->startsAt->equalTo($this->occurredAt()),
        );

        $this->assertTrue(
            $result->expiresAt->equalTo(
                $this->occurredAt()->addDays(14),
            ),
        );

        $this->assertNull($result->graceEndsAt);
    }

    public function test_it_synchronizes_active_plan_modules_immediately(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

        $activeModule = $this->createModule(
            code: 'active_trial_module',
        );

        $inactiveModule = $this->createModule(
            code: 'inactive_trial_module',
            active: false,
        );

        $deprecatedModule = $this->createModule(
            code: 'deprecated_trial_module',
            deprecatedAt: CarbonImmutable::parse(
                '2026-07-01 00:00:00',
                'UTC',
            ),
        );

        $this->attachModuleToPlan($plan, $activeModule);
        $this->attachModuleToPlan($plan, $inactiveModule);
        $this->attachModuleToPlan($plan, $deprecatedModule);

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'trial-module-sync-request',
        );

        $tenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $activeModule->id)
            ->sole();

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

        $this->assertNull($tenantModule->disabled_at);

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $inactiveModule->id,
        ]);

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $deprecatedModule->id,
        ]);
    }

    public function test_it_writes_both_required_audit_rows(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('audit_trial_module');

        $this->attachModuleToPlan($plan, $module);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'trial-audit-request',
        );

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('request_id', 'trial-audit-request')
                ->count(),
        );

        $syncAudit = AuditLog::query()
            ->where('request_id', 'trial-audit-request')
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $licenseAudit = AuditLog::query()
            ->where('request_id', 'trial-audit-request')
            ->where('event', 'tenant_license.trial_issued')
            ->sole();

        $this->assertSame($tenant->id, $syncAudit->tenant_id);
        $this->assertSame($tenant->id, $licenseAudit->tenant_id);

        $this->assertSame(
            TenantLicense::class,
            $licenseAudit->entity_type,
        );

        $this->assertSame(
            $result->tenantLicenseId,
            $licenseAudit->entity_id,
        );

        $this->assertSame(
            TenantLicense::ORIGIN_TRIAL,
            $licenseAudit->snapshot['license_origin'],
        );

        $this->assertSame(
            TenantLicense::STATUS_TRIAL,
            $licenseAudit->snapshot['status'],
        );

        $this->assertSame(
            1,
            $licenseAudit->metadata['module_sync']['created'],
        );
    }

    public function test_it_uses_one_request_id_for_the_entire_use_case(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('request_id_module');

        $this->attachModuleToPlan($plan, $module);

        $requestId = 'shared-trial-request-id';

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: $requestId,
        );

        $requestIds = AuditLog::query()
            ->orderBy('event')
            ->pluck('request_id')
            ->all();

        $this->assertCount(2, $requestIds);

        $this->assertSame(
            [$requestId, $requestId],
            $requestIds,
        );
    }

    public function test_it_uses_one_occurred_at_for_license_modules_and_audits(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('timestamp_module');

        $this->attachModuleToPlan($plan, $module);

        $this->service->handle(
            tenantId: $tenant->id,
            planId: $plan->id,
            requestId: 'shared-timestamp-request',
        );

        $occurredAt = $this->occurredAt();

        $license = TenantLicense::query()->sole();
        $tenantModule = TenantModule::query()->sole();

        $auditLogs = AuditLog::query()
            ->where('request_id', 'shared-timestamp-request')
            ->get();

        $this->assertTrue(
            $license->starts_at->equalTo($occurredAt),
        );

        $this->assertTrue(
            $tenantModule->enabled_at->equalTo($occurredAt),
        );

        $this->assertCount(2, $auditLogs);

        foreach ($auditLogs as $auditLog) {
            $this->assertTrue(
                $auditLog->created_at->equalTo($occurredAt),
            );
        }
    }

    public function test_it_rejects_a_tenant_that_already_has_a_current_license(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('current_plan');
        $targetPlan = $this->createPlan('target_trial_plan');

        $this->createLicense(
            tenant: $tenant,
            plan: $currentPlan,
            origin: TenantLicense::ORIGIN_SUBSCRIPTION,
            status: TenantLicense::STATUS_ACTIVE,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $targetPlan->id,
                requestId: 'current-license-rejection',
            );

            $this->fail(
                'Expected current-license exception was not thrown.',
            );
        } catch (CurrentTenantLicenseAlreadyExistsException $exception) {
            $this->assertSame(
                'Tenant already has a current license.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            1,
            TenantLicense::query()->count(),
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'current-license-rejection',
        ]);
    }

    public function test_it_rejects_a_historically_consumed_trial(): void
    {
        $tenant = $this->createTenant();
        $historicalPlan = $this->createPlan('historical_trial_plan');
        $targetPlan = $this->createPlan('new_trial_plan');

        $this->createLicense(
            tenant: $tenant,
            plan: $historicalPlan,
            origin: TenantLicense::ORIGIN_TRIAL,
            status: TenantLicense::STATUS_EXPIRED,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $targetPlan->id,
                requestId: 'consumed-trial-rejection',
            );

            $this->fail(
                'Expected consumed-trial exception was not thrown.',
            );
        } catch (TrialAlreadyConsumedException $exception) {
            $this->assertSame(
                'Tenant has already consumed its trial license.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            1,
            TenantLicense::query()->count(),
        );

        $this->assertDatabaseMissing('audit_logs', [
            'request_id' => 'consumed-trial-rejection',
        ]);
    }

    public function test_it_rejects_an_inactive_plan(): void
    {
        $tenant = $this->createTenant();

        $inactivePlan = $this->createPlan(
            code: 'inactive_trial_plan',
            active: false,
        );

        $this->expectException(
            PlanNotAvailableForLicenseException::class,
        );

        $this->expectExceptionMessage(
            'The selected plan is not available for a new trial license.',
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $inactivePlan->id,
                requestId: 'inactive-plan-rejection',
            );
        } finally {
            $this->assertDatabaseCount('tenant_licenses', 0);

            $this->assertDatabaseMissing('audit_logs', [
                'request_id' => 'inactive-plan-rejection',
            ]);
        }
    }

    public function test_it_rejects_a_missing_plan(): void
    {
        $tenant = $this->createTenant();
        $missingPlanId = (string) Str::ulid();

        $this->expectException(
            PlanNotAvailableForLicenseException::class,
        );

        $this->expectExceptionMessage(
            'The selected plan is not available for a new trial license.',
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $missingPlanId,
                requestId: 'missing-plan-rejection',
            );
        } finally {
            $this->assertDatabaseCount('tenant_licenses', 0);

            $this->assertDatabaseMissing('audit_logs', [
                'request_id' => 'missing-plan-rejection',
            ]);
        }
    }

    public function test_it_rolls_back_everything_when_module_sync_fails(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $module = $this->createModule('rollback_trial_module');

        $this->attachModuleToPlan($plan, $module);

        /*
         * This deliberately supplies a nonexistent actor ULID.
         *
         * TenantLicense creation succeeds first, then the real
         * SyncTenantModulesFromPlanOperation attempts to insert a
         * TenantModule whose enabled_by FK is invalid.
         *
         * This simulates an unexpected dependency failure inside the
         * same real transaction, rather than a Domain Exception.
         */
        $nonexistentActorUserId = (string) Str::ulid();

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                planId: $plan->id,
                actorUserId: $nonexistentActorUserId,
                requestId: 'trial-rollback-request',
            );

            $this->fail(
                'Expected PostgreSQL foreign-key failure was not thrown.',
            );
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('tenant_licenses', 0);
        $this->assertDatabaseCount('tenant_modules', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_the_tenant_row_with_for_update(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();

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
            requestId: 'tenant-lock-query-request',
        );

        $tenantLockQueries = array_values(
            array_filter(
                $queries,
                static fn (string $sql): bool =>
                    str_contains($sql, 'from "tenants"')
                    && str_contains($sql, 'for update'),
            ),
        );

        $this->assertNotEmpty(
            $tenantLockQueries,
            'IssueTrialLicense must lock the Tenant aggregate row FOR UPDATE.',
        );
    }

    private function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            '2026-07-11 12:30:45',
            'UTC',
        );
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Issue Trial Tenant',
            'slug' => 'issue-trial-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(
        string $code = 'trial_plan',
        bool $active = true,
    ): Plan {
        return Plan::query()->create([
            'name' => str_replace('_', ' ', ucfirst($code)),
            'code' => $code.'-'.str()->random(8),
            'billing_period_unit' => Plan::BILLING_PERIOD_MONTH,
            'billing_period_count' => 1,
            'description' => null,
            'price' => '100.00',
            'currency' => 'SAR',
            'max_users' => 10,
            'max_storage_mb' => 1024,
            'is_active' => $active,
        ]);
    }

    private function createModule(
        string $code,
        bool $active = true,
        ?CarbonImmutable $deprecatedAt = null,
    ): Module {
        return Module::query()->create([
            'name' => str_replace('_', ' ', ucfirst($code)),
            'code' => $code.'-'.str()->random(8),
            'category' => Module::CATEGORY_BUSINESS,
            'version' => '1.0.0',
            'description' => null,
            'is_active' => $active,
            'deprecated_at' => $deprecatedAt,
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

    private function createLicense(
        Tenant $tenant,
        Plan $plan,
        string $origin,
        string $status,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => $origin,
            'status' => $status,
            'starts_at' => CarbonImmutable::parse(
                '2026-06-01 00:00:00',
                'UTC',
            ),
            'expires_at' => CarbonImmutable::parse(
                '2026-06-15 00:00:00',
                'UTC',
            ),
            'grace_ends_at' => null,
        ]);
    }
}
