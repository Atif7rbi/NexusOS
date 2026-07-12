<?php

declare(strict_types=1);

namespace App\Exceptions\TenantLicense;

use App\Models\TenantLicense;
use Carbon\CarbonImmutable;
use DomainException;

final class TenantLicensePastDueException extends DomainException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function activationPastDue(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'activation_past_due',
            message: sprintf(
                'Trial tenant license [%s] expired before activation at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }

    public static function renewalPastDue(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'renewal_past_due',
            message: sprintf(
                'Active tenant license [%s] is past due for early renewal at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }

    public static function graceRecoveryPastDue(
        TenantLicense $license,
        CarbonImmutable $occurredAt,
    ): self {
        return new self(
            reasonCode: 'grace_recovery_past_due',
            message: sprintf(
                'Grace period for tenant license [%s] ended before recovery at [%s].',
                $license->id,
                $occurredAt->toISOString(),
            ),
        );
    }
}
