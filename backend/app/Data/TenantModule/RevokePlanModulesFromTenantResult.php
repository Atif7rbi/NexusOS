<?php

declare(strict_types=1);

namespace App\Data\TenantModule;

/**
 * Result of revoking all plan-derived module entitlements for a Tenant.
 *
 * Only TenantModule rows whose source is "plan" are owned by this
 * operation. Manual, trial, promo, and override rows are untouched.
 */
final readonly class RevokePlanModulesFromTenantResult
{
    /**
     * @param list<string> $revokedModuleIds
     * @param list<string> $alreadyDisabledModuleIds
     */
    public function __construct(
        public string $tenantId,
        public string $planId,
        public int $revoked,
        public int $alreadyDisabled,
        public array $revokedModuleIds,
        public array $alreadyDisabledModuleIds,
    ) {
    }
}
