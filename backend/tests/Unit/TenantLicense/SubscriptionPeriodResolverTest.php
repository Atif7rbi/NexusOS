<?php

declare(strict_types=1);

namespace Tests\Unit\TenantLicense;

use App\Exceptions\TenantLicense\InvalidPlanBillingPeriodException;
use App\Models\Plan;
use App\Services\TenantLicense\Resolvers\SubscriptionPeriodResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SubscriptionPeriodResolverTest extends TestCase
{
    private SubscriptionPeriodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(SubscriptionPeriodResolver::class);
    }

    public function test_it_resolves_a_monthly_plan_without_overflow(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_MONTH,
            'billing_period_count' => 1,
        ]);

        $anchor = CarbonImmutable::parse(
            '2026-01-31 08:00:00',
            'UTC'
        );

        $expiresAt = $this->resolver->resolve($plan, $anchor);

        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            $expiresAt->equalTo('2026-02-28 08:00:00 UTC')
        );
    }

    public function test_it_resolves_a_multi_month_plan(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_MONTH,
            'billing_period_count' => 6,
        ]);

        $anchor = CarbonImmutable::parse(
            '2026-01-31 08:00:00',
            'UTC'
        );

        $expiresAt = $this->resolver->resolve($plan, $anchor);

        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            $expiresAt->equalTo('2026-07-31 08:00:00 UTC')
        );
    }

    public function test_it_resolves_a_yearly_plan_without_overflow(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_YEAR,
            'billing_period_count' => 1,
        ]);

        $anchor = CarbonImmutable::parse(
            '2024-02-29 08:00:00',
            'UTC'
        );

        $expiresAt = $this->resolver->resolve($plan, $anchor);

        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            $expiresAt->equalTo('2025-02-28 08:00:00 UTC')
        );
    }

    public function test_it_resolves_a_multi_year_plan(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_YEAR,
            'billing_period_count' => 3,
        ]);

        $anchor = CarbonImmutable::parse(
            '2026-07-10 08:00:00',
            'UTC'
        );

        $expiresAt = $this->resolver->resolve($plan, $anchor);

        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            $expiresAt->equalTo('2029-07-10 08:00:00 UTC')
        );
    }

    public function test_it_returns_null_for_a_lifetime_plan(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_LIFETIME,
            'billing_period_count' => null,
        ]);

        $expiresAt = $this->resolver->resolve(
            $plan,
            CarbonImmutable::parse(
                '2026-07-10 08:00:00',
                'UTC'
            )
        );

        $this->assertNull($expiresAt);
    }

    public function test_it_rejects_an_unsupported_month_count(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_MONTH,
            'billing_period_count' => 2,
        ]);

        $this->expectException(
            InvalidPlanBillingPeriodException::class
        );

        $this->resolver->resolve(
            $plan,
            CarbonImmutable::parse(
                '2026-07-10 08:00:00',
                'UTC'
            )
        );
    }

    public function test_it_rejects_a_lifetime_plan_with_a_count(): void
    {
        $plan = new Plan([
            'billing_period_unit' => Plan::BILLING_PERIOD_LIFETIME,
            'billing_period_count' => 1,
        ]);

        $this->expectException(
            InvalidPlanBillingPeriodException::class
        );

        $this->resolver->resolve(
            $plan,
            CarbonImmutable::parse(
                '2026-07-10 08:00:00',
                'UTC'
            )
        );
    }

    public function test_it_rejects_an_unknown_period_unit(): void
    {
        $plan = new Plan([
            'billing_period_unit' => 'week',
            'billing_period_count' => 1,
        ]);

        $this->expectException(
            InvalidPlanBillingPeriodException::class
        );

        $this->resolver->resolve(
            $plan,
            CarbonImmutable::parse(
                '2026-07-10 08:00:00',
                'UTC'
            )
        );
    }
}
