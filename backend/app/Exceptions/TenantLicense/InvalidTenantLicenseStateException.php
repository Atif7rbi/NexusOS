<?php

declare(strict_types=1);

namespace App\Exceptions\TenantLicense;

use App\Models\Plan;
use App\Models\TenantLicense;
use DomainException;

final class InvalidTenantLicenseStateException extends DomainException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function graceOnLifetimePlan(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'grace_on_lifetime_plan',
            message: sprintf(
                'Tenant license [%s] cannot contain grace_ends_at while plan [%s] is lifetime.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function trialWithoutExpiry(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'trial_without_expiry',
            message: sprintf(
                'Trial tenant license [%s] must have expires_at.',
                $license->id,
            ),
        );
    }

    public static function trialWithGraceEnd(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'trial_with_grace_end',
            message: sprintf(
                'Trial tenant license [%s] must not have grace_ends_at.',
                $license->id,
            ),
        );
    }

    public static function activeLifetimeWithExpiry(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'active_lifetime_with_expiry',
            message: sprintf(
                'Active tenant license [%s] on lifetime plan [%s] must not have expires_at.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function activeFiniteWithoutExpiry(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'active_finite_without_expiry',
            message: sprintf(
                'Active tenant license [%s] on finite plan [%s] must have expires_at.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function activeWithGraceEnd(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'active_with_grace_end',
            message: sprintf(
                'Active tenant license [%s] must not have grace_ends_at.',
                $license->id,
            ),
        );
    }

    public static function graceWithoutExpiry(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'grace_without_expiry',
            message: sprintf(
                'Grace-period tenant license [%s] must have expires_at.',
                $license->id,
            ),
        );
    }

    public static function graceWithoutEnd(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'grace_without_end',
            message: sprintf(
                'Grace-period tenant license [%s] must have grace_ends_at.',
                $license->id,
            ),
        );
    }

    public static function graceEndNotAfterExpiry(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'grace_end_not_after_expiry',
            message: sprintf(
                'Tenant license [%s] must have grace_ends_at later than expires_at.',
                $license->id,
            ),
        );
    }

    public static function expiredWithoutExpiry(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'expired_without_expiry',
            message: sprintf(
                'Expired tenant license [%s] must preserve expires_at.',
                $license->id,
            ),
        );
    }

    public static function cancelledFiniteWithoutExpiry(
        TenantLicense $license,
        Plan $plan,
    ): self {
        return new self(
            reasonCode: 'cancelled_finite_without_expiry',
            message: sprintf(
                'Cancelled tenant license [%s] on finite plan [%s] must preserve expires_at.',
                $license->id,
                $plan->id,
            ),
        );
    }

    public static function cancelledWithGraceButWithoutExpiry(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'cancelled_with_grace_but_without_expiry',
            message: sprintf(
                'Cancelled tenant license [%s] cannot have grace_ends_at without expires_at.',
                $license->id,
            ),
        );
    }

    public static function unknownStatus(
        TenantLicense $license,
    ): self {
        return new self(
            reasonCode: 'unknown_status',
            message: sprintf(
                'Tenant license [%s] has unsupported status [%s].',
                $license->id,
                $license->status,
            ),
        );
    }
}
