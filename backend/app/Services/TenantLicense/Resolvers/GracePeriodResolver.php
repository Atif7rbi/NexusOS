<?php

declare(strict_types=1);

namespace App\Services\TenantLicense\Resolvers;

use App\Exceptions\TenantLicense\InvalidLicenseDurationConfigurationException;
use App\Models\Plan;
use Carbon\CarbonImmutable;

final class GracePeriodResolver
{
    public function resolve(
        Plan $plan,
        CarbonImmutable $anchor
    ): CarbonImmutable {
        $gracePeriodDays = config(
            'nexusos.tenant_license.grace_period_days'
        );

        if (! is_int($gracePeriodDays) || $gracePeriodDays < 1) {
            throw new InvalidLicenseDurationConfigurationException(
                'Tenant license grace period duration must be a positive integer.'
            );
        }

        return $anchor->addDays($gracePeriodDays);
    }
}
