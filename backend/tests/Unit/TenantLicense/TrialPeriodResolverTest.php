<?php

declare(strict_types=1);

namespace Tests\Unit\TenantLicense;

use App\Exceptions\TenantLicense\InvalidLicenseDurationConfigurationException;
use App\Models\Plan;
use App\Services\TenantLicense\Resolvers\TrialPeriodResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class TrialPeriodResolverTest extends TestCase
{
    public function test_it_resolves_the_trial_expiration_date(): void
    {
        config()->set('nexusos.tenant_license.trial_days', 14);

        $plan = new Plan();
        $anchor = CarbonImmutable::parse('2026-07-10 08:00:00', 'UTC');

        $expiresAt = app(TrialPeriodResolver::class)->resolve(
            $plan,
            $anchor
        );

        $this->assertTrue(
            $expiresAt->equalTo('2026-07-24 08:00:00 UTC')
        );

        $this->assertTrue(
            $anchor->equalTo('2026-07-10 08:00:00 UTC')
        );
    }

    public function test_it_rejects_an_invalid_trial_duration(): void
    {
        config()->set('nexusos.tenant_license.trial_days', 0);

        $this->expectException(
            InvalidLicenseDurationConfigurationException::class
        );

        app(TrialPeriodResolver::class)->resolve(
            new Plan(),
            CarbonImmutable::parse('2026-07-10 08:00:00', 'UTC')
        );
    }
}
