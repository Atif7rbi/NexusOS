<?php

declare(strict_types=1);

namespace App\Data\TenantLicense;

use Carbon\CarbonImmutable;

/**
 * Result of issuing a new operational trial license.
 */
final readonly class IssueTrialLicenseResult
{
    public function __construct(
        public string $tenantLicenseId,
        public string $tenantId,
        public string $planId,
        public string $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $graceEndsAt,
    ) {
    }
}
