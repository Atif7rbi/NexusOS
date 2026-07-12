<?php

declare(strict_types=1);

namespace App\Services\TenantLicense\Policies;

use App\Exceptions\TenantLicense\InvalidTenantLicenseStateException;
use App\Exceptions\TenantLicense\InvalidTenantLicenseTransitionException;
use App\Exceptions\TenantLicense\TenantLicensePastDueException;
use App\Models\Plan;
use App\Models\TenantLicense;
use Carbon\CarbonImmutable;

/**
 * Pure TenantLicense lifecycle policy.
 *
 * This class performs no queries, locks, transactions, mutations,
 * synchronization, or audit logging.
 *
 * Every service mutating an existing TenantLicense must call
 * assertPeriodConsistency() after loading the locked license and Plan,
 * and before any use-case-specific assertion or mutation.
 */
final class TenantLicenseTransitionRules
{
    public function assertPeriodConsistency(
        TenantLicense $license,
        Plan $plan,
    ): void {
        if (
            $license->grace_ends_at !== null
            && $plan->isLifetime()
        ) {
            throw InvalidTenantLicenseStateException::graceOnLifetimePlan(
                $license,
                $plan,
            );
        }

        match ($license->status) {
            TenantLicense::STATUS_TRIAL => $this->assertTrialConsistency(
                $license
            ),
            TenantLicense::STATUS_ACTIVE => $this->assertActiveConsistency(
                $license,
                $plan,
            ),
            TenantLicense::STATUS_GRACE_PERIOD => $this->assertGraceConsistency(
                $license
            ),
            TenantLicense::STATUS_EXPIRED => $this->assertExpiredConsistency(
                $license
            ),
            TenantLicense::STATUS_CANCELLED => $this->assertCancelledConsistency(
                $license,
                $plan,
            ),
            default => throw InvalidTenantLicenseStateException::unknownStatus(
                $license
            ),
        };
    }

    public function assertLicenseCanBeActivated(
        TenantLicense $license,
        Plan $plan,
        CarbonImmutable $occurredAt,
    ): void {
        if (! $license->isTrial()) {
            throw InvalidTenantLicenseTransitionException::
                cannotActivateFromStatus($license);
        }

        if ($license->expires_at->lessThanOrEqualTo($occurredAt)) {
            throw TenantLicensePastDueException::activationPastDue(
                $license,
                $occurredAt,
            );
        }
    }

    public function assertLicenseCanBeRenewed(
        TenantLicense $license,
        Plan $plan,
        CarbonImmutable $occurredAt,
    ): void {
        if (
            ! $license->isActive()
            && ! $license->isInGracePeriod()
        ) {
            throw InvalidTenantLicenseTransitionException::
                cannotRenewFromStatus($license);
        }

        if ($license->isActive()) {
            if ($plan->isLifetime()) {
                throw InvalidTenantLicenseTransitionException::
                    lifetimeCannotBeRenewed($license, $plan);
            }

            if ($license->expires_at->lessThanOrEqualTo($occurredAt)) {
                throw TenantLicensePastDueException::renewalPastDue(
                    $license,
                    $occurredAt,
                );
            }

            return;
        }

        if ($license->grace_ends_at->lessThanOrEqualTo($occurredAt)) {
            throw TenantLicensePastDueException::graceRecoveryPastDue(
                $license,
                $occurredAt,
            );
        }
    }

    public function assertLicenseCanEnterGracePeriod(
        TenantLicense $license,
        Plan $plan,
        CarbonImmutable $occurredAt,
    ): void {
        if (! $license->isActive()) {
            throw InvalidTenantLicenseTransitionException::
                cannotEnterGracePeriodFromStatus($license);
        }

        if ($plan->isLifetime()) {
            throw InvalidTenantLicenseTransitionException::
                lifetimeCannotEnterGracePeriod($license, $plan);
        }

        if ($license->expires_at->greaterThan($occurredAt)) {
            throw InvalidTenantLicenseTransitionException::
                gracePeriodNotYetEligible($license, $occurredAt);
        }
    }

