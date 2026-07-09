<?php

declare(strict_types=1);

namespace App\Exceptions\TenantModule;

use RuntimeException;

class NoCurrentTenantLicenseException extends RuntimeException
{
}
