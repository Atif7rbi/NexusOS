<?php

declare(strict_types=1);

namespace App\Exceptions\TenantLicense;

use DomainException;

final class InvalidCancellationReasonException extends DomainException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function emptyReason(): self
    {
        return new self(
            reasonCode: 'empty_cancellation_reason',
            message: 'Cancellation reason must not be empty.',
        );
    }

    public static function reasonTooLong(
        int $maximumLength,
    ): self {
        return new self(
            reasonCode: 'cancellation_reason_too_long',
            message: sprintf(
                'Cancellation reason must not exceed %d characters.',
                $maximumLength,
            ),
        );
    }
}