    /**
     * A successful return may mean either:
     * - the license may transition to expired, or
     * - the license is already expired and this is an idempotent no-op.
     *
     * The caller must inspect the locked pre-mutation status to determine
     * whether mutation and audit logging are required.
     */
    public function assertLicenseCanExpire(
        TenantLicense $license,
        Plan $plan,
        CarbonImmutable $occurredAt,
    ): void {
        if ($license->isExpired()) {
            return;
        }

        if ($license->isTrial()) {
            if ($license->expires_at->greaterThan($occurredAt)) {
                throw InvalidTenantLicenseTransitionException::
                    expirationNotYetEligible($license, $occurredAt);
            }

            return;
        }

        if ($license->isInGracePeriod()) {
            if ($license->grace_ends_at->greaterThan($occurredAt)) {
                throw InvalidTenantLicenseTransitionException::
                    graceExpirationNotYetEligible(
                        $license,
                        $occurredAt,
                    );
            }

            return;
        }

        if ($license->isActive()) {
            throw InvalidTenantLicenseTransitionException::
                activeMustEnterGracePeriodBeforeExpiration($license);
        }

        throw InvalidTenantLicenseTransitionException::
            cannotExpireFromStatus($license);
    }

    public function assertLicenseCanBeCancelled(
        TenantLicense $license,
        Plan $plan,
    ): void {
        if (
            $license->isTrial()
            || $license->isActive()
            || $license->isInGracePeriod()
        ) {
            return;
        }

        throw InvalidTenantLicenseTransitionException::
            cannotCancelFromStatus($license);
    }

    public function assertLicensePlanCanBeChanged(
        TenantLicense $license,
        Plan $currentPlan,
        Plan $newPlan,
    ): void {
        if (! $license->isActive()) {
            throw InvalidTenantLicenseTransitionException::
                cannotChangePlanFromStatus($license);
        }

        if ($currentPlan->id === $newPlan->id) {
            throw InvalidTenantLicenseTransitionException::
                planAlreadyAssigned($license, $newPlan);
        }

        if (
            $currentPlan->isLifetime()
            && ! $newPlan->isLifetime()
        ) {
            throw InvalidTenantLicenseTransitionException::
                lifetimeToFinitePlanChangeNotAllowed(
                    $license,
                    $currentPlan,
                    $newPlan,
                );
        }

        if (! $newPlan->is_active) {
            throw InvalidTenantLicenseTransitionException::
                targetPlanInactive($newPlan);
        }
    }

    private function assertTrialConsistency(
        TenantLicense $license,
    ): void {
        if ($license->expires_at === null) {
            throw InvalidTenantLicenseStateException::trialWithoutExpiry(
                $license
            );
        }

        if ($license->grace_ends_at !== null) {
            throw InvalidTenantLicenseStateException::trialWithGraceEnd(
                $license
            );
        }
    }

    private function assertActiveConsistency(
        TenantLicense $license,
        Plan $plan,
    ): void {
        if ($license->grace_ends_at !== null) {
            throw InvalidTenantLicenseStateException::activeWithGraceEnd(
                $license
            );
        }

        if (
            $plan->isLifetime()
            && $license->expires_at !== null
        ) {
            throw InvalidTenantLicenseStateException::
                activeLifetimeWithExpiry($license, $plan);
        }

        if (
            ! $plan->isLifetime()
            && $license->expires_at === null
        ) {
            throw InvalidTenantLicenseStateException::
                activeFiniteWithoutExpiry($license, $plan);
        }
    }

    private function assertGraceConsistency(
        TenantLicense $license,
    ): void {
        if ($license->expires_at === null) {
            throw InvalidTenantLicenseStateException::graceWithoutExpiry(
                $license
            );
        }

        if ($license->grace_ends_at === null) {
            throw InvalidTenantLicenseStateException::graceWithoutEnd(
                $license
            );
        }

        $this->assertGraceEndAfterExpiry($license);
    }

    private function assertExpiredConsistency(
        TenantLicense $license,
    ): void {
        if ($license->expires_at === null) {
            throw InvalidTenantLicenseStateException::expiredWithoutExpiry(
                $license
            );
        }

        if ($license->grace_ends_at !== null) {
            $this->assertGraceEndAfterExpiry($license);
        }
    }

    private function assertCancelledConsistency(
        TenantLicense $license,
        Plan $plan,
    ): void {
        if (
            $license->expires_at === null
            && $license->grace_ends_at !== null
        ) {
            throw InvalidTenantLicenseStateException::
                cancelledWithGraceButWithoutExpiry($license);
        }

        if (
            $license->expires_at === null
            && ! $plan->isLifetime()
        ) {
            throw InvalidTenantLicenseStateException::
                cancelledFiniteWithoutExpiry($license, $plan);
        }

        if ($license->grace_ends_at !== null) {
            $this->assertGraceEndAfterExpiry($license);
        }
    }

    private function assertGraceEndAfterExpiry(
        TenantLicense $license,
    ): void {
        if (
            $license->grace_ends_at->lessThanOrEqualTo(
                $license->expires_at
            )
        ) {
            throw InvalidTenantLicenseStateException::
                graceEndNotAfterExpiry($license);
        }
    }
}
