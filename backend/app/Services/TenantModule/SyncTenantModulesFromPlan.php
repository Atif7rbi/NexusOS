<?php

declare(strict_types=1);

namespace App\Services\TenantModule;

use App\Data\TenantModule\SyncTenantModulesFromPlanResult;
use App\Exceptions\TenantModule\NoCurrentTenantLicenseException;
use App\Models\AuditLog;
use App\Models\PlanModule;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncTenantModulesFromPlan
{
    public function handle(
        string $tenantId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): SyncTenantModulesFromPlanResult {
        return DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $requestId
        ): SyncTenantModulesFromPlanResult {
            $currentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->first();

            if (! $currentLicense) {
                throw new NoCurrentTenantLicenseException(
                    'Tenant does not have a current license.'
                );
            }

            $planId = $currentLicense->plan_id;

            $activePlanModuleIds = PlanModule::query()
                ->join(
                    'modules',
                    'modules.id',
                    '=',
                    'plan_modules.module_id'
                )
                ->where('plan_modules.plan_id', $planId)
                ->where('modules.is_active', true)
                ->whereNull('modules.deprecated_at')
                ->pluck('plan_modules.module_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            $activePlanModuleIdMap = array_fill_keys(
                $activePlanModuleIds,
                true
            );

            $existingTenantModules = TenantModule::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->get()
                ->keyBy('module_id');

            $createdModuleIds = [];
            $enabledModuleIds = [];
            $disabledModuleIds = [];
            $skippedProtectedModuleIds = [];
            $unchangedModuleIds = [];

            foreach ($activePlanModuleIds as $moduleId) {
                /** @var TenantModule|null $tenantModule */
                $tenantModule = $existingTenantModules->get($moduleId);

                if (! $tenantModule) {
                    TenantModule::query()->create([
                        'tenant_id' => $tenantId,
                        'module_id' => $moduleId,
                        'status' => TenantModule::STATUS_ENABLED,
                        'source' => TenantModule::SOURCE_PLAN,
                        'enabled_by' => $actorUserId,
                        'enabled_at' => now(),
                        'disabled_at' => null,
                    ]);

                    $createdModuleIds[] = $moduleId;

                    continue;
                }

                if ($tenantModule->source !== TenantModule::SOURCE_PLAN) {
                    $skippedProtectedModuleIds[] = $moduleId;

                    continue;
                }

                if ($tenantModule->status === TenantModule::STATUS_ENABLED) {
                    $unchangedModuleIds[] = $moduleId;

                    continue;
                }

                $tenantModule->update([
                    'status' => TenantModule::STATUS_ENABLED,
                    'enabled_by' => $actorUserId,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                ]);

                $enabledModuleIds[] = $moduleId;
            }

            foreach ($existingTenantModules as $moduleId => $tenantModule) {
                $moduleId = (string) $moduleId;

                if (isset($activePlanModuleIdMap[$moduleId])) {
                    continue;
                }

                if ($tenantModule->source !== TenantModule::SOURCE_PLAN) {
                    $skippedProtectedModuleIds[] = $moduleId;

                    continue;
                }

                if ($tenantModule->status === TenantModule::STATUS_DISABLED) {
                    $unchangedModuleIds[] = $moduleId;

                    continue;
                }

                $tenantModule->update([
                    'status' => TenantModule::STATUS_DISABLED,
                    'disabled_at' => now(),
                ]);

                $disabledModuleIds[] = $moduleId;
            }

            $skippedProtectedModuleIds = array_values(
                array_unique($skippedProtectedModuleIds)
            );

            $unchangedModuleIds = array_values(
                array_unique($unchangedModuleIds)
            );

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_module.synced_from_plan',
                'entity_type' => Tenant::class,
                'entity_id' => $tenantId,
                'request_id' => $requestId ?? (string) Str::uuid(),
                'changes' => [
                    'created' => count($createdModuleIds),
                    'enabled' => count($enabledModuleIds),
                    'disabled' => count($disabledModuleIds),
                    'skipped_protected' => count(
                        $skippedProtectedModuleIds
                    ),
                    'unchanged' => count($unchangedModuleIds),
                ],
                'snapshot' => [
                    'created_module_ids' => $createdModuleIds,
                    'enabled_module_ids' => $enabledModuleIds,
                    'disabled_module_ids' => $disabledModuleIds,
                    'skipped_protected_module_ids' => $skippedProtectedModuleIds,
                    'unchanged_module_ids' => $unchangedModuleIds,
                ],
                'metadata' => [
                    'license_id' => $currentLicense->id,
                    'plan_id' => $planId,
                    'sync_source' => 'current_license',
                ],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);

            return new SyncTenantModulesFromPlanResult(
                tenantId: $tenantId,
                planId: $planId,
                created: count($createdModuleIds),
                enabled: count($enabledModuleIds),
                disabled: count($disabledModuleIds),
                skippedProtected: count($skippedProtectedModuleIds),
                unchanged: count($unchangedModuleIds),
                createdModuleIds: $createdModuleIds,
                enabledModuleIds: $enabledModuleIds,
                disabledModuleIds: $disabledModuleIds,
                skippedProtectedModuleIds: $skippedProtectedModuleIds,
                unchangedModuleIds: $unchangedModuleIds,
            );
        });
    }
}
