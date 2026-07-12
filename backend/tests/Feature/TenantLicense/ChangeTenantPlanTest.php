<?php

declare(strict_types=1);

namespace Tests\Feature\TenantLicense;

use App\Data\TenantLicense\ChangeTenantPlanResult;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantLicense\ChangeTenantPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ChangeTenantPlanTest extends TestCase
{
    use RefreshDatabase;

    private ChangeTenantPlan $service;

    private CarbonImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->occurredAt = CarbonImmutable::parse(
            '2026-07-24 12:00:00',
            'UTC',
        );

        CarbonImmutable::setTestNow($this->occurredAt);

        $this->service = app(ChangeTenantPlan::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | A. Status eligibility
    |--------------------------------------------------------------------------
    */

    public function test_active_license_may_change_plan(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('active-current');
        $newPlan = $this->createPlan('active-target');

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-active-plan',
        );

        $license->refresh();

        $this->assertSame($newPlan->id, $license->plan_id);
        $this->assertSame($newPlan->id, $result->newPlanId);
    }

    public function test_trial_license_cannot_change_plan(): void
    {
        $this->assertStatusRejected(
            status: TenantLicense::STATUS_TRIAL,
            requestId: 'change-trial-rejected',
        );
    }

    public function test_grace_period_license_cannot_change_plan(): void
    {
        $this->assertStatusRejected(
            status: TenantLicense::STATUS_GRACE_PERIOD,
            requestId: 'change-grace-rejected',
        );
    }

    public function test_expired_license_cannot_change_plan(): void
    {
        $this->assertStatusRejected(
            status: TenantLicense::STATUS_EXPIRED,
            requestId: 'change-expired-rejected',
        );
    }

    public function test_cancelled_license_cannot_change_plan(): void
    {
        $this->assertStatusRejected(
            status: TenantLicense::STATUS_CANCELLED,
            requestId: 'change-cancelled-rejected',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B. Plan identity and activity
    |--------------------------------------------------------------------------
    */

    public function test_same_plan_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan('same-plan');

        $license = $this->createActiveLicense(
            $tenant,
            $plan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $plan->id,
                requestId: 'same-plan-rejected',
            );

            $this->fail('Expected same-plan rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'plan_already_assigned',
                $exception->reasonCode,
            );
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_same_plan_is_rejected_even_when_current_plan_is_inactive(): void
    {
        $tenant = $this->createTenant();

        $plan = $this->createPlan(
            code: 'same-inactive-plan',
            isActive: false,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $plan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $plan->id,
                requestId: 'same-inactive-plan-rejected',
            );

            $this->fail('Expected same-plan ordering rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'plan_already_assigned',
                $exception->reasonCode,
            );
        }
    }

    public function test_different_inactive_target_plan_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('inactive-target-current');

        $newPlan = $this->createPlan(
            code: 'inactive-target',
            isActive: false,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $newPlan->id,
                requestId: 'inactive-target-rejected',
            );

            $this->fail('Expected inactive target rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'target_plan_inactive',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame($currentPlan->id, $license->plan_id);
    }

    /*
    |--------------------------------------------------------------------------
    | C. Lifetime transitions
    |--------------------------------------------------------------------------
    */

    public function test_finite_to_lifetime_succeeds(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('finite-current');

        $newPlan = $this->createPlan(
            code: 'lifetime-target',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'finite-to-lifetime',
        );

        $license->refresh();

        $this->assertTrue($result->periodRestarted);
        $this->assertTrue($license->starts_at->equalTo($this->occurredAt));
        $this->assertNull($license->expires_at);
    }

    public function test_lifetime_to_different_lifetime_succeeds(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'lifetime-current',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $newPlan = $this->createPlan(
            code: 'lifetime-new',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $startsAt = $this->occurredAt->subYear();

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $startsAt,
            null,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'lifetime-to-lifetime',
        );

        $license->refresh();

        $this->assertFalse($result->periodRestarted);
        $this->assertTrue($license->starts_at->equalTo($startsAt));
        $this->assertNull($license->expires_at);
    }

    public function test_lifetime_to_finite_is_rejected(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'lifetime-current-rejected',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $newPlan = $this->createPlan('finite-target-rejected');

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subYear(),
            null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $newPlan->id,
                requestId: 'lifetime-to-finite-rejected',
            );

            $this->fail('Expected lifetime-to-finite rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'lifetime_to_finite_plan_change_not_allowed',
                $exception->reasonCode,
            );
        }
    }

    public function test_lifetime_to_inactive_finite_is_rejected_by_structural_rule_first(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'lifetime-structural-current',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $newPlan = $this->createPlan(
            code: 'inactive-finite-target',
            isActive: false,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subYear(),
            null,
        );

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $newPlan->id,
                requestId: 'lifetime-structural-first',
            );

            $this->fail('Expected structural lifetime rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'lifetime_to_finite_plan_change_not_allowed',
                $exception->reasonCode,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | D. Period matching and restart
    |--------------------------------------------------------------------------
    */

    public function test_same_month_and_count_preserves_the_full_period(): void
    {
        $this->assertSamePeriodIsPreserved(
            currentUnit: Plan::BILLING_PERIOD_MONTH,
            currentCount: 1,
            newUnit: Plan::BILLING_PERIOD_MONTH,
            newCount: 1,
            requestId: 'same-month-period',
        );
    }

    public function test_same_year_and_count_preserves_the_full_period(): void
    {
        $this->assertSamePeriodIsPreserved(
            currentUnit: Plan::BILLING_PERIOD_YEAR,
            currentCount: 1,
            newUnit: Plan::BILLING_PERIOD_YEAR,
            newCount: 1,
            requestId: 'same-year-period',
        );
    }

    public function test_different_count_in_same_unit_restarts_the_period(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'one-month-current',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 1,
        );

        $newPlan = $this->createPlan(
            code: 'three-month-target',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 3,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'different-count-restart',
        );

        $license->refresh();

        $this->assertTrue($result->periodRestarted);
        $this->assertTrue($license->starts_at->equalTo($this->occurredAt));

        $this->assertTrue(
            $license->expires_at->equalTo(
                $this->occurredAt->addMonthsNoOverflow(3),
            ),
        );
    }

    public function test_different_unit_restarts_the_period(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'monthly-current',
            unit: Plan::BILLING_PERIOD_MONTH,
            count: 1,
        );

        $newPlan = $this->createPlan(
            code: 'yearly-target',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'different-unit-restart',
        );

        $license->refresh();

        $this->assertTrue($result->periodRestarted);

        $this->assertTrue(
            $license->expires_at->equalTo(
                $this->occurredAt->addYearNoOverflow(),
            ),
        );
    }

    public function test_finite_to_lifetime_restarts_start_and_clears_expiry(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('finite-to-lifetime-current');

        $newPlan = $this->createPlan(
            code: 'finite-to-lifetime-target',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonths(2),
            $this->occurredAt->addMonths(5),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'finite-lifetime-period-reset',
        );

        $license->refresh();

        $this->assertTrue($license->starts_at->equalTo($this->occurredAt));
        $this->assertNull($license->expires_at);
        $this->assertNull($license->grace_ends_at);
    }

    public function test_lifetime_to_lifetime_preserves_start_and_null_expiry(): void
    {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: 'lifetime-period-current',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $newPlan = $this->createPlan(
            code: 'lifetime-period-new',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $startsAt = $this->occurredAt->subYears(2);

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $startsAt,
            null,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'lifetime-period-preserved',
        );

        $license->refresh();

        $this->assertTrue($license->starts_at->equalTo($startsAt));
        $this->assertNull($license->expires_at);
    }

    /*
    |--------------------------------------------------------------------------
    | E. Module reconciliation
    |--------------------------------------------------------------------------
    */

    public function test_it_creates_modules_introduced_by_the_new_plan(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('module-create-current');
        $newPlan = $this->createPlan('module-create-new');
        $module = $this->createModule('new_plan_module');

        $this->attachModuleToPlan($newPlan, $module);

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-create-module',
        );

        $this->assertDatabaseHas('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'source' => TenantModule::SOURCE_PLAN,
            'status' => TenantModule::STATUS_ENABLED,
        ]);
    }

    public function test_it_disables_old_plan_modules_removed_from_the_new_plan(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('module-disable-current');
        $newPlan = $this->createPlan('module-disable-new');
        $module = $this->createModule('old_plan_module');

        $this->attachModuleToPlan($currentPlan, $module);

        $tenantModule = $this->createTenantModule(
            $tenant,
            $module,
            TenantModule::SOURCE_PLAN,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-disable-old-module',
        );

        $tenantModule->refresh();

        $this->assertSame(
            TenantModule::STATUS_DISABLED,
            $tenantModule->status,
        );

        $this->assertTrue(
            $tenantModule->disabled_at->equalTo($this->occurredAt),
        );
    }

    public function test_it_preserves_protected_module_sources(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('protected-current');
        $newPlan = $this->createPlan('protected-new');

        $sources = [
            TenantModule::SOURCE_MANUAL,
            TenantModule::SOURCE_TRIAL,
            TenantModule::SOURCE_PROMO,
            TenantModule::SOURCE_OVERRIDE,
        ];

        $rows = [];

        foreach ($sources as $index => $source) {
            $module = $this->createModule('protected_'.$index);

            $rows[] = $this->createTenantModule(
                $tenant,
                $module,
                $source,
            );
        }

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-preserve-protected',
        );

        foreach ($rows as $row) {
            $row->refresh();

            $this->assertSame(
                TenantModule::STATUS_ENABLED,
                $row->status,
            );
        }
    }

    public function test_it_restores_disabled_plan_module_included_in_new_plan(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('restore-current');
        $newPlan = $this->createPlan('restore-new');
        $module = $this->createModule('restore_module');

        $this->attachModuleToPlan($newPlan, $module);

        $tenantModule = $this->createTenantModule(
            tenant: $tenant,
            module: $module,
            source: TenantModule::SOURCE_PLAN,
            status: TenantModule::STATUS_DISABLED,
            disabledAt: $this->occurredAt->subDay(),
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-restore-module',
        );

        $tenantModule->refresh();

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $tenantModule->status,
        );

        $this->assertNull($tenantModule->disabled_at);
    }

    public function test_it_writes_sync_audit_when_both_plans_have_identical_modules(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('identical-module-current');
        $newPlan = $this->createPlan('identical-module-new');
        $module = $this->createModule('identical_module');

        $this->attachModuleToPlan($currentPlan, $module);
        $this->attachModuleToPlan($newPlan, $module);

        $this->createTenantModule(
            $tenant,
            $module,
            TenantModule::SOURCE_PLAN,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-identical-modules',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_module.synced_from_plan')
            ->sole();

        $this->assertSame(1, $audit->changes['unchanged']);
        $this->assertSame($newPlan->id, $audit->metadata['plan_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | F. Audit and DTO
    |--------------------------------------------------------------------------
    */

    public function test_it_writes_plan_changed_audit(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('audit-event');

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'plan-changed-audit',
        );

        $this->assertDatabaseHas('audit_logs', [
            'request_id' => 'plan-changed-audit',
            'event' => 'tenant_license.plan_changed',
            'entity_type' => TenantLicense::class,
            'entity_id' => $license->id,
        ]);
    }

    public function test_audit_contains_previous_and_new_plan_ids(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('audit-plan-ids');

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'audit-plan-ids',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.plan_changed')
            ->sole();

        $this->assertSame(
            $currentPlan->id,
            $audit->metadata['previous_plan_id'],
        );

        $this->assertSame(
            $newPlan->id,
            $audit->metadata['new_plan_id'],
        );
    }

    public function test_audit_records_period_restarted_false_for_identical_duration(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('audit-not-restarted');

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'audit-not-restarted',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.plan_changed')
            ->sole();

        $this->assertFalse($audit->metadata['period_restarted']);
    }

    public function test_audit_records_period_restarted_true_for_different_duration(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('audit-restart-current');

        $newPlan = $this->createPlan(
            code: 'audit-restart-new',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'audit-restarted',
        );

        $audit = AuditLog::query()
            ->where('event', 'tenant_license.plan_changed')
            ->sole();

        $this->assertTrue($audit->metadata['period_restarted']);
    }

    public function test_license_and_module_audits_share_request_id_and_timestamp(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('shared-context');

        $requestId = 'change-shared-context';

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: $requestId,
        );

        $audits = AuditLog::query()
            ->where('request_id', $requestId)
            ->get();

        $this->assertCount(2, $audits);

        $this->assertEqualsCanonicalizing(
            [
                'tenant_module.synced_from_plan',
                'tenant_license.plan_changed',
            ],
            $audits->pluck('event')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertTrue(
                $audit->created_at->equalTo($this->occurredAt),
            );
        }
    }

    public function test_it_returns_the_expected_result_dto(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('result-dto');

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-result-dto',
        );

        $this->assertInstanceOf(ChangeTenantPlanResult::class, $result);
        $this->assertSame($license->id, $result->tenantLicenseId);
        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($currentPlan->id, $result->previousPlanId);
        $this->assertSame($newPlan->id, $result->newPlanId);
        $this->assertSame(TenantLicense::STATUS_ACTIVE, $result->status);
        $this->assertFalse($result->periodRestarted);
    }

    public function test_it_preserves_license_origin(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('origin-current');
        $newPlan = $this->createPlan('origin-new');

        $license = $this->createActiveLicense(
            tenant: $tenant,
            plan: $currentPlan,
            startsAt: $this->occurredAt->subMonth(),
            expiresAt: $this->occurredAt->addMonth(),
            origin: TenantLicense::ORIGIN_TRIAL,
        );

        $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: 'change-preserve-origin',
        );

        $license->refresh();

        $this->assertSame(
            TenantLicense::ORIGIN_TRIAL,
            $license->license_origin,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | G. Atomicity and locking
    |--------------------------------------------------------------------------
    */

    public function test_it_rolls_back_plan_period_modules_and_audits_on_sync_failure(): void
    {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan('rollback-current');

        $newPlan = $this->createPlan(
            code: 'rollback-new',
            unit: Plan::BILLING_PERIOD_YEAR,
            count: 1,
        );

        $module = $this->createModule('rollback_module');

        $this->attachModuleToPlan($newPlan, $module);

        $startsAt = $this->occurredAt->subMonth();
        $expiresAt = $this->occurredAt->addMonth();

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $startsAt,
            $expiresAt,
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION nexusos_fail_plan_change_sync_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event = 'tenant_module.synced_from_plan'
                   AND NEW.request_id = 'change-plan-rollback'
                THEN
                    RAISE EXCEPTION
                        'Simulated plan change synchronization failure';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nexusos_fail_plan_change_sync_audit_trigger
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            EXECUTE PROCEDURE nexusos_fail_plan_change_sync_audit();
        SQL);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $newPlan->id,
                requestId: 'change-plan-rollback',
            );

            $this->fail('Expected simulated synchronization failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Simulated plan change synchronization failure',
                $exception->getMessage(),
            );
        } finally {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS '
                .'nexusos_fail_plan_change_sync_audit_trigger '
                .'ON audit_logs'
            );

            DB::unprepared(
                'DROP FUNCTION IF EXISTS '
                .'nexusos_fail_plan_change_sync_audit()'
            );
        }

        $license->refresh();

        $this->assertSame($currentPlan->id, $license->plan_id);
        $this->assertTrue($license->starts_at->equalTo($startsAt));
        $this->assertTrue($license->expires_at->equalTo($expiresAt));

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_it_locks_tenant_then_license_then_modules(): void
    {
        [$tenant, $currentPlan, $newPlan, $license] =
            $this->createStandardChangeScenario('lock-order');

        $module = $this->createModule('change_lock_module');

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
            newPlanId: $newPlan->id,
            requestId: 'change-lock-order',
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

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertStatusRejected(
        string $status,
        string $requestId,
    ): void {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan($requestId.'-current');
        $newPlan = $this->createPlan($requestId.'-new');

        $expiresAt = $this->occurredAt->addMonth();
        $graceEndsAt = null;

        if ($status === TenantLicense::STATUS_GRACE_PERIOD) {
            $expiresAt = $this->occurredAt->subDay();
            $graceEndsAt = $this->occurredAt->addDays(6);
        }

        if ($status === TenantLicense::STATUS_EXPIRED) {
            $expiresAt = $this->occurredAt->subDays(10);
        }

        $license = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $currentPlan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => $status,
            'starts_at' => $this->occurredAt->subMonth(),
            'expires_at' => $expiresAt,
            'grace_ends_at' => $graceEndsAt,
        ]);

        try {
            $this->service->handle(
                tenantId: $tenant->id,
                tenantLicenseId: $license->id,
                newPlanId: $newPlan->id,
                requestId: $requestId,
            );

            $this->fail('Expected status-based rejection.');
        } catch (InvalidTenantLicenseTransitionException $exception) {
            $this->assertSame(
                'cannot_change_plan_from_status',
                $exception->reasonCode,
            );
        }

        $license->refresh();

        $this->assertSame($currentPlan->id, $license->plan_id);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('tenant_modules', 0);
    }

    private function assertSamePeriodIsPreserved(
        string $currentUnit,
        ?int $currentCount,
        string $newUnit,
        ?int $newCount,
        string $requestId,
    ): void {
        $tenant = $this->createTenant();

        $currentPlan = $this->createPlan(
            code: $requestId.'-current',
            unit: $currentUnit,
            count: $currentCount,
        );

        $newPlan = $this->createPlan(
            code: $requestId.'-new',
            unit: $newUnit,
            count: $newCount,
        );

        $startsAt = $this->occurredAt->subMonth();
        $expiresAt = $this->occurredAt->addMonth();

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $startsAt,
            $expiresAt,
        );

        $result = $this->service->handle(
            tenantId: $tenant->id,
            tenantLicenseId: $license->id,
            newPlanId: $newPlan->id,
            requestId: $requestId,
        );

        $license->refresh();

        $this->assertFalse($result->periodRestarted);
        $this->assertTrue($license->starts_at->equalTo($startsAt));
        $this->assertTrue($license->expires_at->equalTo($expiresAt));
        $this->assertNull($license->grace_ends_at);
    }

    /**
     * @return array{Tenant, Plan, Plan, TenantLicense}
     */
    private function createStandardChangeScenario(
        string $prefix,
    ): array {
        $tenant = $this->createTenant();
        $currentPlan = $this->createPlan($prefix.'-current');
        $newPlan = $this->createPlan($prefix.'-new');

        $license = $this->createActiveLicense(
            $tenant,
            $currentPlan,
            $this->occurredAt->subMonth(),
            $this->occurredAt->addMonth(),
        );

        return [$tenant, $currentPlan, $newPlan, $license];
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Plan Change Test Tenant',
            'slug' => 'plan-change-test-tenant-'.str()->random(8),
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

    private function createActiveLicense(
        Tenant $tenant,
        Plan $plan,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $expiresAt,
        string $origin = TenantLicense::ORIGIN_SUBSCRIPTION,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => $origin,
            'status' => TenantLicense::STATUS_ACTIVE,
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

    private function createTenantModule(
        Tenant $tenant,
        Module $module,
        string $source,
        string $status = TenantModule::STATUS_ENABLED,
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
