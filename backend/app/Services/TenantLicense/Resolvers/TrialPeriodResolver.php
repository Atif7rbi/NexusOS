<?php

declare(strict_types=1);

namespace App\Services\TenantLicense\Resolvers;

use App\Exceptions\TenantLicense\InvalidLicenseDurationConfigurationException;
use App\Models\Plan;
use Carbon\CarbonImmutable;

final class TrialPeriodResolver
{
    public function resolve(
        Plan $plan,
        CarbonImmutable $anchor
    ): CarbonImmutable {
        $trialDays = config('nexusos.tenant_license.trial_days');

        if (! is_int($trialDays) || $trialDays < 1) {
            throw new InvalidLicenseDurationConfigurationException(
                'Tenant license trial duration must be a positive integer.'
            );
        }

        return $anchor->addDays($trialDays);
    }
}
