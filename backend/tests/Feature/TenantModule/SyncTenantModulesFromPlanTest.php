<?php

declare(strict_types=1);

namespace Tests\Feature\TenantModule;

use App\Exceptions\TenantModule\NoCurrentTenantLicenseException;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantModule\SyncTenantModulesFromPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncTenantModulesFromPlanTest extends TestCase
{
    use RefreshDatabase;

    private SyncTenantModulesFromPlan $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SyncTenantModulesFromPlan::class);
    }

    public function test_it_protects_manual_trial_promo_and_override_sources(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createCurrentLicense($tenant, $plan);

        $protectedSources = [
            TenantModule::SOURCE_MANUAL,
            TenantModule::SOURCE_TRIAL,
            TenantModule::SOURCE_PROMO,
            TenantModule::SOURCE_OVERRIDE,
        ];

        $moduleIds = [];
        $originalEnabledAt = CarbonImmutable::parse(
            '2026-07-01 08:00:00',
            'UTC'
        );

        foreach ($protectedSources as $index => $source) {
            $module = $this->createModule(
                code: sprintf('protected_%d', $index)
            );

            $this->attachModuleToPlan($plan, $module);

            TenantModule::query()->create([
                'tenant_id' => $tenant->id,
                'module_id' => $module->id,
                'status' => TenantModule::STATUS_DISABLED,
                'source' => $source,
                'enabled_by' => null,
                'enabled_at' => $originalEnabledAt,
                'disabled_at' => $originalEnabledAt,
            ]);

            $moduleIds[] = $module->id;
        }

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'protected-source-test',
        );

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->enabled);
        $this->assertSame(0, $result->disabled);
        $this->assertSame(4, $result->skippedProtected);
        $this->assertSame(0, $result->unchanged);

        $this->assertEqualsCanonicalizing(
            $moduleIds,
            $result->skippedProtectedModuleIds
        );

        foreach ($protectedSources as $index => $source) {
            $tenantModule = TenantModule::query()
                ->where('tenant_id', $tenant->id)
                ->where('module_id', $moduleIds[$index])
                ->sole();

            $this->assertSame(
                TenantModule::STATUS_DISABLED,
                $tenantModule->status
            );

            $this->assertSame($source, $tenantModule->source);

            $this->assertTrue(
                $tenantModule->enabled_at->equalTo($originalEnabledAt)
            );

            $this->assertTrue(
                $tenantModule->disabled_at->equalTo($originalEnabledAt)
            );
        }
    }

    public function test_it_creates_restores_disables_and_preserves_plan_modules(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createCurrentLicense($tenant, $plan);

        $createdModule = $this->createModule('created_module');
        $restoredModule = $this->createModule('restored_module');
        $disabledModule = $this->createModule('disabled_module');
        $unchangedEnabledModule = $this->createModule(
            'unchanged_enabled_module'
        );
        $unchangedDisabledModule = $this->createModule(
            'unchanged_disabled_module'
        );

        $this->attachModuleToPlan($plan, $createdModule);
        $this->attachModuleToPlan($plan, $restoredModule);
        $this->attachModuleToPlan($plan, $unchangedEnabledModule);

        $oldTimestamp = CarbonImmutable::parse(
            '2026-07-01 08:00:00',
            'UTC'
        );

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $restoredModule->id,
            'status' => TenantModule::STATUS_DISABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $oldTimestamp,
            'disabled_at' => $oldTimestamp,
        ]);

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $disabledModule->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $oldTimestamp,
            'disabled_at' => null,
        ]);

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $unchangedEnabledModule->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $oldTimestamp,
            'disabled_at' => null,
        ]);

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $unchangedDisabledModule->id,
            'status' => TenantModule::STATUS_DISABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => $oldTimestamp,
            'disabled_at' => $oldTimestamp,
        ]);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'sync-behavior-test',
        );

        $this->assertSame($tenant->id, $result->tenantId);
        $this->assertSame($plan->id, $result->planId);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->enabled);
        $this->assertSame(1, $result->disabled);
        $this->assertSame(0, $result->skippedProtected);
        $this->assertSame(2, $result->unchanged);

        $this->assertSame(
            [$createdModule->id],
            $result->createdModuleIds
        );

        $this->assertSame(
            [$restoredModule->id],
            $result->enabledModuleIds
        );

        $this->assertSame(
            [$disabledModule->id],
            $result->disabledModuleIds
        );

        $this->assertEqualsCanonicalizing(
            [
                $unchangedEnabledModule->id,
                $unchangedDisabledModule->id,
            ],
            $result->unchangedModuleIds
        );

        $createdTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $createdModule->id)
            ->sole();

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $createdTenantModule->status
        );

        $this->assertSame(
            TenantModule::SOURCE_PLAN,
            $createdTenantModule->source
        );

        $this->assertNotNull($createdTenantModule->enabled_at);
        $this->assertNull($createdTenantModule->disabled_at);

        $restoredTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $restoredModule->id)
            ->sole();

        $this->assertSame(
            TenantModule::STATUS_ENABLED,
            $restoredTenantModule->status
        );

        $this->assertNotNull($restoredTenantModule->enabled_at);
        $this->assertNull($restoredTenantModule->disabled_at);

        $disabledTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $disabledModule->id)
            ->sole();

        $this->assertSame(
            TenantModule::STATUS_DISABLED,
            $disabledTenantModule->status
        );

        $this->assertNotNull($disabledTenantModule->disabled_at);
    }

    public function test_it_ignores_inactive_and_deprecated_plan_modules(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createCurrentLicense($tenant, $plan);

        $inactiveModule = $this->createModule(
            code: 'inactive_module',
            active: false,
        );

        $deprecatedModule = $this->createModule(
            code: 'deprecated_module',
            deprecatedAt: CarbonImmutable::parse(
                '2026-07-01 08:00:00',
                'UTC'
            ),
        );

        $this->attachModuleToPlan($plan, $inactiveModule);
        $this->attachModuleToPlan($plan, $deprecatedModule);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'inactive-deprecated-test',
        );

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->enabled);
        $this->assertSame(0, $result->disabled);
        $this->assertSame(0, $result->skippedProtected);
        $this->assertSame(0, $result->unchanged);

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $inactiveModule->id,
        ]);

        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_id' => $tenant->id,
            'module_id' => $deprecatedModule->id,
        ]);
    }

    public function test_it_writes_audit_log_even_when_sync_is_a_no_op(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createCurrentLicense($tenant, $plan);
        $module = $this->createModule('already_enabled_module');

        $this->attachModuleToPlan($plan, $module);

        TenantModule::query()->create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_ENABLED,
            'source' => TenantModule::SOURCE_PLAN,
            'enabled_by' => null,
            'enabled_at' => CarbonImmutable::parse(
                '2026-07-01 08:00:00',
                'UTC'
            ),
            'disabled_at' => null,
        ]);

        $result = $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'no-op-request-id',
        );

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->enabled);
        $this->assertSame(0, $result->disabled);
        $this->assertSame(0, $result->skippedProtected);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame([$module->id], $result->unchangedModuleIds);

        $auditLog = AuditLog::query()
            ->where('request_id', 'no-op-request-id')
            ->sole();

        $this->assertSame($tenant->id, $auditLog->tenant_id);
        $this->assertNull($auditLog->actor_user_id);
        $this->assertSame(
            AuditLog::CATEGORY_BUSINESS,
            $auditLog->category
        );
        $this->assertSame(
            'tenant_module.synced_from_plan',
            $auditLog->event
        );
        $this->assertSame(Tenant::class, $auditLog->entity_type);
        $this->assertSame($tenant->id, $auditLog->entity_id);

        $expectedChanges = [
            'created' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'skipped_protected' => 0,
            'unchanged' => 1,
        ];

        $actualChanges = $auditLog->changes;

        ksort($expectedChanges);
        ksort($actualChanges);

        $this->assertSame($expectedChanges, $actualChanges);

        $expectedSnapshot = [
            'created_module_ids' => [],
            'enabled_module_ids' => [],
            'disabled_module_ids' => [],
            'skipped_protected_module_ids' => [],
            'unchanged_module_ids' => [$module->id],
        ];

        $actualSnapshot = $auditLog->snapshot;

        ksort($expectedSnapshot);
        ksort($actualSnapshot);

        $this->assertSame($expectedSnapshot, $actualSnapshot);

        $expectedMetadata = [
            'license_id' => $license->id,
            'plan_id' => $plan->id,
            'sync_source' => 'current_license',
        ];

        $actualMetadata = $auditLog->metadata;

        ksort($expectedMetadata);
        ksort($actualMetadata);

        $this->assertSame($expectedMetadata, $actualMetadata);

        $this->assertNotNull($auditLog->created_at);
    }

    public function test_it_generates_a_request_id_when_none_is_provided(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $this->createCurrentLicense($tenant, $plan);

        $this->service->handle(tenantId: $tenant->id);

        $auditLog = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->sole();

        $this->assertNotNull($auditLog->request_id);
        $this->assertSame(36, strlen($auditLog->request_id));
    }

    public function test_it_rejects_sync_when_tenant_has_no_current_license(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(
            NoCurrentTenantLicenseException::class
        );

        $this->expectExceptionMessage(
            'Tenant does not have a current license.'
        );

        $this->service->handle(
            tenantId: $tenant->id,
            requestId: 'missing-license-test',
        );
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Characterization Tenant',
            'slug' => 'characterization-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Characterization Plan',
            'code' => 'characterization-plan-'.str()->random(8),
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

    private function createCurrentLicense(
        Tenant $tenant,
        Plan $plan,
    ): TenantLicense {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'license_origin' => TenantLicense::ORIGIN_SUBSCRIPTION,
            'status' => TenantLicense::STATUS_ACTIVE,
            'starts_at' => CarbonImmutable::parse(
                '2026-07-01 08:00:00',
                'UTC'
            ),
            'expires_at' => CarbonImmutable::parse(
                '2026-08-01 08:00:00',
                'UTC'
            ),
            'grace_ends_at' => null,
        ]);
    }
}
