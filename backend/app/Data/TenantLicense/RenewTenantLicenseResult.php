<?php

declare(strict_types=1);

namespace App\Data\TenantLicense;

use Carbon\CarbonImmutable;

/**
 * Result of renewing an active or grace-period license.
 */
final readonly class RenewTenantLicenseResult
{
    public const TYPE_EARLY = 'early';
    public const TYPE_RECOVERY = 'recovery';

    public function __construct(
        public string $tenantLicenseId,
        public string $tenantId,
        public string $planId,
        public string $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $graceEndsAt,
        public string $renewalType,
    ) {
    }
}
