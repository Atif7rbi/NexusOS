<?php

declare(strict_types=1);

namespace App\Services\TenantModule\Operations;

use App\Data\TenantModule\RevokePlanModulesFromTenantResult;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantModule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Internal operation for revoking plan-derived TenantModule entitlements.
 *
 * This operation:
 * - opens no transaction;
 * - generates no timestamp;
 * - generates no request ID;
 * - assumes the Tenant and TenantLicense rows are already locked;
 * - owns only TenantModule rows whose source is "plan";
 * - never touches manual, trial, promo, or override rows.
 */
final class RevokePlanModulesFromTenantOperation
{
    public const TRIGGER_LICENSE_EXPIRATION = 'license_expiration';

    public const TRIGGER_LICENSE_CANCELLATION = 'license_cancellation';

    private const ALLOWED_TRIGGERS = [
        self::TRIGGER_LICENSE_EXPIRATION,
        self::TRIGGER_LICENSE_CANCELLATION,
    ];

    public function execute(
        string $tenantId,
        string $licenseId,
        string $planId,
        string $trigger,
        CarbonImmutable $occurredAt,
        ?string $actorUserId,
        string $requestId,
    ): RevokePlanModulesFromTenantResult {
        if (! in_array($trigger, self::ALLOWED_TRIGGERS, true)) {
            throw new InvalidArgumentException(
                'Unsupported plan entitlement revocation trigger.'
            );
        }

        /*
         * TenantModule is the third aggregate lock level:
         *
         * Tenant
         *   ↓
         * TenantLicense
         *   ↓
         * TenantModule rows
         *
         * Deterministic ordering reduces deadlock risk.
         */
        $planTenantModules = TenantModule::query()
            ->where('tenant_id', $tenantId)
            ->where('source', TenantModule::SOURCE_PLAN)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $revokedModuleIds = [];
        $alreadyDisabledModuleIds = [];

        foreach ($planTenantModules as $tenantModule) {
            if ($tenantModule->status === TenantModule::STATUS_DISABLED) {
                $alreadyDisabledModuleIds[] = $tenantModule->module_id;

                continue;
            }

            $tenantModule->forceFill([
                'status' => TenantModule::STATUS_DISABLED,
                'disabled_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $tenantModule->save();

            $revokedModuleIds[] = $tenantModule->module_id;
        }

        /*
         * A real revocation transition always records that the operation
         * ran, even when no plan-derived module required mutation.
         */
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'category' => AuditLog::CATEGORY_BUSINESS,
            'event' => 'tenant_module.plan_entitlement_revoked',
            'entity_type' => Tenant::class,
            'entity_id' => $tenantId,
            'request_id' => $requestId,
            'changes' => [
                'revoked' => count($revokedModuleIds),
                'already_disabled' => count(
                    $alreadyDisabledModuleIds
                ),
            ],
            'snapshot' => [
                'revoked_module_ids' => $revokedModuleIds,
                'already_disabled_module_ids' => $alreadyDisabledModuleIds,
            ],
            'metadata' => [
                'trigger' => $trigger,
                'license_id' => $licenseId,
                'plan_id' => $planId,
            ],
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $occurredAt,
        ]);

        return new RevokePlanModulesFromTenantResult(
            tenantId: $tenantId,
            planId: $planId,
            revoked: count($revokedModuleIds),
            alreadyDisabled: count($alreadyDisabledModuleIds),
            revokedModuleIds: $revokedModuleIds,
            alreadyDisabledModuleIds: $alreadyDisabledModuleIds,
        );
    }
}
