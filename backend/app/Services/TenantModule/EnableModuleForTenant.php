<?php

declare(strict_types=1);

namespace App\Services\TenantModule;

use App\Data\TenantModule\EnableModuleForTenantResult;
use App\Exceptions\TenantModule\ModuleNotAllowedForPlanException;
use App\Exceptions\TenantModule\NoCurrentTenantLicenseException;
use App\Exceptions\TenantModule\ProtectedModuleSourceException;
use App\Models\AuditLog;
use App\Models\PlanModule;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnableModuleForTenant
{
    public function handle(
        string $tenantId,
        string $moduleId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): EnableModuleForTenantResult {
        return DB::transaction(function () use ($tenantId, $moduleId, $actorUserId, $requestId): EnableModuleForTenantResult {
            $currentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->first();

            if (! $currentLicense) {
                throw new NoCurrentTenantLicenseException('Tenant does not have a current license.');
            }

            $isAllowedByPlan = PlanModule::query()
                ->where('plan_id', $currentLicense->plan_id)
                ->where('module_id', $moduleId)
                ->exists();

            if (! $isAllowedByPlan) {
                throw new ModuleNotAllowedForPlanException('Module is not allowed by the tenant current plan.');
            }

            $tenantModule = TenantModule::query()
                ->where('tenant_id', $tenantId)
                ->where('module_id', $moduleId)
                ->lockForUpdate()
                ->first();

            if ($tenantModule && $tenantModule->source === TenantModule::SOURCE_OVERRIDE) {
                throw new ProtectedModuleSourceException('Override tenant module source cannot be changed by this service.');
            }

            if ($tenantModule && $tenantModule->status === TenantModule::STATUS_ENABLED) {
                return new EnableModuleForTenantResult(
                    tenantModuleId: $tenantModule->id,
                    tenantId: $tenantModule->tenant_id,
                    moduleId: $tenantModule->module_id,
                    status: $tenantModule->status,
                    source: $tenantModule->source,
                    changed: false,
                );
            }

            if (! $tenantModule) {
                $tenantModule = TenantModule::query()->create([
                    'tenant_id' => $tenantId,
                    'module_id' => $moduleId,
                    'status' => TenantModule::STATUS_ENABLED,
                    'source' => TenantModule::SOURCE_PLAN,
                    'enabled_by' => $actorUserId,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                ]);
            } else {
                $tenantModule->update([
                    'status' => TenantModule::STATUS_ENABLED,
                    'source' => TenantModule::SOURCE_PLAN,
                    'enabled_by' => $actorUserId,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                ]);
            }

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_module.enabled',
                'entity_type' => TenantModule::class,
                'entity_id' => $tenantModule->id,
                'request_id' => $requestId ?? (string) Str::uuid(),
                'changes' => [
                    'status' => TenantModule::STATUS_ENABLED,
                    'source' => TenantModule::SOURCE_PLAN,
                ],
                'snapshot' => [
                    'tenant_module_id' => $tenantModule->id,
                    'tenant_id' => $tenantModule->tenant_id,
                    'module_id' => $tenantModule->module_id,
                    'status' => $tenantModule->status,
                    'source' => $tenantModule->source,
                ],
                'metadata' => null,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);

            return new EnableModuleForTenantResult(
                tenantModuleId: $tenantModule->id,
                tenantId: $tenantModule->tenant_id,
                moduleId: $tenantModule->module_id,
                status: $tenantModule->status,
                source: $tenantModule->source,
                changed: true,
            );
        });
    }
}
