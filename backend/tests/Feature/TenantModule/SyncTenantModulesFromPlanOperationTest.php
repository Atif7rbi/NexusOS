<?php

declare(strict_types=1);

namespace Tests\Feature\TenantModule;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SyncTenantModulesFromPlanOperationTest extends TestCase
{
    use RefreshDatabase;

    private SyncTenantModulesFromPlanOperation $operation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operation = app(
            SyncTenantModulesFromPlanOperation::class
        );
    }

    public function test_it_uses_the_supplied_timestamp_and_request_id_everywhere(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createCurrentLicense($tenant, $plan);

        $createdModule = $this->createModule('operation_created');
        $restoredModule = $this->createModule('operation_restored');
        $disabledModule = $this->createModule('operation_disabled');

        $this->attachModuleToPlan($plan, $createdModule);
        $this->attachModuleToPlan($plan, $restoredModule);

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

        $occurredAt = CarbonImmutable::parse(
            '2026-07-11 09:15:30',
            'UTC'
        );

        $requestId = 'operation-request-0001';

        $result = DB::transaction(
            fn () => $this->operation->execute(
                tenantId: $tenant->id,
                licenseId: $license->id,
                planId: $plan->id,
                occurredAt: $occurredAt,
                actorUserId: null,
                requestId: $requestId,
            )
        );

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->enabled);
        $this->assertSame(1, $result->disabled);

        $createdTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $createdModule->id)
            ->sole();

        $this->assertTrue(
            $createdTenantModule->enabled_at->equalTo($occurredAt)
        );

        $this->assertNull(
            $createdTenantModule->disabled_at
        );

        $restoredTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $restoredModule->id)
            ->sole();

        $this->assertTrue(
            $restoredTenantModule->enabled_at->equalTo($occurredAt)
        );

        $this->assertNull(
            $restoredTenantModule->disabled_at
        );

        $disabledTenantModule = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $disabledModule->id)
            ->sole();

        $this->assertTrue(
            $disabledTenantModule->disabled_at->equalTo($occurredAt)
        );

        $auditLog = AuditLog::query()
            ->where('request_id', $requestId)
            ->sole();

        $this->assertSame(
            $requestId,
            $auditLog->request_id
        );

        $this->assertTrue(
            $auditLog->created_at->equalTo($occurredAt)
        );

        $this->assertSame(
            $license->id,
            $auditLog->metadata['license_id']
        );

        $this->assertSame(
            $plan->id,
            $auditLog->metadata['plan_id']
        );
    }

    public function test_it_does_not_open_or_own_a_transaction(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->createPlan();
        $license = $this->createCurrentLicense($tenant, $plan);

        $occurredAt = CarbonImmutable::parse(
            '2026-07-11 10:00:00',
            'UTC'
        );

        $transactionLevelBefore = DB::connection()
            ->transactionLevel();

        $this->operation->execute(
            tenantId: $tenant->id,
            licenseId: $license->id,
            planId: $plan->id,
            occurredAt: $occurredAt,
            actorUserId: null,
            requestId: 'transaction-ownership-test',
        );

        $transactionLevelAfter = DB::connection()
            ->transactionLevel();

        $this->assertSame(
            $transactionLevelBefore,
            $transactionLevelAfter
        );

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'request_id' => 'transaction-ownership-test',
            'event' => 'tenant_module.synced_from_plan',
        ]);
    }

    private function createTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Operation Test Tenant',
            'slug' => 'operation-test-tenant-'.str()->random(8),
            'status' => Tenant::STATUS_ACTIVE,
            'default_currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
        ]);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Operation Test Plan',
            'code' => 'operation-test-plan-'.str()->random(8),
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
    ): Module {
        return Module::query()->create([
            'name' => str_replace(
                '_',
                ' ',
                ucfirst($code)
            ),
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
