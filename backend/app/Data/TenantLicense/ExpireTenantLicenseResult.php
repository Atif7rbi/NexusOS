<?php

declare(strict_types=1);

namespace App\Data\TenantLicense;

use Carbon\CarbonImmutable;

/**
 * Result of expiring an eligible license.
 *
 * This is the only TenantLicense result carrying a changed flag.
 * Reprocessing an already expired license is an idempotent no-op.
 */
final readonly class ExpireTenantLicenseResult
{
    public function __construct(
        public string $tenantLicenseId,
        public string $tenantId,
        public string $planId,
        public string $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $graceEndsAt,
        public bool $changed,
    ) {
    }
}
