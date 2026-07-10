<?php

declare(strict_types=1);

namespace App\Services\TenantModule;

use App\Data\TenantModule\DisableModuleForTenantResult;
use App\Exceptions\TenantModule\ProtectedModuleSourceException;
use App\Exceptions\TenantModule\TenantModuleNotFoundException;
use App\Models\AuditLog;
use App\Models\TenantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DisableModuleForTenant
{
    public function handle(
        string $tenantId,
        string $moduleId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): DisableModuleForTenantResult {
        return DB::transaction(function () use (
            $tenantId,
            $moduleId,
            $actorUserId,
            $requestId
        ): DisableModuleForTenantResult {
            $tenantModule = TenantModule::query()
                ->where('tenant_id', $tenantId)
                ->where('module_id', $moduleId)
                ->lockForUpdate()
                ->first();

            if (! $tenantModule) {
                throw new TenantModuleNotFoundException(
                    'Tenant module record was not found.'
                );
            }

            if ($tenantModule->source === TenantModule::SOURCE_OVERRIDE) {
                throw new ProtectedModuleSourceException(
                    'Override tenant module source cannot be changed by this service.'
                );
            }

            if ($tenantModule->status === TenantModule::STATUS_DISABLED) {
                return new DisableModuleForTenantResult(
                    tenantModuleId: $tenantModule->id,
                    tenantId: $tenantModule->tenant_id,
                    moduleId: $tenantModule->module_id,
                    status: $tenantModule->status,
                    source: $tenantModule->source,
                    changed: false,
                );
            }

            $before = [
                'status' => $tenantModule->status,
                'source' => $tenantModule->source,
                'enabled_by' => $tenantModule->enabled_by,
                'enabled_at' => $tenantModule->enabled_at?->toISOString(),
                'disabled_at' => $tenantModule->disabled_at?->toISOString(),
            ];

            $tenantModule->update([
                'status' => TenantModule::STATUS_DISABLED,
                'disabled_at' => now(),
            ]);

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => 'tenant_module.disabled',
                'entity_type' => TenantModule::class,
                'entity_id' => $tenantModule->id,
                'request_id' => $requestId ?? (string) Str::uuid(),
                'changes' => [
                    'before' => $before,
                    'after' => [
                        'status' => $tenantModule->status,
                        'source' => $tenantModule->source,
                        'enabled_by' => $tenantModule->enabled_by,
                        'enabled_at' => $tenantModule->enabled_at?->toISOString(),
                        'disabled_at' => $tenantModule->disabled_at?->toISOString(),
                    ],
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

            return new DisableModuleForTenantResult(
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
