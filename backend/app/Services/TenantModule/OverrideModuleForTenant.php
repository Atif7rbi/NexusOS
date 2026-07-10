<?php

declare(strict_types=1);

namespace App\Services\TenantModule;

use App\Data\TenantModule\OverrideModuleForTenantResult;
use App\Exceptions\TenantModule\InvalidTenantModuleStatusException;
use App\Exceptions\TenantModule\NoCurrentTenantLicenseException;
use App\Exceptions\TenantModule\OverrideReasonRequiredException;
use App\Exceptions\TenantModule\PlatformActorRequiredException;
use App\Models\AuditLog;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OverrideModuleForTenant
{
    public function handle(
        string $tenantId,
        string $moduleId,
        string $targetStatus,
        string $actorUserId,
        string $reason,
        bool $isPlatformActor,
        ?string $requestId = null,
    ): OverrideModuleForTenantResult {
        if (! $isPlatformActor) {
            throw new PlatformActorRequiredException(
                'Only platform actors are allowed to override tenant module state.'
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new OverrideReasonRequiredException(
                'A non-empty reason is required to override a tenant module.'
            );
        }

        if (! in_array(
            $targetStatus,
            [
                TenantModule::STATUS_ENABLED,
                TenantModule::STATUS_DISABLED,
            ],
            true
        )) {
            throw new InvalidTenantModuleStatusException(
                'Invalid tenant module target status.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $moduleId,
            $targetStatus,
            $actorUserId,
            $reason,
            $requestId
        ): OverrideModuleForTenantResult {
            $hasCurrentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->exists();

            if (! $hasCurrentLicense) {
                throw new NoCurrentTenantLicenseException(
                    'Tenant does not have a current license.'
                );
            }

            $tenantModule = TenantModule::query()
                ->where('tenant_id', $tenantId)
                ->where('module_id', $moduleId)
                ->lockForUpdate()
                ->first();

            $before = $tenantModule
                ? [
                    'status' => $tenantModule->status,
                    'source' => $tenantModule->source,
                    'enabled_by' => $tenantModule->enabled_by,
                    'enabled_at' => $tenantModule->enabled_at?->toISOString(),
                    'disabled_at' => $tenantModule->disabled_at?->toISOString(),
                ]
                : null;

            $isReaffirmed = $tenantModule
                && $tenantModule->source === TenantModule::SOURCE_OVERRIDE
                && $tenantModule->status === $targetStatus;

            if (! $isReaffirmed) {
                $attributes = [
                    'status' => $targetStatus,
                    'source' => TenantModule::SOURCE_OVERRIDE,
                ];

                if ($targetStatus === TenantModule::STATUS_ENABLED) {
                    $attributes['enabled_by'] = $actorUserId;
                    $attributes['enabled_at'] = now();
                    $attributes['disabled_at'] = null;
                }

                if ($targetStatus === TenantModule::STATUS_DISABLED) {
                    $attributes['disabled_at'] = now();
                }

                if (! $tenantModule) {
                    $tenantModule = TenantModule::query()->create([
                        'tenant_id' => $tenantId,
                        'module_id' => $moduleId,
                        ...$attributes,
                    ]);
                } else {
                    $tenantModule->update($attributes);
                }
            }

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'category' => AuditLog::CATEGORY_BUSINESS,
                'event' => $isReaffirmed
                    ? 'tenant_module.override_reaffirmed'
                    : 'tenant_module.override',
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
                'metadata' => [
                    'reason' => $reason,
                    'is_platform_actor' => true,
                ],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);

            return new OverrideModuleForTenantResult(
                tenantModuleId: $tenantModule->id,
                tenantId: $tenantModule->tenant_id,
                moduleId: $tenantModule->module_id,
                status: $tenantModule->status,
                source: $tenantModule->source,
                previousStatus: $before['status'] ?? null,
                previousSource: $before['source'] ?? null,
                changed: ! $isReaffirmed,
            );
        });
    }
}
