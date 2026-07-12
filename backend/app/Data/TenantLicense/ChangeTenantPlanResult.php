<?php

declare(strict_types=1);

namespace App\Data\TenantLicense;

use Carbon\CarbonImmutable;

/**
 * Result of changing the plan of an active tenant license.
 */
final readonly class ChangeTenantPlanResult
{
    public function __construct(
        public string $tenantLicenseId,
        public string $tenantId,
        public string $previousPlanId,
        public string $newPlanId,
        public string $status,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $graceEndsAt,
        public bool $periodRestarted,
    ) {
    }
}
