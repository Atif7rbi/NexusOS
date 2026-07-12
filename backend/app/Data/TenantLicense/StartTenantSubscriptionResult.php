<?php

declare(strict_types=1);

namespace App\Data\TenantLicense;

use Carbon\CarbonImmutable;

/**
 * Result of starting a new active tenant subscription.
 */
final readonly class StartTenantSubscriptionResult
{
    public function __construct(
        public string $tenantLicenseId,
        public string $tenantId,
        public string $planId,
        public string $status,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $graceEndsAt,
    ) {
    }
}
