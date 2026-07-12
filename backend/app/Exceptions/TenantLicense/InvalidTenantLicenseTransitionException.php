<?php

declare(strict_types=1);

namespace App\Exceptions\TenantLicense;

use App\Models\Plan;
use App\Models\TenantLicense;
use Carbon\CarbonImmutable;
use DomainException;

final class InvalidTenantLicenseTransitionException extends DomainException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function cannotActivateFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_activate_from_status',
            message: sprintf(
                'Tenant license [%s] cannot be activated from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function cannotRenewFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_renew_from_status',
            message: sprintf(
                'Tenant license [%s] cannot be renewed from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function lifetimeCannotBeRenewed(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'lifetime_cannot_be_renewed',
            message: sprintf(
                'Tenant license [%s] on lifetime plan [%s] cannot be renewed.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function cannotEnterGracePeriodFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_enter_grace_period_from_status',
            message: sprintf(
                'Tenant license [%s] cannot enter grace period from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function lifetimeCannotEnterGracePeriod(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'lifetime_cannot_enter_grace_period',
            message: sprintf(
                'Tenant license [%s] on lifetime plan [%s] cannot enter grace period.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function gracePeriodNotYetEligible(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'grace_period_not_yet_eligible',
            message: sprintf(
                'Tenant license [%s] is not eligible for grace period at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }

    public static function expirationNotYetEligible(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'expiration_not_yet_eligible',
            message: sprintf(
                'Trial tenant license [%s] is not eligible for expiration at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }

    public static function graceExpirationNotYetEligible(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'grace_expiration_not_yet_eligible',
            message: sprintf(
                'Grace-period tenant license [%s] is not eligible for expiration at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }

    public static function activeMustEnterGracePeriodBeforeExpiration(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'active_must_enter_grace_before_expiration',
            message: sprintf(
                'Active tenant license [%s] must enter grace period before expiration.',
                $license->id,
            ),
        );
    }

    public static function cannotExpireFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_expire_from_status',
            message: sprintf(
                'Tenant license [%s] cannot expire from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function cannotCancelFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_cancel_from_status',
            message: sprintf(
                'Tenant license [%s] cannot be cancelled from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function cannotChangePlanFromStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cannot_change_plan_from_status',
            message: sprintf(
                'Tenant license [%s] cannot change plan from status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }

    public static function planAlreadyAssigned(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'plan_already_assigned',
            message: sprintf(
                'Tenant license [%s] already uses plan [%s].',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function lifetimeToFinitePlanChangeNotAllowed(
        TenantLicense $license,
        Plan $currentPlan,
        Plan $newPlan,
    ): self {
        return new self(
            reasonCode: 'lifetime_to_finite_plan_change_not_allowed',
            message: sprintf(
                'Tenant license [%s] cannot change from lifetime plan [%s] to finite plan [%s].',
                $license->id,
                $currentPlan->id,
                $newPlan->id,
            ),
        );
    }

    public static function targetPlanInactive(
        Plan $newPlan,
    ): self {
        return new self(
            reasonCode: 'target_plan_inactive',
            message: sprintf(
                'Target plan [%s] is inactive and cannot be assigned.',
                $newPlan->id,
            ),
        );
    }
}
