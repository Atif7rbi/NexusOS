<?php

declare(strict_types=1);

namespace App\Services\TenantLicense\Resolvers;

use App\Exceptions\TenantLicense\InvalidPlanBillingPeriodException;
use App\Models\Plan;
use Carbon\CarbonImmutable;

final class SubscriptionPeriodResolver
{
    public function resolve(
        Plan $plan,
        CarbonImmutable $anchor
    ): ?CarbonImmutable {
        if ($plan->isLifetime()) {
            if ($plan->billing_period_count !== null) {
                throw new InvalidPlanBillingPeriodException(
                    'Lifetime plans must not have a billing period count.'
                );
            }

            return null;
        }

        $periodCount = $plan->billing_period_count;

        if (! is_int($periodCount) || $periodCount < 1) {
            throw new InvalidPlanBillingPeriodException(
                'Finite plans must have a positive billing period count.'
            );
        }

        if ($plan->isMonthly()) {
            if (! in_array(
                $periodCount,
                Plan::ALLOWED_MONTH_COUNTS,
                true
            )) {
                throw new InvalidPlanBillingPeriodException(
                    'Monthly plan billing period count is not supported.'
                );
            }

            return $anchor->addMonthsNoOverflow($periodCount);
        }

        if ($plan->isYearly()) {
            return $anchor->addYearsNoOverflow($periodCount);
        }

        throw new InvalidPlanBillingPeriodException(
            'Plan billing period unit is not supported.'
        );
    }
}
