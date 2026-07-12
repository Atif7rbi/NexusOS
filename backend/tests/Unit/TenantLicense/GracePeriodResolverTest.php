<?php

declare(strict_types=1);

namespace Tests\Unit\TenantLicense;

use App\Exceptions\TenantLicense\InvalidLicenseDurationConfigurationException;
use App\Models\Plan;
use App\Services\TenantLicense\Resolvers\GracePeriodResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class GracePeriodResolverTest extends TestCase
{
    public function test_it_resolves_the_grace_period_end_date(): void
    {
        config()->set(
            'nexusos.tenant_license.grace_period_days',
            7
        );

        $plan = new Plan();
        $anchor = CarbonImmutable::parse('2026-07-10 08:00:00', 'UTC');

        $graceEndsAt = app(GracePeriodResolver::class)->resolve(
            $plan,
            $anchor
        );

        $this->assertTrue(
            $graceEndsAt->equalTo('2026-07-17 08:00:00 UTC')
        );

        $this->assertTrue(
            $anchor->equalTo('2026-07-10 08:00:00 UTC')
        );
    }

    public function test_it_rejects_an_invalid_grace_duration(): void
    {
        config()->set(
            'nexusos.tenant_license.grace_period_days',
            0
        );

        $this->expectException(
            InvalidLicenseDurationConfigurationException::class
        );

        app(GracePeriodResolver::class)->resolve(
            new Plan(),
            CarbonImmutable::parse('2026-07-10 08:00:00', 'UTC')
        );
    }
}
