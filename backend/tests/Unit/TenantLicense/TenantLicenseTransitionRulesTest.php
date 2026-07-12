<?php

declare(strict_types=1);

namespace Tests\Unit\TenantLicense;

use App\Exceptions\TenantLicense\InvalidTenantLicenseStateException;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Exceptions\TenantLicense\TenantLicensePastDueException;
use App\Models\Plan;
use App\Models\TenantLicense;
use App\Services\TenantLicense\Policies\TenantLicenseTransitionRules;
use Carbon\CarbonImmutable;
use Closure;
use Tests\TestCase;
use Throwable;

final class TenantLicenseTransitionRulesTest extends TestCase
{
    private TenantLicenseTransitionRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new TenantLicenseTransitionRules();
    }

    public function test_period_consistency_accepts_valid_trial_on_lifetime_plan(): void
    {
        $plan = $this->plan(
            id: 'plan-lifetime',
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->license(
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: '2026-07-24 08:00:00',
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_valid_finite_active_license(): void
    {
        $plan = $this->plan();

        $license = $this->license(
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: '2026-08-10 08:00:00',
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_valid_lifetime_active_license(): void
    {
        $plan = $this->plan(
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->license(
            status: TenantLicense::STATUS_ACTIVE,
            expiresAt: null,
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_valid_grace_period_license(): void
    {
        $plan = $this->plan();

        $license = $this->license(
            status: TenantLicense::STATUS_GRACE_PERIOD,
            expiresAt: '2026-07-10 08:00:00',
            graceEndsAt: '2026-07-17 08:00:00',
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_expired_trial_snapshot(): void
    {
        $plan = $this->plan(
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->license(
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: '2026-07-10 08:00:00',
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_expired_grace_snapshot(): void
    {
        $plan = $this->plan();

        $license = $this->license(
            status: TenantLicense::STATUS_EXPIRED,
            expiresAt: '2026-07-10 08:00:00',
            graceEndsAt: '2026-07-17 08:00:00',
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_accepts_cancelled_lifetime_snapshot(): void
    {
        $plan = $this->plan(
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->license(
            status: TenantLicense::STATUS_CANCELLED,
            expiresAt: null,
        );

        $this->rules->assertPeriodConsistency($license, $plan);

        $this->addToAssertionCount(1);
    }

    public function test_period_consistency_rejects_grace_on_lifetime_plan(): void
    {
        $plan = $this->plan(
            unit: Plan::BILLING_PERIOD_LIFETIME,
            count: null,
        );

        $license = $this->license(
            status: TenantLicense::STATUS_CANCELLED,
            expiresAt: '2026-07-10 08:00:00',
            graceEndsAt: '2026-07-17 08:00:00',
        );

        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'grace_on_lifetime_plan',
            fn () => $this->rules->assertPeriodConsistency(
                $license,
                $plan,
            ),
        );
    }

    public function test_period_consistency_rejects_trial_without_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'trial_without_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_TRIAL,
                    expiresAt: null,
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_trial_with_grace_end(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'trial_with_grace_end',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_TRIAL,
                    expiresAt: '2026-07-10 08:00:00',
                    graceEndsAt: '2026-07-17 08:00:00',
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_active_lifetime_with_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'active_lifetime_with_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                $this->plan(
                    unit: Plan::BILLING_PERIOD_LIFETIME,
                    count: null,
                ),
            ),
        );
    }

    public function test_period_consistency_rejects_active_finite_without_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'active_finite_without_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: null,
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_grace_without_end(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'grace_without_end',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_GRACE_PERIOD,
                    expiresAt: '2026-07-10 08:00:00',
                    graceEndsAt: null,
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_grace_end_not_after_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'grace_end_not_after_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_GRACE_PERIOD,
                    expiresAt: '2026-07-10 08:00:00',
                    graceEndsAt: '2026-07-10 08:00:00',
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_expired_without_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'expired_without_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_EXPIRED,
                    expiresAt: null,
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_cancelled_finite_without_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'cancelled_finite_without_expiry',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: TenantLicense::STATUS_CANCELLED,
                    expiresAt: null,
                ),
                $this->plan(),
            ),
        );
    }

    public function test_period_consistency_rejects_unknown_status(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseStateException::class,
            'unknown_status',
            fn () => $this->rules->assertPeriodConsistency(
                $this->license(
                    status: 'unknown',
                    expiresAt: '2026-07-10 08:00:00',
                ),
                $this->plan(),
            ),
        );
    }

    public function test_activation_accepts_unexpired_trial(): void
    {
        $license = $this->license(
            status: TenantLicense::STATUS_TRIAL,
            expiresAt: '2026-07-11 08:00:00',
        );

        $this->rules->assertLicenseCanBeActivated(
            $license,
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_activation_rejects_non_trial_status(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'cannot_activate_from_status',
            fn () => $this->rules->assertLicenseCanBeActivated(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_activation_rejects_trial_at_expiry_boundary(): void
    {
        $this->assertReasonCode(
            TenantLicensePastDueException::class,
            'activation_past_due',
            fn () => $this->rules->assertLicenseCanBeActivated(
                $this->license(
                    status: TenantLicense::STATUS_TRIAL,
                    expiresAt: '2026-07-10 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_renewal_accepts_active_finite_license_inside_period(): void
    {
        $this->rules->assertLicenseCanBeRenewed(
            $this->license(
                status: TenantLicense::STATUS_ACTIVE,
                expiresAt: '2026-08-10 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_renewal_rejects_lifetime_license(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'lifetime_cannot_be_renewed',
            fn () => $this->rules->assertLicenseCanBeRenewed(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: null,
                ),
                $this->plan(
                    unit: Plan::BILLING_PERIOD_LIFETIME,
                    count: null,
                ),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_renewal_rejects_active_license_at_expiry_boundary(): void
    {
        $this->assertReasonCode(
            TenantLicensePastDueException::class,
            'renewal_past_due',
            fn () => $this->rules->assertLicenseCanBeRenewed(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-07-10 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_renewal_accepts_grace_recovery_inside_window(): void
    {
        $this->rules->assertLicenseCanBeRenewed(
            $this->license(
                status: TenantLicense::STATUS_GRACE_PERIOD,
                expiresAt: '2026-07-01 08:00:00',
                graceEndsAt: '2026-07-17 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_renewal_rejects_grace_recovery_at_end_boundary(): void
    {
        $this->assertReasonCode(
            TenantLicensePastDueException::class,
            'grace_recovery_past_due',
            fn () => $this->rules->assertLicenseCanBeRenewed(
                $this->license(
                    status: TenantLicense::STATUS_GRACE_PERIOD,
                    expiresAt: '2026-07-01 08:00:00',
                    graceEndsAt: '2026-07-10 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_grace_entry_accepts_expired_active_license(): void
    {
        $this->rules->assertLicenseCanEnterGracePeriod(
            $this->license(
                status: TenantLicense::STATUS_ACTIVE,
                expiresAt: '2026-07-10 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_grace_entry_rejects_lifetime_license(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'lifetime_cannot_enter_grace_period',
            fn () => $this->rules->assertLicenseCanEnterGracePeriod(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: null,
                ),
                $this->plan(
                    unit: Plan::BILLING_PERIOD_LIFETIME,
                    count: null,
                ),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_grace_entry_rejects_license_before_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'grace_period_not_yet_eligible',
            fn () => $this->rules->assertLicenseCanEnterGracePeriod(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-07-11 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_expiration_accepts_trial_at_expiry_boundary(): void
    {
        $this->rules->assertLicenseCanExpire(
            $this->license(
                status: TenantLicense::STATUS_TRIAL,
                expiresAt: '2026-07-10 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_expiration_rejects_trial_before_expiry(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'expiration_not_yet_eligible',
            fn () => $this->rules->assertLicenseCanExpire(
                $this->license(
                    status: TenantLicense::STATUS_TRIAL,
                    expiresAt: '2026-07-11 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_expiration_accepts_grace_period_at_end_boundary(): void
    {
        $this->rules->assertLicenseCanExpire(
            $this->license(
                status: TenantLicense::STATUS_GRACE_PERIOD,
                expiresAt: '2026-07-01 08:00:00',
                graceEndsAt: '2026-07-10 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_expiration_accepts_already_expired_license(): void
    {
        $this->rules->assertLicenseCanExpire(
            $this->license(
                status: TenantLicense::STATUS_EXPIRED,
                expiresAt: '2026-07-01 08:00:00',
            ),
            $this->plan(),
            $this->time('2026-07-10 08:00:00'),
        );

        $this->addToAssertionCount(1);
    }

    public function test_expiration_rejects_active_license(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'active_must_enter_grace_before_expiration',
            fn () => $this->rules->assertLicenseCanExpire(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-07-01 08:00:00',
                ),
                $this->plan(),
                $this->time('2026-07-10 08:00:00'),
            ),
        );
    }

    public function test_cancellation_accepts_current_statuses(): void
    {
        foreach (TenantLicense::CURRENT_STATUSES as $status) {
            $license = match ($status) {
                TenantLicense::STATUS_TRIAL => $this->license(
                    status: $status,
                    expiresAt: '2026-07-24 08:00:00',
                ),
                TenantLicense::STATUS_ACTIVE => $this->license(
                    status: $status,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                TenantLicense::STATUS_GRACE_PERIOD => $this->license(
                    status: $status,
                    expiresAt: '2026-07-01 08:00:00',
                    graceEndsAt: '2026-07-17 08:00:00',
                ),
            };

            $this->rules->assertLicenseCanBeCancelled(
                $license,
                $this->plan(),
            );
        }

        $this->addToAssertionCount(3);
    }

    public function test_cancellation_rejects_terminal_status(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'cannot_cancel_from_status',
            fn () => $this->rules->assertLicenseCanBeCancelled(
                $this->license(
                    status: TenantLicense::STATUS_CANCELLED,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                $this->plan(),
            ),
        );
    }

    public function test_plan_change_accepts_finite_to_lifetime(): void
    {
        $this->rules->assertLicensePlanCanBeChanged(
            $this->license(
                status: TenantLicense::STATUS_ACTIVE,
                expiresAt: '2026-08-10 08:00:00',
            ),
            $this->plan(id: 'plan-current'),
            $this->plan(
                id: 'plan-lifetime',
                unit: Plan::BILLING_PERIOD_LIFETIME,
                count: null,
            ),
        );

        $this->addToAssertionCount(1);
    }

    public function test_plan_change_rejects_same_plan(): void
    {
        $currentPlan = $this->plan(id: 'plan-same');

        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'plan_already_assigned',
            fn () => $this->rules->assertLicensePlanCanBeChanged(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                $currentPlan,
                $currentPlan,
            ),
        );
    }

    public function test_plan_change_rejects_lifetime_to_finite_before_inactive_check(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'lifetime_to_finite_plan_change_not_allowed',
            fn () => $this->rules->assertLicensePlanCanBeChanged(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: null,
                ),
                $this->plan(
                    id: 'plan-lifetime',
                    unit: Plan::BILLING_PERIOD_LIFETIME,
                    count: null,
                ),
                $this->plan(
                    id: 'plan-finite-inactive',
                    active: false,
                ),
            ),
        );
    }

    public function test_plan_change_rejects_inactive_target_plan(): void
    {
        $this->assertReasonCode(
            InvalidTenantLicenseTransitionException::class,
            'target_plan_inactive',
            fn () => $this->rules->assertLicensePlanCanBeChanged(
                $this->license(
                    status: TenantLicense::STATUS_ACTIVE,
                    expiresAt: '2026-08-10 08:00:00',
                ),
                $this->plan(id: 'plan-current'),
                $this->plan(
                    id: 'plan-inactive',
                    active: false,
                ),
            ),
        );
    }

    private function plan(
        string $id = 'plan-finite',
        string $unit = Plan::BILLING_PERIOD_MONTH,
        ?int $count = 1,
        bool $active = true,
    ): Plan {
        $plan = new Plan();

        $plan->forceFill([
            'id' => $id,
            'billing_period_unit' => $unit,
            'billing_period_count' => $count,
            'is_active' => $active,
        ]);

        return $plan;
    }

    private function license(
        string $status,
        ?string $expiresAt,
        ?string $graceEndsAt = null,
    ): TenantLicense {
        $license = new TenantLicense();

        $license->forceFill([
            'id' => 'license-01',
            'tenant_id' => 'tenant-01',
            'plan_id' => 'plan-01',
            'status' => $status,
            'starts_at' => $this->time('2026-07-01 08:00:00'),
            'expires_at' => $expiresAt !== null
                ? $this->time($expiresAt)
                : null,
            'grace_ends_at' => $graceEndsAt !== null
                ? $this->time($graceEndsAt)
                : null,
        ]);

        return $license;
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * @param class-string<Throwable> $exceptionClass
     */
    private function assertReasonCode(
        string $exceptionClass,
        string $reasonCode,
        Closure $callback,
    ): void {
        try {
            $callback();

            $this->fail(sprintf(
                'Expected exception [%s] was not thrown.',
                $exceptionClass,
            ));
        } catch (Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($reasonCode, $exception->reasonCode);
        }
    }
}
