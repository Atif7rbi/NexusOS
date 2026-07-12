<?php

declare(strict_types=1);

namespace App\Services\TenantModule;

use App\Data\TenantModule\SyncTenantModulesFromPlanResult;
use App\Exceptions\TenantModule\NoCurrentTenantLicenseException;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Services\TenantModule\Operations\SyncTenantModulesFromPlanOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public application service for synchronizing a Tenant's modules from
 * its current license Plan.
 *
 * This service owns:
 * - the transaction;
 * - aggregate lock ordering;
 * - current-license discovery;
 * - occurredAt generation;
 * - request ID generation.
 *
 * Synchronization implementation belongs to
 * SyncTenantModulesFromPlanOperation.
 */
final class SyncTenantModulesFromPlan
{
    public function __construct(
        private readonly SyncTenantModulesFromPlanOperation $operation,
    ) {
    }

    public function handle(
        string $tenantId,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): SyncTenantModulesFromPlanResult {
        $occurredAt = CarbonImmutable::now('UTC');
        $resolvedRequestId = $requestId ?? (string) Str::uuid();

        return DB::transaction(function () use (
            $tenantId,
            $actorUserId,
            $occurredAt,
            $resolvedRequestId,
        ): SyncTenantModulesFromPlanResult {
            /*
             * Official aggregate lock order:
             *
             * Tenant
             *   ↓
             * TenantLicense
             *   ↓
             * TenantModule rows inside the operation
             */
            Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentLicense = TenantLicense::query()
                ->where('tenant_id', $tenantId)
                ->current()
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($currentLicense === null) {
                throw new NoCurrentTenantLicenseException(
                    'Tenant does not have a current license.'
                );
            }

            return $this->operation->execute(
                tenantId: $tenantId,
                licenseId: $currentLicense->id,
                planId: $currentLicense->plan_id,
                occurredAt: $occurredAt,
                actorUserId: $actorUserId,
                requestId: $resolvedRequestId,
            );
        });
    }
}
